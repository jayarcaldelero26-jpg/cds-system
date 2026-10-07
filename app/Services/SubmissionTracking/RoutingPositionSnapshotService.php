<?php

namespace App\Services\SubmissionTracking;

use App\Models\RoutingPositionSetting;
use App\Models\RoutingPositionSettingVersion;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Resolves an immutable route version, or a clearly marked preview for a new route. */
final class RoutingPositionSnapshotService
{
    public const GRAPH_VERSION = 'position-graph-v1';
    private const SOURCES = [
        'conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws',
        'ipaf-management', 'revenue', 'management-plans',
    ];
    private const SOURCE_TABLES = [
        'conservation' => 'conservation_report_submissions', 'engp' => 'engp_report_submissions',
        'bms' => 'bms_report_submissions', 'bams' => 'bams_report_submissions',
        'imea' => 'imea_report_submissions', 'imea-maintenance' => 'imea_facility_maintenance_reports',
        'aws' => 'aws', 'ipaf-management' => 'ipaf_management_reports',
        'revenue' => 'ipaf_revenue_collections', 'management-plans' => 'management_plans',
    ];

    /** @var array<string,array<string,mixed>> */
    private array $primedPositions = [];

    /** Prime list projection with bounded reads. Transition-time calls bypass this cache. */
    public function prime(iterable $items): void
    {
        $this->primedPositions = [];
        $groups = [];
        foreach ($items as $item) {
            $record = $item['record'] ?? null;
            $source = (string) ($item['source'] ?? '');
            if (! $record instanceof Model || ! in_array($source, self::SOURCES, true)) continue;
            $id = (string) $record->getKey();
            $groups[$source][$id] = ['record' => $record, 'has_history' => (bool) ($item['has_history'] ?? false)];
        }
        if ($groups === []) return;

        $tables = ['routing_position_setting_versions', 'routing_position_settings', 'routing_position_cutover_watermarks', 'submission_routing_snapshots'];
        $present = collect($tables)->filter(fn (string $table): bool => Schema::hasTable($table))->count();
        if ($present === 0) {
            foreach ($groups as $source => $records) foreach ($records as $id => $_) $this->primedPositions[$source.':'.$id] = $this->baseline('schema_unavailable');
            return;
        }
        if ($present !== count($tables)) throw new RuntimeException('Routing position schema is incomplete; writes are disabled until repaired.');

        $snapshots = collect();
        foreach ($groups as $source => $records) {
            foreach (array_chunk(array_keys($records), 500) as $ids) {
                $snapshots = $snapshots->concat(SubmissionRoutingSnapshot::query()
                    ->where('source_key', $source)->whereIn('source_id', $ids)->get());
            }
        }
        $snapshotByKey = $snapshots->keyBy(fn (SubmissionRoutingSnapshot $snapshot): string => $snapshot->source_key.':'.$snapshot->source_id);
        $versionIds = $snapshots->pluck('setting_version_id')->map(fn ($id): int => (int) $id)->unique();
        $versionOneId = RoutingPositionSettingVersion::query()->where('version', 1)->value('id');
        if ($versionOneId !== null) $versionIds->push((int) $versionOneId);
        $head = RoutingPositionSetting::query()->whereKey(1)->first();
        if ($head) $versionIds->push((int) $head->setting_version_id);
        $versions = $versionIds->isEmpty()
            ? collect()
            : RoutingPositionSettingVersion::query()->whereIn('id', $versionIds->unique()->all())->get()->keyBy('id');
        $watermarks = DB::table('routing_position_cutover_watermarks')->whereIn('source_key', array_keys($groups))->get()->keyBy('source_key');
        $validWatermarks = [];
        foreach (array_keys($groups) as $source) {
            $watermark = $watermarks->get($source);
            $validWatermarks[$source] = $watermark
                && ($watermark->table_name ?? null) === self::SOURCE_TABLES[$source]
                && Schema::hasTable(self::SOURCE_TABLES[$source])
                && (int) $watermark->max_id >= 0;
        }

        foreach ($groups as $source => $records) {
            foreach ($records as $id => $item) {
                $key = $source.':'.$id;
                $snapshot = $snapshotByKey->get($key);
                if ($snapshot) {
                    $version = $versions->get((int) $snapshot->setting_version_id);
                    if (! $version || $snapshot->graph_version !== self::GRAPH_VERSION || ! in_array($snapshot->profile, ['regular', 'direct'], true)) {
                        throw new RuntimeException('The captured route snapshot is invalid; routing writes are disabled.');
                    }
                    $this->primedPositions[$key] = $this->fromVersion($version, $snapshot->profile, false, false, $snapshot);
                    continue;
                }

                $watermark = $watermarks->get($source);
                if ($item['has_history']) {
                    $version = $versionOneId === null ? null : $versions->get((int) $versionOneId);
                    if (! $version) throw new RuntimeException('The all-enabled baseline setting version is missing.');
                    $this->primedPositions[$key] = $this->fromVersion($version, 'legacy', false, false, null);
                    continue;
                }

                if (! ($validWatermarks[$source] ?? false)) throw new RuntimeException('The route cutover watermark is missing or invalid.');

                if ((int) $id <= (int) $watermark->max_id) {
                    $version = $versionOneId === null ? null : $versions->get((int) $versionOneId);
                    if (! $version) throw new RuntimeException('The all-enabled baseline setting version is missing.');
                    $this->primedPositions[$key] = $this->fromVersion($version, 'legacy', false, false, null);
                    continue;
                }

                $version = $head ? $versions->get((int) $head->setting_version_id) : null;
                if (! $version) throw new RuntimeException('The current routing position setting head is invalid.');
                $this->primedPositions[$key] = $this->fromVersion($version, 'preview', true, true, null);
            }
        }
    }

    public function clearPrimed(): void
    {
        $this->primedPositions = [];
    }

    /** @return array<string,mixed> */
    public function resolve(Model $record, string $source, bool $hasHistory, bool $lockHead = false, bool $lockSnapshot = false): array
    {
        if (! in_array($source, self::SOURCES, true)) throw new RuntimeException('The routing source is not registered for position snapshots.');
        $cacheKey = $source.':'.$record->getKey();
        if (! $lockHead && array_key_exists($cacheKey, $this->primedPositions)) return $this->primedPositions[$cacheKey];
        $tables = ['routing_position_setting_versions', 'routing_position_settings', 'routing_position_cutover_watermarks', 'submission_routing_snapshots'];
        $present = collect($tables)->filter(fn (string $table): bool => Schema::hasTable($table))->count();
        if ($present === 0) return $this->baseline('schema_unavailable');
        if ($present !== count($tables)) throw new RuntimeException('Routing position schema is incomplete; writes are disabled until repaired.');

        $snapshotQuery = SubmissionRoutingSnapshot::query()->where('source_key', $source)->where('source_id', $record->getKey());
        if ($lockSnapshot) $snapshotQuery->lockForUpdate();
        $snapshot = $snapshotQuery->first();
        if ($snapshot) {
            $version = RoutingPositionSettingVersion::query()->find($snapshot->setting_version_id);
            if (! $version || $snapshot->graph_version !== self::GRAPH_VERSION || ! in_array($snapshot->profile, ['regular', 'direct'], true)) {
                throw new RuntimeException('The captured route snapshot is invalid; routing writes are disabled.');
            }
            return $this->fromVersion($version, $snapshot->profile, false, false, $snapshot);
        }

        $versionOne = RoutingPositionSettingVersion::query()->where('version', 1)->first();
        if ($hasHistory) {
            if (! $versionOne) throw new RuntimeException('The all-enabled baseline setting version is missing.');
            return $this->fromVersion($versionOne, 'legacy', false, false, null);
        }

        $watermark = DB::table('routing_position_cutover_watermarks')->where('source_key', $source)->first();
        if (! $watermark || ($watermark->table_name ?? null) !== self::SOURCE_TABLES[$source]
            || ! Schema::hasTable((string) $watermark->table_name) || (int) $watermark->max_id < 0) {
            throw new RuntimeException('The route cutover watermark is missing or invalid.');
        }
        if ((int) $record->getKey() <= (int) $watermark->max_id) {
            if (! $versionOne) throw new RuntimeException('The all-enabled baseline setting version is missing.');
            return $this->fromVersion($versionOne, 'legacy', false, false, null);
        }

        $headQuery = RoutingPositionSetting::query()->whereKey(1);
        if ($lockHead) $headQuery->lockForUpdate();
        $head = $headQuery->first();
        $versionQuery = $head ? RoutingPositionSettingVersion::query()->whereKey($head->setting_version_id) : null;
        if ($versionQuery && $lockHead) $versionQuery->lockForUpdate();
        $version = $versionQuery?->first();
        if (! $version) throw new RuntimeException('The current routing position setting head is invalid.');
        return $this->fromVersion($version, 'preview', true, true, null);
    }

    /**
     * Return the route's adopted origin. Only an immutable route snapshot
     * overrides current protected-area policy; legacy rows and previews retain
     * their existing live-policy behavior.
     *
     * @param array<string,mixed>|null $resolvedPosition Reuse a position already resolved for this request.
     */
    public function effectiveProfile(Model $record, string $source, bool $hasHistory, ?array $resolvedPosition = null): string
    {
        $position = $resolvedPosition ?? $this->resolve($record, $source, $hasHistory);
        $profile = $position['profile'] ?? null;
        if (in_array($profile, ['regular', 'direct'], true)) return $profile;

        return $source !== 'engp' && app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record)
            ? 'direct'
            : 'regular';
    }

    /** Apply the captured profile, falling back to current PA identity only for uncaptured rows. */
    public function scopeEffectiveProfileQuery(Builder $query, string $source, string $table, string $profile): Builder
    {
        if (! in_array($profile, ['regular', 'direct'], true)) {
            throw new RuntimeException('The route profile query requires a regular or direct profile.');
        }

        $scopeCurrentProfile = static function (Builder $candidate) use ($profile): Builder {
            $policy = app(ProtectedAreaRoutingPolicy::class);

            return $profile === 'direct'
                ? $policy->scopeDirectPenroQuery($candidate)
                : $policy->scopeNotDirectPenroQuery($candidate);
        };

        if (! Schema::hasTable('submission_routing_snapshots')) {
            return $scopeCurrentProfile($query);
        }

        return $query->where(function (Builder $effectiveProfile) use ($source, $table, $profile, $scopeCurrentProfile): void {
            $effectiveProfile->whereExists(function ($snapshot) use ($source, $table, $profile): void {
                $snapshot->selectRaw('1')
                    ->from('submission_routing_snapshots as captured_route')
                    ->where('captured_route.source_key', $source)
                    ->where('captured_route.profile', $profile)
                    ->whereColumn('captured_route.source_id', $table.'.id');
            })->orWhere(function (Builder $uncaptured) use ($source, $table, $scopeCurrentProfile): void {
                $uncaptured->whereNotExists(function ($snapshot) use ($source, $table): void {
                    $snapshot->selectRaw('1')
                        ->from('submission_routing_snapshots as captured_route')
                        ->where('captured_route.source_key', $source)
                        ->whereColumn('captured_route.source_id', $table.'.id');
                });
                $scopeCurrentProfile($uncaptured);
            });
        });
    }

    /** Persist the resolved preview alongside the first accepted event. Caller holds the source row and settings-head locks. */
    public function capture(Model $record, string $source, array $resolved, string $profile, User $actor): ?SubmissionRoutingSnapshot
    {
        if (! ($resolved['needs_capture'] ?? false)) return null;
        if (! in_array($profile, ['regular', 'direct'], true) || ! ($resolved['setting_version_id'] ?? null)) {
            throw new RuntimeException('The new route cannot be captured from an invalid preview.');
        }
        return SubmissionRoutingSnapshot::query()->create([
            'source_key' => $source,
            'source_id' => $record->getKey(),
            'setting_version_id' => $resolved['setting_version_id'],
            'profile' => $profile,
            'graph_version' => self::GRAPH_VERSION,
            'captured_by' => $actor->getKey(),
            'captured_at' => CarbonImmutable::now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function baseline(string $origin): array
    {
        return ['setting_version_id' => null, 'version' => 1, 'office_penro_enabled' => true, 'penro_tsd_chief_enabled' => true, 'graph_version' => self::GRAPH_VERSION, 'preview' => false, 'needs_capture' => false, 'origin' => $origin, 'profile' => 'legacy'];
    }

    /** @return array<string,mixed> */
    private function fromVersion(RoutingPositionSettingVersion $version, string $profile, bool $preview, bool $needsCapture, ?SubmissionRoutingSnapshot $snapshot): array
    {
        $this->assertVersionIntegrity($version);
        return [
            'setting_version_id' => (int) $version->getKey(),
            'version' => (int) $version->version,
            'office_penro_enabled' => (bool) $version->office_penro_enabled,
            'penro_tsd_chief_enabled' => (bool) $version->penro_tsd_chief_enabled,
            'graph_version' => self::GRAPH_VERSION,
            'preview' => $preview,
            'needs_capture' => $needsCapture,
            'origin' => $snapshot ? 'snapshot' : ($preview ? 'current_preview' : 'all_enabled_baseline'),
            'profile' => $profile,
            'snapshot_id' => $snapshot?->getKey(),
            'captured_at' => $snapshot?->captured_at?->toIso8601String(),
        ];
    }

    private function assertVersionIntegrity(RoutingPositionSettingVersion $version): void
    {
        $office = $version->getRawOriginal('office_penro_enabled');
        $tsd = $version->getRawOriginal('penro_tsd_chief_enabled');
        if ((int) $version->version < 1
            || ! in_array($office, [0, 1, '0', '1', false, true], true)
            || ! in_array($tsd, [0, 1, '0', '1', false, true], true)
            || ((int) $version->version === 1 && (! in_array($office, [1, '1', true], true) || ! in_array($tsd, [1, '1', true], true)))) {
            throw new RuntimeException('A routing setting version is malformed; routing writes are disabled.');
        }
    }
}
