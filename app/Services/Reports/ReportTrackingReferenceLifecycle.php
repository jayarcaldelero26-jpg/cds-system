<?php

namespace App\Services\Reports;

use App\Models\ReportTrackingReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

final class ReportTrackingReferenceLifecycle
{
    public function __construct(private readonly ReportTrackingNumberService $trackingNumbers) {}

    /** @param callable():mixed $deleteSource */
    public function deleteSource(Model $source, callable $deleteSource): mixed
    {
        return DB::transaction(function () use ($source, $deleteSource): mixed {
            $sourceType = $this->trackingNumbers->sourceTypeFor($source::class);
            if ($sourceType === null) {
                throw new \InvalidArgumentException('Unsupported report tracking source model.');
            }

            $query = $source->newQuery();
            if (in_array(SoftDeletes::class, class_uses_recursive($source::class), true)) $query->withTrashed();
            $lockedSource = $query->whereKey($source->getKey())->lockForUpdate()->first();
            if (! $lockedSource) {
                throw new \RuntimeException('Cannot delete a missing tracked source record.');
            }

            $reference = ReportTrackingReference::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $source->getKey())
                ->lockForUpdate()
                ->first();

            if ($reference && $reference->source_deleted_at === null) {
                $reference->source_deleted_at = now();
                $reference->saveOrFail();
            }

            $result = $deleteSource();
            if ($result === false) {
                throw new \RuntimeException('The tracked source record could not be deleted.');
            }
            if ($source->newQuery()->whereKey($source->getKey())->exists()) {
                throw new \RuntimeException('The tracked source record still exists after deletion.');
            }

            return $result;
        });
    }
}
