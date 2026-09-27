<?php

namespace App\Services\Archive;

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\ModuleDefinition;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Enforces the archive invariant for actual recorded routing events. */
final class CompletedReportArchiveHook
{
    public function __construct(
        private readonly SubmissionTrackingService $tracking,
        private readonly ReportDocumentAdapterResolver $documents,
        private readonly FinalDocumentArchiver $archiver,
        private readonly OrganizationalAccessService $organization,
        private readonly PambSubmissionAccessService $pambAccess,
        private readonly PambRoutingTimelineService $pambTimeline,
        private readonly ArchiveCheckpointPolicy $checkpointPolicy,
        private readonly ArchiveOfficeResolver $archiveOffices,
    ) {}

    /** Generic checkpoint lifecycle is mandatory and must run inside its routing transaction. */
    public function afterRoutingEvent(DocumentRoutingEvent $event, ?User $actor): array
    {
        if (! $this->isGenericCheckpoint($event)) return ['status' => 'not_records_checkpoint'];
        if (! $actor) throw ValidationException::withMessages(['archive' => 'The archive checkpoint requires an authenticated routing actor.']);

        $this->checkpointPolicy->assertEnabledAndConfigured();
        $source = (string) $event->source_type;
        $recordId = (int) $event->source_id;
        $sourceDefinition = $this->tracking->source($source);
        if (! $sourceDefinition) throw ValidationException::withMessages(['archive' => 'The required archive source is unavailable.']);
        $record = $sourceDefinition['model']::query()->findOrFail($recordId);
        $module = $this->moduleForSource($sourceDefinition, $record);
        $archiveUnit = $this->archiveUnitForSource($sourceDefinition);
        $archiveOffice = $this->archiveOffices->resolve($sourceDefinition, $record);
        if (! $this->hasCheckpointEvent($event)) throw ValidationException::withMessages(['archive' => 'The required archive checkpoint event is no longer current.']);

        return $this->archiveRecord($source, $record, $module, $archiveUnit, $archiveOffice, $actor, true, function (Model $locked) use ($event, $actor, $source): void {
            abort_unless($this->hasCheckpointEvent($event->fresh()), 409);
            $sourceDefinition = $this->tracking->source($source);
            abort_unless($sourceDefinition
                && $this->organization->canUseSubmissionTrackingSource($actor, $source, $sourceDefinition['ability']), 403);
            abort_unless($this->organization->canViewSubmissionAttachment($actor, $locked), 403);
            if ($locked instanceof ConservationReportSubmission) abort_unless($this->pambAccess->canView($actor, $locked), 403);
        });
    }

    /** PAMB keeps its existing historical checkpoint behavior, independent of generic route actions. */
    public function afterPambTransition(string $source, int $recordId, string $actionKey, ?User $actor): array
    {
        if (! $actor || $this->checkpointAction($source, $actionKey) !== PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO) {
            return ['status' => 'not_records_checkpoint'];
        }
        $this->checkpointPolicy->assertEnabledAndConfigured();

        try {
            $sourceDefinition = $this->tracking->source($source);
            if (! $sourceDefinition) return ['status' => 'not_applicable'];
            $record = $sourceDefinition['model']::query()->findOrFail($recordId);
            $module = $this->moduleForSource($sourceDefinition, $record);
            $archiveUnit = $this->archiveUnitForSource($sourceDefinition);
            $archiveOffice = $this->archiveOffices->resolve($sourceDefinition, $record);
            if (! ($record instanceof ConservationReportSubmission)
                || ! $record->routingEvents()->get()->contains(fn (PambRoutingEvent $event): bool =>
                    $this->pambTimeline->canonicalStageKey((string) $event->stage_key) === PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO
                )) return ['status' => 'receipt_event_not_found'];

            return $this->archiveRecord($source, $record, $module, $archiveUnit, $archiveOffice, $actor, true, function (Model $locked) use ($actor): void {
                abort_unless($this->organization->canViewSubmissionAttachment($actor, $locked), 403);
                if ($locked instanceof ConservationReportSubmission) abort_unless($this->pambAccess->canView($actor, $locked), 403);
            });
        } catch (Throwable $exception) {
            Log::error('PAMB checkpoint archive hook failed.', ['source' => $source, 'record_id' => $recordId, 'exception' => $exception::class]);
            throw ValidationException::withMessages(['archive' => 'The required PENRO Records archive checkpoint could not be verified. Routing was not advanced.']);
        }
    }

    /** @param callable(Model):void $assertFinalAndAuthorized */
    private function archiveRecord(string $source, Model $record, ModuleDefinition $module, string $archiveUnit, string $archiveOffice, User $actor, bool $mandatory, callable $assertFinalAndAuthorized): array
    {
        $resolution = $this->documents->resolveOfficialDocumentSlot($source, $record);
        if ($resolution['status'] !== ReportDocumentAdapterResolver::SUPPORTED
            || ! $resolution['adapter'] || ! is_string($resolution['slot']) || $resolution['slot'] === '') {
            if ($mandatory) throw ValidationException::withMessages(['archive' => 'The official document required for the archive checkpoint is unavailable.']);
            Log::warning('PAMB checkpoint has no resolvable official document slot.', ['source' => $source, 'record_id' => $record->getKey(), 'resolution' => $resolution['status']]);
            return ['status' => 'unresolved_document'];
        }

        $result = $this->archiver->archiveFinalDocumentUsingAdapter(
            $record,
            $source,
            $resolution['slot'],
            $resolution['adapter'],
            $resolution['slot'],
            (int) $actor->getKey(),
            $assertFinalAndAuthorized,
            config('services.document_archive.folder_id'),
            $module,
            $archiveUnit,
            $archiveOffice,
        );
        if ($mandatory && $result['status'] !== 'archived') {
            throw ValidationException::withMessages(['archive' => 'The required archive checkpoint could not be verified. Routing was not advanced.']);
        }

        return $result;
    }

    /** @param array<string,mixed> $source */
    private function moduleForSource(array $source, Model $record): ModuleDefinition
    {
        $resolver = $source['archive_module_code'] ?? null;
        if (! is_callable($resolver)) {
            throw ValidationException::withMessages(['archive' => 'The required archive module is not mapped.']);
        }

        $module = ModuleDefinition::query()->active()->notRetired()->where('code', (string) $resolver($record))->first();
        if (! $module) {
            throw ValidationException::withMessages(['archive' => 'The required archive module is not mapped.']);
        }

        return $module;
    }

    /** @param array<string,mixed> $source */
    private function archiveUnitForSource(array $source): string
    {
        $unit = $source['archive_unit'] ?? null;
        if (! in_array($unit, ['Conservation Unit', 'Development Unit'], true)) {
            throw ValidationException::withMessages(['archive' => 'The required archive unit is not mapped.']);
        }

        return $unit;
    }


    private function isGenericCheckpoint(DocumentRoutingEvent $event): bool
    {
        return $this->checkpointPolicy->isCheckpoint((string) data_get($event->metadata, 'action_key'), (string) $event->from_stage, (string) $event->to_stage)
            && $event->event_key === 'forwarded';
    }

    private function hasCheckpointEvent(DocumentRoutingEvent $event): bool
    {
        return $event->exists && $this->isGenericCheckpoint($event)
            && DocumentRoutingEvent::query()->whereKey($event->getKey())
                ->where('source_type', $event->source_type)->where('source_id', $event->source_id)
                ->where('event_key', 'forwarded')->where('from_stage', 'penro_records')
                ->where('to_stage', 'transit_to_office_of_penro')->exists();
    }

    private function checkpointAction(string $source, string $actionKey): ?string
    {
        if ($source === 'conservation' && $actionKey === 'forward_to_office_penro') return $actionKey;
        return $source === 'conservation'
            && $this->pambTimeline->canonicalStageKey($actionKey) === PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO
                ? PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO
                : null;
    }
}
