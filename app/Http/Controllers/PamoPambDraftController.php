<?php

namespace App\Http\Controllers;

use App\Models\ConservationReportSubmission;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Pamo\PamoNativeDraftAdapter;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class PamoPambDraftController extends Controller
{
    private const PRIVATE_DISK = 'local';
    private const MAX_FILE_KB = 102400;

    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly ConservationReportSubmissionController $reports,
        private readonly CurrentDocumentReplacementService $documents,
        private readonly ProtectedAttachmentService $attachments,
        private readonly RoutingPositionSnapshotService $routePositions,
        private readonly DocumentRoutingTransitionService $routing,
        private readonly PambMovProcessingService $pambMov,
        private readonly PamoNativeDraftAdapter $nativeDrafts,
        private readonly PamoNativeSourceRegistry $nativeSources,
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
    ) {}

    public function index(Request $request, string $workflow): array
    {
        if (! Schema::hasTable('pamo_pamb_drafts')) return ['pamoDrafts' => [], 'pamoWorkspace' => false];
        $facts = $this->assertPamoActor($request->user());
        $this->assertPilotWorkflow($workflow);
        $drafts = PamoPambDraft::query()
            ->where('protected_area_id', $facts['protected_area_id'])
            ->where('pamo_office_id', $facts['pamo_office_id'])
            ->where('workflow_key', $workflow)
            ->with('sourceSubmission:id,workflow_key')
            ->latest('started_at')->limit(100)->get()
            ->map(fn (PamoPambDraft $draft): array => $this->present($draft))
            ->all();

        return ['pamoDrafts' => $drafts, 'pamoWorkspace' => true];
    }

    public function store(Request $request, string $workflow): RedirectResponse
    {
        $actor = $request->user();
        $facts = $this->assertPamoActor($actor);
        $this->assertPilotWorkflow($workflow);
        $data = $this->partialPayload($request, $workflow);
        $file = $request->file('mov');
        $token = $request->validate([
            'start_idempotency_key' => ['required', 'uuid'],
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'target_office' => ['prohibited'], 'conservation_report_submission_id' => ['prohibited'],
            'office_designated' => ['prohibited'], 'pamo_office_name' => ['prohibited'],
            'workflow_key' => ['prohibited'], 'started_at' => ['prohibited'], 'originator_user_id' => ['prohibited'],
            'draft_uuid' => ['prohibited'],
        ])['start_idempotency_key'];

        $existing = PamoPambDraft::query()->where('start_idempotency_key', $token)->first();
        if ($existing) {
            $this->assertDraftAccess($actor, $existing, $workflow, $facts);
            $this->saveLocked($request, $existing, $workflow, $facts, $data, $file, null);
            return back()->with('success', 'Draft saved.');
        }

        $uuid = (string) Str::uuid();
        $now = CarbonImmutable::now(BusinessCalendarService::TIMEZONE);
        try {
            $draft = PamoPambDraft::query()->create([
                'draft_uuid' => $uuid,
                'start_idempotency_key' => $token,
                'protected_area_id' => $facts['protected_area_id'],
                'pamo_office_id' => $facts['pamo_office_id'],
                'pamo_office_name' => $facts['pamo_office_name'],
                'originator_user_id' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'workflow_key' => $workflow,
                'report_payload' => $data,
                'started_at' => $now,
                'status' => 'draft',
                'lock_version' => 1,
            ]);
        } catch (QueryException $exception) {
            $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->first();
            if (! $draft) throw $exception;
            $this->assertDraftAccess($actor, $draft, $workflow, $facts);
        }

        if ($file) $this->saveLocked($request, $draft, $workflow, $facts, $data, $file, 1);

        return back()->with('success', 'Draft saved.');
    }

    public function update(Request $request, string $workflow, PamoPambDraft $draft): RedirectResponse
    {
        $actor = $request->user();
        $facts = $this->assertPamoActor($actor);
        $this->assertDraftAccess($actor, $draft, $workflow, $facts);
        $data = $this->partialPayload($request, $workflow);
        $file = $request->file('mov');
        $expectedVersion = (int) $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'target_office' => ['prohibited'], 'conservation_report_submission_id' => ['prohibited'],
            'office_designated' => ['prohibited'], 'pamo_office_name' => ['prohibited'],
            'workflow_key' => ['prohibited'], 'started_at' => ['prohibited'], 'originator_user_id' => ['prohibited'],
            'draft_uuid' => ['prohibited'], 'start_idempotency_key' => ['prohibited'],
        ])['lock_version'];
        $this->saveLocked($request, $draft, $workflow, $facts, $data, $file, $expectedVersion);

        return back()->with('success', 'Draft saved.');
    }

    public function forward(Request $request, string $workflow, PamoPambDraft $draft): RedirectResponse
    {
        $actor = $request->user();
        $facts = $this->assertPamoActor($actor);
        $this->assertDraftAccess($actor, $draft, $workflow, $facts);
        $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'target_office' => ['prohibited'], 'conservation_report_submission_id' => ['prohibited'],
            'bms_report_submission_id' => ['prohibited'], 'bams_report_submission_id' => ['prohibited'], 'imea_report_submission_id' => ['prohibited'],
            'imea_facility_maintenance_report_id' => ['prohibited'], 'aws_report_id' => ['prohibited'], 'ipaf_management_report_id' => ['prohibited'], 'ipaf_revenue_collection_id' => ['prohibited'],
            'office_designated' => ['prohibited'], 'pamo_office_name' => ['prohibited'],
            'workflow_key' => ['prohibited'], 'started_at' => ['prohibited'], 'originator_user_id' => ['prohibited'],
            'draft_uuid' => ['prohibited'],
        ]);
        // Forward carries the current modal values so the final validation and
        // promotion use what the user sees, even when those values have not
        // been saved as a separate draft update yet.
        $currentPayload = $this->partialPayload($request, $workflow);

        $sourcePathToClean = null;
        $oldDraftPathToClean = null;
        try {
            $submission = DB::transaction(function () use ($request, $draft, $workflow, $facts, $actor, $currentPayload, &$sourcePathToClean, &$oldDraftPathToClean) {
            $locked = PamoPambDraft::query()->lockForUpdate()->findOrFail($draft->getKey());
            $this->assertDraftAccess($actor, $locked, $workflow, $facts);
            if ($locked->status === 'forwarded' && $locked->conservation_report_submission_id) {
                return ConservationReportSubmission::query()->findOrFail($locked->conservation_report_submission_id);
            }
            $native = $this->nativeSources->find($workflow);
            if ($locked->status === 'forwarded' && $native && $locked->getAttribute($native['column'])) {
                $model = $native['model'];
                return $model::query()->findOrFail($locked->getAttribute($native['column']));
            }
            if ($locked->status !== 'draft') throw ValidationException::withMessages(['draft' => 'This draft is no longer available to forward.']);
            $version = (int) $request->integer('lock_version');
            if ($version !== (int) $locked->lock_version) throw ValidationException::withMessages(['draft' => 'This draft changed in another session. Reload it before forwarding.']);
            $this->assertPilotWorkflow($workflow);
            $payload = [...($locked->report_payload ?? []), ...$currentPayload];

            $path = $locked->mov_path;
            if (! $this->isDraftPath($locked, $path) || ! Storage::disk(self::PRIVATE_DISK)->exists($path)) {
                throw ValidationException::withMessages(['mov' => 'Upload the required report or MOV before forwarding.']);
            }
            $absolutePath = Storage::disk(self::PRIVATE_DISK)->path($path);
            $storedFile = new UploadedFile($absolutePath, (string) $locked->mov_file_name, mime_content_type($absolutePath) ?: 'application/octet-stream', UPLOAD_ERR_OK, true);
            if ($native) {
                [$nativeRecord, $sourcePathToClean] = $this->nativeDrafts->promote($locked, $workflow, $facts, $actor, $storedFile, $payload);
                $locked->update([
                    'report_payload' => $payload,
                    $native['column'] => $nativeRecord->getKey(),
                    'status' => 'forwarded',
                    'forwarded_at' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE),
                    'updated_by' => $actor->getKey(),
                    'mov_path' => null,
                    'lock_version' => ((int) $locked->lock_version) + 1,
                ]);
                $oldDraftPathToClean = $path;
                return $nativeRecord;
            }
            $attributes = [
                ...$payload,
                'protected_area_id' => $facts['protected_area_id'],
                'target_office' => $facts['target_office'],
                'mov' => $storedFile,
            ];
            $validator = Validator::make($attributes, $this->reports->pamoPilotReportRules($workflow, true), $this->attachmentMessages($attributes['document_type'] ?? null));
            $validated = $validator->validate();
            $dateRequest = Request::create('/', 'POST', $attributes);
            $this->reports->assertPamoPilotActualDates($validated, $dateRequest, $workflow);
            $validated = $this->reports->applyPamoDateConductedRanges($validated, $workflow, $attributes['date_conducted_ranges'] ?? null);
            unset($validated['mov']);

            $submission = ConservationReportSubmission::query()->create([
                ...$validated,
                'workflow_key' => $workflow,
                'created_by' => $locked->originator_user_id,
                'updated_by' => $actor->getKey(),
            ]);
            $adapter = app(\App\Services\Attachments\ReportDocumentAdapterResolver::class)
                ->resolve('conservation-report', $submission, 'mov');
            $replacement = $this->documents->replaceUsingAdapter(
                $submission,
                'conservation-report',
                'mov',
                $storedFile,
                $adapter,
                'mov',
                (int) $actor->getKey(),
                fn (ConservationReportSubmission $record) => $this->organization->assertCanAccessProtectedArea($actor, $record->protected_area_id),
                null,
                [],
                'UPLOAD',
            );
            $sourcePathToClean = $replacement['path'];
            $this->pambMov->recordUpload($submission->fresh(), $actor);

            $position = $this->routePositions->resolve($submission, 'conservation', false, true, true);
            $origin = [
                'protected_area_id' => $facts['protected_area_id'],
                'pamo_office_id' => $facts['pamo_office_id'],
                'pamo_office_name' => $facts['pamo_office_name'],
                'responsible_cenro_office_id' => $facts['responsible_cenro_office_id'],
                'responsible_cenro_name' => $facts['responsible_cenro_name'],
                'originator_user_id' => $locked->originator_user_id,
                'origin_started_at' => $locked->started_at,
            ];
            $this->routePositions->capture($submission, 'conservation', $position, $facts['profile'], $actor, $origin);
            $action = $facts['profile'] === 'pamo_direct' ? 'forward_from_pamo' : 'forward_from_pamo_to_cenro_focal';
            $this->routing->transition($submission, 'conservation', $action, (int) $actor->getKey(), null, null, null, true);

            $locked->update([
                'report_payload' => $payload,
                'status' => 'forwarded',
                'conservation_report_submission_id' => $submission->getKey(),
                'forwarded_at' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE),
                'updated_by' => $actor->getKey(),
                'mov_path' => null,
                'lock_version' => ((int) $locked->lock_version) + 1,
            ]);
            $oldDraftPathToClean = $path;
                return $submission->fresh();
            });
        } catch (\Throwable $exception) {
            if ($sourcePathToClean) Storage::disk(self::PRIVATE_DISK)->delete($sourcePathToClean);
            throw $exception;
        }

        if ($oldDraftPathToClean) Storage::disk(self::PRIVATE_DISK)->delete($oldDraftPathToClean);

        return back()->with('success', 'Report forwarded successfully.')->with('forwarded_submission_id', $submission->getKey());
    }

    public function showMov(Request $request, string $workflow, PamoPambDraft $draft): HttpResponse
    {
        $actor = $request->user();
        $facts = $this->assertPamoActor($actor);
        $this->assertDraftAccess($actor, $draft, $workflow, $facts);
        abort_unless($this->isDraftPath($draft, $draft->mov_path) && Storage::disk(self::PRIVATE_DISK)->exists($draft->mov_path), 404);
        $absolute = Storage::disk(self::PRIVATE_DISK)->path($draft->mov_path);
        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $filename = str_replace(['"', "\r", "\n"], '', (string) $draft->mov_file_name);
        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function showSourceMov(Request $request, string $workflow, PamoPambDraft $draft): HttpResponse
    {
        $actor = $request->user();
        $facts = $this->assertPamoActor($actor);
        $this->assertDraftAccess($actor, $draft, $workflow, $facts);
        $source = $this->nativeSources->find($workflow);
        abort_unless($source && $draft->status === 'forwarded' && $draft->getAttribute($source['column']), 404);
        $model = $source['model'];
        $record = $model::query()->findOrFail($draft->getAttribute($source['column']));
        abort_unless((int) $record->protected_area_id === (int) $draft->protected_area_id, 404);
        $this->organization->assertCanAccessProtectedArea($actor, $record->protected_area_id);
        return $this->attachments->response($source['attachment'], $record, $source['file_field']);
    }

    private function saveLocked(Request $request, PamoPambDraft $draft, string $workflow, array $facts, array $data, ?UploadedFile $file, ?int $expectedVersion): void
    {
        $newPath = null;
        if ($file) {
            $directory = 'pamo-pamb-drafts/'.$draft->pamo_office_id.'/'.$draft->protected_area_id.'/'.$draft->draft_uuid.'/mov';
            $extension = strtolower((string) $file->guessExtension());
            $newPath = $file->storeAs($directory, Str::random(40).($extension ? '.'.$extension : ''), self::PRIVATE_DISK);
            if (! is_string($newPath) || ! Storage::disk(self::PRIVATE_DISK)->exists($newPath)) {
                throw ValidationException::withMessages(['mov' => 'The draft upload could not be stored.']);
            }
        }

        $oldPath = null;
        try {
            DB::transaction(function () use ($request, $draft, $workflow, $facts, $data, $file, $newPath, $expectedVersion, &$oldPath): void {
                $locked = PamoPambDraft::query()->lockForUpdate()->findOrFail($draft->getKey());
                $actor = $request->user();
                $this->assertDraftAccess($actor, $locked, $workflow, $facts);
                if ($locked->status !== 'draft') throw ValidationException::withMessages(['draft' => 'A forwarded report cannot be edited as a draft.']);
                if ($expectedVersion !== null && $expectedVersion !== (int) $locked->lock_version) throw ValidationException::withMessages(['draft' => 'This draft changed in another session. Reload it before saving.']);
                $oldPath = $locked->mov_path;
                $locked->update([
                    'report_payload' => [...($locked->report_payload ?? []), ...$data],
                    'mov_path' => $newPath ?: $oldPath,
                    'mov_file_name' => $file ? mb_strcut(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 250) : $locked->mov_file_name,
                    'updated_by' => $actor->getKey(),
                    'lock_version' => ((int) $locked->lock_version) + 1,
                ]);
            });
        } catch (\Throwable $exception) {
            if ($newPath) Storage::disk(self::PRIVATE_DISK)->delete($newPath);
            throw $exception;
        }

        if ($newPath && $oldPath && $oldPath !== $newPath) Storage::disk(self::PRIVATE_DISK)->delete($oldPath);
    }

    private function partialPayload(Request $request, string $workflow): array
    {
        $this->assertPilotWorkflow($workflow);
        if ($this->nativeSources->find($workflow)) return $this->nativeDrafts->partialPayload($request, $workflow);
        $definition = app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->find($workflow) ?? [];
        $documents = array_values(array_unique(array_merge(...array_values($definition['activity_documents'] ?? ['default' => ($definition['documents'] ?? [])]))));
        $periods = $definition['periods'] ?? [];
        // Draft and final submission both preserve the user's activity title.
        $rules = [
            'activity_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:100', Rule::in($documents)],
            'reporting_period' => ['sometimes', 'nullable', 'string', 'max:100', Rule::in($periods)],
            'date_conducted' => ['sometimes', 'nullable', 'date'],
            'date_accomplished' => ['sometimes', 'nullable', 'date'],
            'date_conducted_ranges' => app(\App\Services\DateConductedRangeService::class)->supportsWorkflow($workflow) ? ['sometimes', 'nullable', 'array'] : ['prohibited'],
            'date_conducted_ranges.*.from' => ['nullable', 'date_format:Y-m-d'],
            'date_conducted_ranges.*.to' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'mov' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.self::MAX_FILE_KB],
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'target_office' => ['prohibited'], 'conservation_report_submission_id' => ['prohibited'],
            'office_designated' => ['prohibited'], 'pamo_office_name' => ['prohibited'],
            'workflow_key' => ['prohibited'], 'started_at' => ['prohibited'], 'originator_user_id' => ['prohibited'],
            'draft_uuid' => ['prohibited'], 'id' => ['prohibited'], 'forwarded_at' => ['prohibited'],
        ];
        $validated = Validator::make($request->all(), $rules)->validate();
        foreach (['date_conducted', 'date_accomplished'] as $field) {
            if (! empty($validated[$field])) app(\App\Services\ActualActivityDateGuard::class)->assertNotFuture($validated[$field], $field, $field === 'date_conducted' ? 'Date Conducted' : 'Date Accomplished');
        }
        if (! empty($validated['date_conducted_ranges'])) {
            app(\App\Services\ActualActivityDateGuard::class)->assertDateConductedRanges($validated['date_conducted_ranges'], null, null);
        }
        return collect($validated)->except('mov')->all();
    }

    private function assertPamoActor(?User $actor): array
    {
        abort_unless($actor && $actor->is_active && $actor->is_approved
            && $this->organization->effectiveCategory($actor) === OrganizationalAccessService::PAMO
            && $actor->protected_area_id !== null, 403);
        $facts = $this->organization->pamoAssignmentForProtectedArea((int) $actor->protected_area_id);
        abort_unless($facts && $this->organization->canAccessProtectedArea($actor, $facts['protected_area_id']), 403);
        $this->pamoOrigins->assertDraftAndOriginSchemaReady();
        return $facts;
    }

    private function assertDraftAccess(?User $actor, PamoPambDraft $draft, string $workflow, array $facts): void
    {
        abort_unless($draft->workflow_key === $workflow && $this->supportsWorkflow($workflow), 404);
        abort_unless((int) $draft->protected_area_id === (int) $actor?->protected_area_id
            && (int) $draft->protected_area_id === (int) $facts['protected_area_id']
            && (int) $draft->pamo_office_id === (int) $facts['pamo_office_id'], 403);
        abort_unless($this->organization->canAccessProtectedArea($actor, $draft->protected_area_id), 403);
    }

    private function assertPilotWorkflow(string $workflow): void
    {
        if (app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->supportsPamoSubmission($workflow)) return;
        abort_unless($this->nativeSources->isEligible($workflow), 404);
        $this->nativeSources->assertSchemaReady();
    }

    private function supportsWorkflow(string $workflow): bool
    {
        return app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->supportsPamoSubmission($workflow)
            || ($this->nativeSources->isSchemaReady() && $this->nativeSources->isEligible($workflow));
    }

    private function isDraftPath(PamoPambDraft $draft, ?string $path): bool
    {
        if (! is_string($path) || str_contains($path, '..') || str_starts_with($path, '/')) return false;
        $prefix = 'pamo-pamb-drafts/'.$draft->pamo_office_id.'/'.$draft->protected_area_id.'/'.$draft->draft_uuid.'/mov/';
        return str_starts_with(str_replace('\\', '/', $path), $prefix);
    }

    private function present(PamoPambDraft $draft): array
    {
        $native = $this->nativeSources->find((string) $draft->workflow_key);
        $nativeId = $native ? $draft->getAttribute($native['column']) : null;
        $sourceKey = $native['source'] ?? 'conservation';
        $sourceId = $nativeId ?: $draft->conservation_report_submission_id;
        $sourceRecord = null;
        $sourceMovUrl = null;
        $sourceUrl = null;
        if ($nativeId && $native) {
            $model = $native['model'];
            $sourceRecord = $model::query()->find($nativeId);
            if ($sourceRecord
                && (int) $sourceRecord->protected_area_id === (int) $draft->protected_area_id
                && $this->attachments->descriptor($native['attachment'], $sourceRecord, $native['file_field']) !== null) {
                $sourceMovUrl = route('conservation-reports.pamo-drafts.source-mov', [$draft->workflow_key, $draft->draft_uuid]);
            }
        } elseif ($draft->conservation_report_submission_id) {
            $sourceRecord = ConservationReportSubmission::query()->find($draft->conservation_report_submission_id);
            if ($sourceRecord
                && $sourceRecord->workflow_key === $draft->workflow_key
                && (int) $sourceRecord->protected_area_id === (int) $draft->protected_area_id) {
                if ($this->attachments->descriptor('conservation-report', $sourceRecord, 'mov') !== null) {
                    $sourceMovUrl = route('conservation-reports.mov', [$draft->workflow_key, $sourceRecord->getKey()]);
                }
            }
        }
        if ($sourceId && $sourceRecord) {
            $view = $this->trackingView($sourceRecord, $sourceKey);
            $sourceUrl = route('submission-tracking.index', ['view' => $view, 'source' => $sourceKey, 'source_id' => $sourceId]);
        }

        return [
            'id' => $draft->draft_uuid,
            'workflow_key' => $draft->workflow_key,
            'status' => $draft->status,
            'report_payload' => $draft->report_payload ?? [],
            'started_at' => $draft->started_at?->toIso8601String(),
            'forwarded_at' => $draft->forwarded_at?->toIso8601String(),
            'lock_version' => (int) $draft->lock_version,
            'mov_file_name' => $draft->mov_file_name,
            'mov_url' => $draft->mov_path ? route('conservation-reports.pamo-drafts.mov', [$draft->workflow_key, $draft->draft_uuid]) : null,
            'source_mov_url' => $sourceMovUrl,
            'source_key' => $nativeId ? $sourceKey : ($sourceId ? 'conservation' : null),
            'source_submission_id' => $sourceId,
            'source_url' => $sourceUrl,
        ];
    }

    private function trackingView(\Illuminate\Database\Eloquent\Model $record, string $sourceKey): string
    {
        $state = $this->routing->state($record, $sourceKey);
        if ($state['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL) {
            return 'history';
        }
        $lastEvent = $state['events']->last();
        if ($state['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
            && $lastEvent?->event_key === 'correction_received') {
            return 'incoming';
        }

        return ($state['route_position']['origin_kind'] ?? null) === 'pamo_pamb'
            ? 'outgoing'
            : 'incoming';
    }

    private function attachmentMessages(?string $documentType): array
    {
        return [
            'date_accomplished.after_or_equal' => 'Date Accomplished must be on or after Date Conducted.',
            'mov.required' => in_array($documentType, ['Minutes', 'Reso', 'Resolution'], true) ? 'The selected PAMB document requires its MOV attachment.' : 'A report attachment / MOV is required.',
            'mov.max' => 'The report attachment / MOV must not exceed 100 MB.',
        ];
    }
}
