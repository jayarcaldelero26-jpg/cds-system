<?php

namespace App\Services\Archive;

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolves PA folder labels only from a verified PAMO route origin. */
final class PamoArchiveFolderGroupingResolver
{
    public function __construct(
        private readonly PamoOriginSchemaReadiness $originSchema,
        private readonly PamoNativeSourceRegistry $nativeSources,
    ) {}

    public function forFinalReturn(string $source, Model $record, string $archivePhase): ?string
    {
        if ($archivePhase !== \App\Models\FinalDocumentArchiveManifest::PHASE) return null;

        $native = $this->nativeSources->findBySource($source);
        $supported = $source === 'conservation'
            ? $record instanceof ConservationReportSubmission
            : ($native !== null && is_a($record, $native['definition']['model']));
        if (! $supported || ! filled($record->getAttribute('protected_area_id'))) return null;
        if (! $this->originSchema->isOriginSchemaReady()) return null;

        $snapshots = SubmissionRoutingSnapshot::query()
            ->where('source_key', $source)
            ->where('source_id', $record->getKey())
            ->limit(2)
            ->get();
        if ($snapshots->isEmpty()) return null;
        if ($snapshots->count() !== 1) $this->unmapped();

        $snapshot = $snapshots->first();
        $pamoProfiles = ['pamo_regular', 'pamo_direct'];
        if ($snapshot->origin_kind !== 'pamo_pamb') {
            if ($snapshot->origin_kind !== null || in_array($snapshot->profile, $pamoProfiles, true)) {
                $this->unmapped();
            }

            return null;
        }

        $areaId = (int) $record->getAttribute('protected_area_id');
        if (! in_array($snapshot->profile, $pamoProfiles, true)
            || $snapshot->graph_version !== \App\Services\SubmissionTracking\RoutingPositionSnapshotService::PAMO_GRAPH_VERSION
            || $areaId < 1
            || (int) $snapshot->protected_area_id !== $areaId
            || (int) $snapshot->pamo_office_id < 1
            || ! filled($snapshot->pamo_office_name)
            || ! filled($snapshot->originator_user_id)
            || ! $snapshot->origin_started_at) {
            $this->unmapped();
        }

        if ($snapshot->profile === 'pamo_regular'
            && ((int) $snapshot->responsible_cenro_office_id < 1 || ! filled($snapshot->responsible_cenro_name))) {
            $this->unmapped();
        }
        if ($snapshot->profile === 'pamo_direct'
            && ($snapshot->responsible_cenro_office_id !== null || filled($snapshot->responsible_cenro_name))) {
            $this->unmapped();
        }

        if ($snapshot->profile === 'pamo_regular') {
            $cenro = OrganizationalOffice::query()->find((int) $snapshot->responsible_cenro_office_id);
            if (! $cenro || ! $cenro->is_active || $cenro->office_type !== 'cenro'
                || $this->normalize($cenro->name) !== $this->normalize((string) $snapshot->responsible_cenro_name)) {
                $this->unmapped();
            }
        }

        $area = ProtectedArea::query()->find($areaId);
        if (! $area || ! filled($area->name)) $this->unmapped();

        $assignments = ProtectedAreaOfficeAssignment::query()
            ->with('office')
            ->where('protected_area_id', $areaId)
            ->where('assignment_type', 'pamo')
            ->limit(2)
            ->get();
        if ($assignments->count() !== 1) $this->unmapped();
        $office = $assignments->first()->office;
        if (! $office instanceof OrganizationalOffice
            || ! $office->is_active
            || $office->office_type !== 'pamo'
            || (int) $office->getKey() !== (int) $snapshot->pamo_office_id
            || $this->normalize($office->name) !== $this->normalize((string) $snapshot->pamo_office_name)) {
            $this->unmapped();
        }

        if ($native !== null) {
            $this->assertNativeSourceBinding($native['workflow'], $native['definition'], $record, $snapshot, $areaId);
        }

        $label = filled($area->short_name) ? trim((string) $area->short_name) : trim((string) $area->name);
        if ($label === '') $this->unmapped();

        return $label;
    }

    /** @param array{source:string, attachment:string, column:string, model:class-string<Model>, file_field:string, label:string} $definition */
    private function assertNativeSourceBinding(string $workflow, array $definition, Model $record, SubmissionRoutingSnapshot $snapshot, int $areaId): void
    {
        $column = $definition['column'];
        if (! $this->nativeSources->isSchemaReady()
            || ! \Illuminate\Support\Facades\Schema::hasColumn('pamo_pamb_drafts', $column)) {
            $this->unmapped();
        }

        $drafts = DB::table('pamo_pamb_drafts')
            ->where('workflow_key', $workflow)
            ->where($column, $record->getKey())
            ->limit(2)
            ->get(['protected_area_id', 'pamo_office_id', 'pamo_office_name', 'status']);
        if ($drafts->count() !== 1) $this->unmapped();
        $draft = $drafts->first();
        if ((int) $draft->protected_area_id !== $areaId
            || (int) $draft->pamo_office_id !== (int) $snapshot->pamo_office_id
            || $this->normalize((string) $draft->pamo_office_name) !== $this->normalize((string) $snapshot->pamo_office_name)
            || $draft->status !== 'forwarded') {
            $this->unmapped();
        }
    }

    private function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function unmapped(): never
    {
        throw new RuntimeException('The PAMO archive PA grouping could not be verified from its captured source origin.');
    }
}
