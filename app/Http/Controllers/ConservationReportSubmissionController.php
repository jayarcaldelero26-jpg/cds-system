<?php

namespace App\Http\Controllers;

use App\Models\ConservationReportSubmission;
use App\Models\ModuleDefinition;
use App\Models\ProtectedArea;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConservationReportSubmissionController extends Controller
{
    private const PRIMARY_ATTACHMENT_MAX_KB = 102400;

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly ProtectedAttachmentService $attachments, private readonly PambComplianceCalculator $pambCompliance, private readonly PambSubmissionAccessService $pambAccess, private readonly PambMovProcessingService $pambMov) {}

    public function index(Request $request, string $workflow): Response
    {
        $config = $this->workflow($workflow);
        $submissions = $this->pambAccess->scopeQuery(ConservationReportSubmission::query(), $request->user())
            ->where('workflow_key', $workflow)
            ->with(['protectedArea:id,name,short_name', 'movReviewEvents.recordedBy'])
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('reporting_period'), fn ($query) => $query->where('reporting_period', $request->string('reporting_period')->toString()))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(fn ($query) => $query->where('target_office', 'like', "%{$search}%")
                    ->orWhere('activity_name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->latest('id')->paginate(10)->withQueryString()
            ->through(fn (ConservationReportSubmission $submission) => $this->submissionData($submission));

        $availableTargetOffices = $this->pambAccess->scopeQuery(ConservationReportSubmission::query(), $request->user())
            ->whereNotNull('target_office')->distinct()->orderBy('target_office')->pluck('target_office');

        return Inertia::render('ConservationReports/Index', [
            'workflow' => $config,
            'submissions' => $submissions,
            'protectedAreas' => $this->pambAccess->isPamo($request->user())
                ? ProtectedArea::query()->whereKey($request->user()->protected_area_id)->orderBy('name')->get(['id', 'name', 'short_name'])
                : ProtectedArea::query()->orderBy('name')->get(['id', 'name', 'short_name']),
            'targetOffices' => $availableTargetOffices,
            'filters' => $request->only(['search', 'protected_area_id', 'reporting_period', 'document_type']),
        ]);
    }

    public function store(Request $request, string $workflow): RedirectResponse
    {
        $config = $this->workflow($workflow);
        $validated = $request->validate($this->reportRules($config, requireMov: true, activityName: $request->string('activity_name')->toString()), $this->attachmentMessages($request->string('document_type')->toString()));
        $validated['target_office'] = $this->resolvedTargetOffice($request, $validated['target_office'] ?? null);
        $this->assertScopedWorkflow($request, $workflow, $validated['target_office'], $validated['protected_area_id'] ?? null);
        $validated = $this->storeMov($request, $validated);
        $submission = ConservationReportSubmission::create([...$validated, 'workflow_key' => $workflow, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        if ($submission->mov_file_path && $request->user()) {
            $this->pambMov->recordUpload($submission, $request->user());
        }
        return back()->with('success', 'Conservation report successfully added.');
    }

    public function update(Request $request, string $workflow, ConservationReportSubmission $submission): RedirectResponse
    {
        $config = $this->workflow($workflow);
        $this->ensureWorkflow($workflow, $submission);
        $validated = $request->validate($this->reportRules($config, $submission->document_type, activityName: $request->string('activity_name')->toString()), $this->attachmentMessages($request->string('document_type')->toString() ?: $submission->document_type));
        $validated['target_office'] = $this->resolvedTargetOffice($request, $validated['target_office'] ?? $submission->target_office);
        $this->assertScopedWorkflow($request, $workflow, $validated['target_office'], $validated['protected_area_id'] ?? $submission->protected_area_id);
        $oldPath = $submission->mov_file_path;
        $newPath = null;
        $replaceOld = $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) {
                $validated = $this->storeMov($request, $validated);
                $newPath = $validated['mov_file_path'] ?? null;
            }
            $submission->update([...$validated, ...($newPath ? ['mov_processing_status' => null, 'mov_submitted_at' => null, 'mov_submitted_by' => null, 'mov_reviewed_at' => null, 'mov_reviewed_by' => null, 'mov_review_remarks' => null] : []), 'updated_by' => $request->user()?->id]);
            if ($newPath && $request->user()) {
                $this->pambMov->recordUpload($submission, $request->user());
            }
        } catch (\Throwable $exception) {
            if ($newPath) $this->attachments->delete($newPath);
            throw $exception;
        }
        if ($replaceOld && $oldPath) $this->attachments->delete($oldPath);
        return back()->with('success', 'Conservation report successfully updated.');
    }

    public function destroy(string $workflow, ConservationReportSubmission $submission): RedirectResponse
    {
        $this->ensureWorkflow($workflow, $submission);
        $path = $submission->mov_file_path;
        $submission->delete();
        if ($path) $this->attachments->delete($path);
        return back()->with('success', 'Conservation report deleted.');
    }

    public function showMov(string $workflow, ConservationReportSubmission $submission): BinaryFileResponse
    {
        $this->ensureWorkflow($workflow, $submission);
        return $this->attachments->response('conservation-report', $submission, 'mov');
    }

    /** @param array<string, mixed> $config */
    private function reportRules(array $config, ?string $legacyDocumentType = null, bool $requireMov = false, ?string $activityName = null): array
    {
        $activityDocuments = $config['activity_documents'] ?? [];
        $documents = array_values(array_unique(array_filter([
            ...array_merge(...array_values($activityDocuments ?: ['default' => $config['documents'] ?? []])),
            $legacyDocumentType,
        ])));
        $allowedDocuments = $activityName && array_key_exists($activityName, $activityDocuments) ? $activityDocuments[$activityName] : $documents;

        return [
            'protected_area_id' => ['nullable', 'exists:protected_areas,id'],
            'target_office' => ['nullable', 'string', 'max:255'],
            'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', Rule::in(array_values(array_unique([...$allowedDocuments, $legacyDocumentType])))],
            'reporting_period' => ['nullable', 'string', Rule::in($config['periods'] ?? [])],
            'date_conducted' => $this->pambCompliance->isMeeting($config['key'] ?? null)
                ? ['required', 'date']
                : ['nullable', 'string', 'max:255'],
            'date_accomplished' => $this->pambCompliance->isMeeting($config['key'] ?? null)
                ? ['nullable', 'date', 'after_or_equal:date_conducted']
                : ['nullable', 'date'],
            'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.self::PRIMARY_ATTACHMENT_MAX_KB],
            'remarks' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    private function attachmentMessages(?string $documentType): array
    {
        $label = match ($documentType) {
            'Minutes' => 'Minutes',
            'Reso', 'Resolution' => 'Resolution',
            default => null,
        };

        $messages = [
            'date_accomplished.after_or_equal' => 'Date Accomplished must be on or after Date Conducted.',
        ];

        return $label ? [...$messages,
            'mov.required' => "A {$label} attachment is required.",
            'mov.max' => "The {$label} attachment must not exceed 100 MB.",
        ] : [...$messages,
            'mov.required' => 'A report attachment / MOV is required.',
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ];
    }

    /** @param array<string, mixed> $validated */
    private function storeMov(Request $request, array $validated): array
    {
        unset($validated['mov']);
        if ($request->hasFile('mov')) {
            $file = $request->file('mov');
            $validated['mov_file_name'] = $file->getClientOriginalName();
            $validated['mov_file_path'] = $this->attachments->store($file, 'conservation-report');
        }

        return $validated;
    }

    private function deleteMov(ConservationReportSubmission $submission): void
    {
        if ($submission->mov_file_path) {
            $this->attachments->delete($submission->mov_file_path);
        }
    }

    /** @return array<string, mixed> */
    private function workflow(string $key): array
    {
        if ($workflow = $this->workflows->find($key)) {
            return $workflow;
        }

        $module = ModuleDefinition::query()->generic()->where('code', $key)->firstOrFail();
        $period = match ($module->reporting_frequency) {
            'weekly' => ['Weekly'], 'monthly' => ['Monthly'], 'quarterly' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            'semestral' => ['1st Semester', '2nd Semester'], 'annual' => ['Annual'], 'custom' => ['Custom'], default => [],
        };

        return [
            'key' => $module->code, 'label' => $module->name, 'description' => $module->description,
            'period_field' => 'reporting_period', 'period_label' => 'Reporting Period', 'periods' => $period,
            'activities' => ['General Report'], 'documents' => ['Progress Report', 'Final Report'],
            'activity_documents' => ['General Report' => ['Progress Report', 'Final Report']],
            'days_complied_field' => 'days_complied', 'penro_delay_field' => 'penro_delay',
            'deadline_mode' => $module->deadline_mode, 'default_deadline_days' => $module->default_deadline_days,
            'allow_deadline_override' => $module->allow_deadline_override,
            'module_definition_code' => $module->code,
        ];
    }

    private function ensureWorkflow(string $workflow, ConservationReportSubmission $submission): void
    {
        $this->workflow($workflow);
        abort_unless($submission->workflow_key === $workflow, 404);
        if ($user = request()->user()) {
            $submission->loadMissing('protectedArea');
            abort_unless($this->pambAccess->canView($user, $submission), 403);
            abort_unless(! $this->pambAccess->isCenro($user) || $this->pambCompliance->isMeeting($workflow), 403);
        }
    }

    private function assertScopedWorkflow(Request $request, string $workflow, ?string $targetOffice, mixed $protectedAreaId = null): void
    {
        $user = $request->user();
        if (! $user) return;
        if ($this->pambAccess->isCenro($user)) {
            abort_unless($this->pambCompliance->isMeeting($workflow), 403);
            $organization = app(OrganizationalAccessService::class);
            abort_unless($targetOffice !== null && $organization->normalizeOffice($targetOffice) === $organization->normalizeOffice($user->office_designated), 403);

            if ($protectedAreaId !== null) {
                $scopedSubmission = new ConservationReportSubmission([
                    'protected_area_id' => (int) $protectedAreaId,
                    'target_office' => $targetOffice,
                ]);
                $scopedSubmission->setRelation('protectedArea', ProtectedArea::query()->find($protectedAreaId));
                abort_unless($this->pambAccess->canView($user, $scopedSubmission), 403);
            }
        }
        if ($this->pambAccess->isPamo($user)) {
            abort_unless($user->protected_area_id !== null && (int) $user->protected_area_id === (int) $protectedAreaId, 403);
        }
    }

    private function resolvedTargetOffice(Request $request, ?string $submittedTargetOffice): ?string
    {
        $user = $request->user();
        $organization = app(OrganizationalAccessService::class);

        // CENRO scope is an authenticated organizational assignment, never a
        // client-controlled form value. This also supports the create form,
        // which intentionally does not ask the operator to re-enter it.
        return $user && $this->pambAccess->isCenro($user)
            ? $organization->normalizeOffice($user->office_designated)
            : $organization->normalizeOffice($submittedTargetOffice);
    }

    /** @return array<string, mixed> */
    private function submissionData(ConservationReportSubmission $submission): array
    {
        $directPenro = app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission);

        return [
            ...collect($submission->toArray())->except(['mov_file_path', 'mov_file_name'])->all(),
            'mov' => $this->attachments->descriptor('conservation-report', $submission, 'mov'),
            'mov_url' => $submission->mov_file_path ? $this->attachments->url('conservation-report', $submission, 'mov') : null,
            'submission_origin' => $directPenro ? 'PENRO' : 'CENRO',
            'cenro_release_applicable' => ! $directPenro,
            'mov_processing' => $this->pambMov->present($submission),
        ];
    }
}
