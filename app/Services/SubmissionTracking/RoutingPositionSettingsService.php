<?php

namespace App\Services\SubmissionTracking;

use App\Models\RoutingPositionSetting;
use App\Models\RoutingPositionSettingVersion;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class RoutingPositionSettingsService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @return array<string,mixed> */
    public function current(): array
    {
        $tables = ['routing_position_settings', 'routing_position_setting_versions', 'routing_position_cutover_watermarks', 'submission_routing_snapshots'];
        $existing = collect($tables)->filter(fn (string $table): bool => Schema::hasTable($table))->count();
        if ($existing === 0) {
            return ['available' => false, 'version' => 1, 'office_penro_enabled' => true, 'penro_tsd_chief_enabled' => true, 'saved_at' => null, 'saved_by' => null, 'reason' => null];
        }
        if ($existing !== count($tables)) throw new \RuntimeException('Routing position schema is incomplete.');
        $head = RoutingPositionSetting::query()->with('settingVersion.savedBy')->find(1);
        $version = $head?->settingVersion;
        if (! $version) throw new RuntimeException('The current routing settings head is invalid.');
        $officeRaw = $version->getRawOriginal('office_penro_enabled');
        $tsdRaw = $version->getRawOriginal('penro_tsd_chief_enabled');
        if ((int) $version->version < 1
            || ! in_array($officeRaw, [0, 1, '0', '1', false, true], true)
            || ! in_array($tsdRaw, [0, 1, '0', '1', false, true], true)
            || ((int) $version->version === 1 && (! in_array($officeRaw, [1, '1', true], true) || ! in_array($tsdRaw, [1, '1', true], true)))) {
            throw new \RuntimeException('The current routing settings version is malformed.');
        }

        return [
            'available' => true,
            'version' => (int) $version->version,
            'office_penro_enabled' => (bool) $version->office_penro_enabled,
            'penro_tsd_chief_enabled' => (bool) $version->penro_tsd_chief_enabled,
            'saved_at' => $version->saved_at?->toIso8601String(),
            'saved_by' => $version->savedBy?->name,
            'reason' => $version->reason,
        ];
    }

    /** Categories tied to routing positions that are disabled for new accounts. */
    public function disabledAccountCategories(): array
    {
        $settings = $this->current();
        $disabled = [];

        if (! $settings['office_penro_enabled']) $disabled[] = OrganizationalAccessService::OFFICE_PENRO;
        if (! $settings['penro_tsd_chief_enabled']) $disabled[] = OrganizationalAccessService::PENRO_TSD_CHIEF;

        return $disabled;
    }

    /** Existing users may retain their own disabled-position category while editing their account. */
    public function categoryAvailableForAccount(?string $category, ?string $preservedCategory = null): bool
    {
        $organization = app(OrganizationalAccessService::class);
        $category = $organization->normalizeCategory($category);
        $preservedCategory = $organization->normalizeCategory($preservedCategory);

        if (! $category) return true;
        if ($category === OrganizationalAccessService::OFFICE_PENRO) {
            return (bool) $this->current()['office_penro_enabled'] || $category === $preservedCategory;
        }
        if ($category === OrganizationalAccessService::PENRO_TSD_CHIEF) {
            return (bool) $this->current()['penro_tsd_chief_enabled'] || $category === $preservedCategory;
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function save(int $expectedVersion, bool $office, bool $tsd, ?string $reason, User $actor): array
    {
        return DB::transaction(function () use ($expectedVersion, $office, $tsd, $reason, $actor): array {
            $head = RoutingPositionSetting::query()->whereKey(1)->lockForUpdate()->first();
            $previous = $head ? RoutingPositionSettingVersion::query()->find($head->setting_version_id) : null;
            if (! $head || ! $previous) throw new RuntimeException('The current routing settings head is invalid.');
            if ((int) $previous->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => 'Routing settings changed in another session. Refresh before saving.']);
            }
            if ((bool) $previous->office_penro_enabled === $office && (bool) $previous->penro_tsd_chief_enabled === $tsd) return $this->current();

            $now = now();
            $version = RoutingPositionSettingVersion::query()->create([
                'version' => (int) $previous->version + 1,
                'office_penro_enabled' => $office,
                'penro_tsd_chief_enabled' => $tsd,
                'saved_by' => $actor->getKey(),
                'saved_at' => $now,
                'reason' => filled($reason) ? trim((string) $reason) : 'Routing position controls updated',
            ]);
            $head->setting_version_id = $version->getKey();
            $head->save();
            $this->audit->record(
                'submission_tracking',
                'Routing Position Settings Updated',
                'routing_position_setting_versions',
                $version->getKey(),
                'submission-tracking',
                'Routing position settings updated to version '.$version->version.'.',
                [
                    'previous_version' => (int) $previous->version,
                    'previous' => ['office_penro_enabled' => (bool) $previous->office_penro_enabled, 'penro_tsd_chief_enabled' => (bool) $previous->penro_tsd_chief_enabled],
                    'current_version' => (int) $version->version,
                    'current' => ['office_penro_enabled' => $office, 'penro_tsd_chief_enabled' => $tsd],
                    'reason' => $version->reason,
                ],
                (int) $actor->getKey(),
            );

            return $this->current();
        });
    }
}
