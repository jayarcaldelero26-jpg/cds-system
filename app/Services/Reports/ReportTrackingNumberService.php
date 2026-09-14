<?php

namespace App\Services\Reports;

use App\Models\ReportTrackingReference;
use App\Models\ReportTrackingSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Assigns stable, human-readable references to actual report records only. */
final class ReportTrackingNumberService
{
    private const DOMAIN_PA = 'PA';
    private const DOMAIN_ENGP = 'ENGP';

    /** @param Collection<int,array{record:Model,key:string}> $items @return array<string,string> */
    public function ensureFor(Collection $items): array
    {
        $candidates = $items->map(function (array $item): ?array {
            $record = $item['record'];
            if (! $record->exists || ! $record->getKey() || $this->isRetired($record)) {
                return null;
            }

            $sourceType = (string) $item['key'];
            $domain = $this->domain($sourceType);

            return [
                'key' => $sourceType.':'.$record->getKey(),
                'source_type' => $sourceType,
                'source_id' => (int) $record->getKey(),
                'domain' => $domain,
                'reporting_year' => $this->reportingYear($record),
            ];
        })->filter()->values();

        if ($candidates->isEmpty()) return [];

        $existing = ReportTrackingReference::query()
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates->groupBy('source_type') as $sourceType => $sourceItems) {
                    $query->orWhere(fn ($sourceQuery) => $sourceQuery
                        ->where('source_type', $sourceType)
                        ->whereIn('source_id', $sourceItems->pluck('source_id')->all()));
                }
            })
            ->get()
            ->mapWithKeys(fn (ReportTrackingReference $reference): array => [
                $reference->source_type.':'.$reference->source_id => $reference->tracking_number,
            ]);

        foreach ($candidates as $candidate) {
            $key = $candidate['key'];
            if ($existing->has($key)) continue;

            $tracking = $this->allocate($candidate);
            $existing->put($key, $tracking);
        }

        return $existing->all();
    }

    /**
     * Return references already assigned to actual records without allocating
     * missing references. This is used by tracking views and maintenance jobs.
     *
     * @param Collection<int,array{record:Model,key:string}> $items
     * @return array<string,string>
     */
    public function existingFor(Collection $items): array
    {
        $candidates = $items->map(function (array $item): ?array {
            $record = $item['record'];
            if (! $record->exists || ! $record->getKey() || $this->isRetired($record)) return null;
            return ['key' => $item['key'].':'.$record->getKey(), 'source_type' => $item['key'], 'source_id' => (int) $record->getKey()];
        })->filter()->values();
        if ($candidates->isEmpty()) return [];

        return ReportTrackingReference::query()
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates->groupBy('source_type') as $sourceType => $sourceItems) {
                    $query->orWhere(fn ($sourceQuery) => $sourceQuery->where('source_type', $sourceType)->whereIn('source_id', $sourceItems->pluck('source_id')->all()));
                }
            })
            ->get()
            ->mapWithKeys(fn (ReportTrackingReference $reference): array => [$reference->source_type.':'.$reference->source_id => $reference->tracking_number])
            ->all();
    }

    public function allocate(array $candidate): string
    {
        return DB::transaction(function () use ($candidate): string {
            $existing = ReportTrackingReference::query()
                ->where('source_type', $candidate['source_type'])
                ->where('source_id', $candidate['source_id'])
                ->first();
            if ($existing) return $existing->tracking_number;

            DB::table('report_tracking_sequences')->insertOrIgnore([
                'domain' => $candidate['domain'],
                'reporting_year' => $candidate['reporting_year'],
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = ReportTrackingSequence::query()
                ->where('domain', $candidate['domain'])
                ->where('reporting_year', $candidate['reporting_year'])
                ->lockForUpdate()
                ->firstOrFail();
            $number = (int) $sequence->next_value;
            $sequence->update(['next_value' => $number + 1]);

            $tracking = sprintf('EDATS-%s-%d-%06d', $candidate['domain'], $candidate['reporting_year'], $number);
            try {
                ReportTrackingReference::query()->create([
                    'tracking_number' => $tracking,
                    'domain' => $candidate['domain'],
                    'source_type' => $candidate['source_type'],
                    'source_id' => $candidate['source_id'],
                    'reporting_year' => $candidate['reporting_year'],
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $found = ReportTrackingReference::query()
                    ->where('source_type', $candidate['source_type'])
                    ->where('source_id', $candidate['source_id'])
                    ->value('tracking_number');
                if (! $found) throw new \RuntimeException('Tracking reference allocation raced without a recoverable source reference.');
                return $found;
            }

            return $tracking;
        });
    }

    public function backfill(bool $dryRun = false): array
    {
        $counts = ['scanned' => 0, 'created' => 0, 'skipped' => 0];
        foreach ($this->sourceModels() as $sourceType => $modelClass) {
            $query = $modelClass::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) $query->withTrashed();
            $query->chunkById(250, function (Collection $records) use (&$counts, $sourceType, $dryRun): void {
                $counts['scanned'] += $records->count();
                $items = $records->map(fn (Model $record): array => ['record' => $record, 'key' => $sourceType]);
                if ($dryRun) {
                    $counts['created'] += $items->filter(fn (array $item): bool => ! ReportTrackingReference::query()->where('source_type', $sourceType)->where('source_id', $item['record']->getKey())->exists())->count();
                    return;
                }
                $before = ReportTrackingReference::query()->where('source_type', $sourceType)->whereIn('source_id', $records->modelKeys())->count();
                $this->ensureFor($items);
                $after = ReportTrackingReference::query()->where('source_type', $sourceType)->whereIn('source_id', $records->modelKeys())->count();
                $counts['created'] += max(0, $after - $before);
            });
        }

        $counts['skipped'] = max(0, $counts['scanned'] - $counts['created']);
        return $counts;
    }

    public function domain(string $sourceType): string
    {
        return $sourceType === 'engp' ? self::DOMAIN_ENGP : self::DOMAIN_PA;
    }

    private function reportingYear(Model $record): int
    {
        $year = $record->getAttribute('reporting_year');
        if (is_numeric($year) && (int) $year >= 2000 && (int) $year <= 2100) return (int) $year;

        foreach (['date_accomplished', 'date_conducted', 'date_received_penro', 'created_at'] as $field) {
            $value = $record->getAttribute($field);
            if ($value) return CarbonImmutable::parse($value)->year;
        }

        return CarbonImmutable::now('Asia/Manila')->year;
    }

    private function isRetired(Model $record): bool
    {
        return in_array((string) $record->getAttribute('workflow_key'), [
            'cds_lawin', 'lawin_monitoring', 'issue_monitoring', 'ppa', 'ecotourism_monitoring', 'technical_reports',
        ], true);
    }

    /** @return array<string,class-string<Model>> */
    private function sourceModels(): array
    {
        return [
            'engp' => \App\Models\EngpReportSubmission::class,
            'conservation' => \App\Models\ConservationReportSubmission::class,
            'bms' => \App\Models\BmsReportSubmission::class,
            'bams' => \App\Models\BamsReportSubmission::class,
            'imea' => \App\Models\ImeaReportSubmission::class,
            'aws' => \App\Models\Aws::class,
            'ipaf-management' => \App\Models\IpafManagementReport::class,
            'imea-maintenance' => \App\Models\ImeaFacilityMaintenanceReport::class,
            'revenue' => \App\Models\IpafRevenueCollection::class,
            'management-plans' => \App\Models\ManagementPlan::class,
        ];
    }
}
