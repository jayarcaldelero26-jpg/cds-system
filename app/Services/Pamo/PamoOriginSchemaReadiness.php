<?php

namespace App\Services\Pamo;

use App\Models\ConservationReportSubmission;
use App\Models\SubmissionRoutingSnapshot;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Checks the optional PAMO origin schema on the snapshot model's connection. */
final class PamoOriginSchemaReadiness
{
    private const SNAPSHOTS = 'submission_routing_snapshots';

    /** @var list<string> */
    private const ORIGIN_COLUMNS = [
        'origin_kind',
        'protected_area_id',
        'pamo_office_id',
        'pamo_office_name',
        'responsible_cenro_office_id',
        'responsible_cenro_name',
        'originator_user_id',
        'origin_started_at',
    ];

    public function isOriginSchemaReady(): bool
    {
        $connection = $this->snapshotConnection();
        $request = app()->bound('request') ? app(Request::class) : null;
        $cacheKey = $this->cacheKey($connection);

        if ($request?->attributes->has($cacheKey)) {
            return (bool) $request->attributes->get($cacheKey);
        }

        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable(self::SNAPSHOTS)) {
            return $this->remember($request, $cacheKey, false);
        }

        $columns = $schema->getColumnListing(self::SNAPSHOTS);
        if (! in_array('origin_kind', $columns, true)) {
            return $this->remember($request, $cacheKey, false);
        }

        $missing = array_values(array_diff(self::ORIGIN_COLUMNS, $columns));
        if ($missing !== []) {
            throw new HttpException(503, 'PAMO route origin schema is incomplete; origin-aware routing is unavailable.');
        }

        return $this->remember($request, $cacheKey, true);
    }

    public function isPamoConservationOrigin(Model $record, string $sourceKey): bool
    {
        if (! $record instanceof ConservationReportSubmission || $sourceKey !== 'conservation') {
            return false;
        }

        if (! $this->isOriginSchemaReady()) {
            return false;
        }

        return $this->snapshotConnection()
            ->table(self::SNAPSHOTS)
            ->where('source_key', 'conservation')
            ->where('source_id', $record->getKey())
            ->value('origin_kind') === 'pamo_pamb';
    }

    public function assertDraftAndOriginSchemaReady(): void
    {
        $connection = $this->snapshotConnection();
        if (! $connection->getSchemaBuilder()->hasTable('pamo_pamb_drafts') || ! $this->isOriginSchemaReady()) {
            throw new HttpException(503, 'PAMO/PAMB drafts are unavailable until the PAMO origin schema is installed.');
        }
    }

    private function snapshotConnection(): Connection
    {
        return (new SubmissionRoutingSnapshot())->getConnection();
    }

    private function cacheKey(Connection $connection): string
    {
        return self::class.':'.hash('sha256', implode('|', [
            (string) $connection->getName(),
            $connection->getDatabaseName(),
            (string) spl_object_id($connection),
        ]));
    }

    private function remember(?Request $request, string $key, bool $ready): bool
    {
        $request?->attributes->set($key, $ready);

        return $ready;
    }
}
