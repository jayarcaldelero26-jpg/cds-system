<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use App\Services\DateConductedRangeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

abstract class StandardAReportSubmissionController extends Controller
{
    protected const PRIMARY_ATTACHMENT_MAX_KB = 102400;

    /** @var class-string<Model> */
    protected string $modelClass;
    protected string $page;
    protected string $routePrefix;
    protected string $storageFolder;
    protected string $label;
    protected bool $dateConductedRangesEnabled = false;
    public function __construct(
        private readonly ProtectedAttachmentService $attachments,
        private readonly OrganizationalAccessService $organization,
        private readonly DateConductedRangeService $dateConductedRanges,
        private readonly CurrentDocumentReplacementService $documents,
        private readonly ReportDocumentAdapterResolver $documentAdapters,
    ) {}

    public function index(Request $request): Response
    {
        $model = $this->modelClass;
        if ($request->filled('protected_area_id')) {
            $this->organization->assertCanAccessProtectedArea($request->user(), $request->input('protected_area_id'));
        }
        $submissions = $this->organization->scopeProtectedAreaQuery($model::query(), $request->user())
            ->with('protectedArea:id,name,short_name')
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('semester'), fn ($query) => $query->where('semester', $request->input('semester')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(fn ($query) => $query->where('target_office', 'like', "%{$search}%")
                    ->orWhere('activity_name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Model $submission) => $this->submissionData($submission));

        return Inertia::render($this->page, [
            'submissions' => $submissions,
            'protectedAreas' => $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name']),
            ...app(SubmissionFormScopeService::class)->options($request->user()),
            'filters' => $request->only(['protected_area_id', 'semester', 'search']),
            'moduleLabel' => $this->label,
            'routePrefix' => $this->routePrefix,
            'dateConductedRangesEnabled' => $this->dateConductedRangesEnabled,
            'workflowConfig' => ['activity_required' => true, 'document_type_required' => false, 'period_required' => true, 'date_conducted_required' => true, 'date_accomplished_required' => true],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $validated = $request->validate($this->rules(requireMov: true), [
            'mov.required' => 'A primary report attachment is required.',
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        $this->organization->assertCanUseOptionalProtectedArea($request->user(), $validated['protected_area_id'] ?? null);
        $validated = $this->prepareDateConductedPayload($request, $validated);
        $file = $request->file('mov');
        unset($validated['mov']);
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;
        $model = $this->modelClass;
        $submission = $model::create($validated);
        try {
            $adapter = $this->documentAdapters->resolve($this->attachmentSource(), $submission, 'mov');
            $this->documents->replaceUsingAdapter($submission, $this->attachmentSource(), 'mov', $file, $adapter, 'mov', (int) $request->user()->id,
                fn (Model $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                $request->input('remarks'), [], 'UPLOAD');
        } catch (Throwable $exception) {
            $submission->delete();
            throw $exception;
        }

        return back()->with('success', "{$this->label} report submission successfully added.");
    }

    public function update(Request $request, int $reportSubmission): RedirectResponse
    {
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $submission = $this->findSubmission($reportSubmission, $request->user());
        $this->organization->assertCanAccessProtectedArea($request->user(), $submission->protected_area_id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($submission);
        $validated = $request->validate($this->rules($submission->document_type), [
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        $this->organization->assertCanUseOptionalProtectedArea($request->user(), $validated['protected_area_id'] ?? null);
        $validated = $this->prepareDateConductedPayload($request, $validated, $submission);
        unset($validated['mov']);
        $validated['updated_by'] = $request->user()?->id;
        if ($request->hasFile('mov')) {
            $adapter = $this->documentAdapters->resolve($this->attachmentSource(), $submission, 'mov');
            $this->documents->replaceUsingAdapter($submission, $this->attachmentSource(), 'mov', $request->file('mov'), $adapter, 'mov', (int) $request->user()->id,
                fn (Model $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                $request->input('remarks'), $validated, 'REPLACEMENT');
        } else {
            DB::transaction(fn () => $submission->update($validated));
        }

        return back()->with('success', "{$this->label} report submission successfully updated.");
    }

    public function destroy(int $reportSubmission): RedirectResponse
    {
        $submission = $this->findSubmission($reportSubmission, request()->user());
        $this->organization->assertCanAccessProtectedArea(request()->user(), $submission->protected_area_id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($submission);
        $path = $submission->mov_file_path;
        DB::transaction(fn () => $submission->delete());
        if ($path) $this->attachments->delete($path);

        return back()->with('success', "{$this->label} report submission successfully deleted.");
    }

    public function showMov(int $reportSubmission): HttpResponse
    {
        $submission = $this->findSubmission($reportSubmission, request()->user());
        $this->organization->assertCanAccessProtectedArea(request()->user(), $submission->protected_area_id);
        return $this->attachments->response($this->attachmentSource(), $submission, 'mov');
    }

    private function rules(?string $legacyDocumentType = null, bool $requireMov = false): array
    {
        $documentTypes = array_values(array_unique(array_filter(['Final Report', 'Progress Report', $legacyDocumentType])));

        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'target_office' => ['required', 'string', 'max:255'],
            'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', Rule::in($documentTypes)],
            'semester' => ['required', Rule::in(['1st Semester', '2nd Semester'])],
            'date_conducted' => ['nullable', 'string', 'max:255'],
            'date_conducted_ranges' => ['required', 'array', 'min:1'],
            'date_conducted_ranges.*' => ['array'],
            'date_conducted_ranges.*.from' => ['required', 'date_format:Y-m-d'],
            'date_conducted_ranges.*.to' => ['nullable', 'date_format:Y-m-d'],
            'date_accomplished' => ['required', 'date'],
            'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.self::PRIMARY_ATTACHMENT_MAX_KB],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function findSubmission(int $id, \App\Models\User $user): Model
    {
        $model = $this->modelClass;
        $submission = $model::query()->findOrFail($id);
        $this->organization->assertCanAccessProtectedArea($user, $submission->getAttribute('protected_area_id'));

        return $submission;
    }


    private function submissionData(Model $submission): array
    {
        $data = collect($submission->toArray())->except(['mov_file_path', 'mov_file_name'])->all();
        $data['mov'] = $this->attachments->descriptor($this->attachmentSource(), $submission, 'mov');
        $data['mov_url'] = $submission->mov_file_path ? $this->attachments->url($this->attachmentSource(), $submission, 'mov') : null;
        $directPenro = app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission);
        $data['submission_origin'] = $directPenro ? 'PENRO' : 'CENRO';
        $data['cenro_release_applicable'] = ! $directPenro;
        if ($this->dateConductedRangesEnabled) {
            $data['date_conducted_display'] = $this->dateConductedRanges->display($submission->date_conducted_ranges, $submission->date_conducted);
            $data['date_conducted_ranges'] = $submission->date_conducted_ranges;
        }
        return $data;
    }

    private function prepareDateConductedPayload(Request $request, array $validated, ?Model $existing = null): array
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        if ($this->dateConductedRangesEnabled) {
            $dates->assertDateConductedRanges($request->input('date_conducted_ranges'), $existing?->getAttribute('date_conducted_ranges'), $existing?->getAttribute('date_conducted'));
        } else {
            $dates->assertNotFuture($validated['date_conducted'] ?? null, 'date_conducted', 'Date Conducted', $existing?->getAttribute('date_conducted'));
        }
        $dates->assertNotFuture($validated['date_accomplished'] ?? null, 'date_accomplished', 'Date Accomplished', $existing?->getAttribute('date_accomplished'));

        return $this->dateConductedRangesEnabled ? $this->dateConductedRanges->applyToPayload($validated, $request->input('date_conducted_ranges')) : $validated;
    }

    protected function attachmentSource(): string
    {
        return $this->routePrefix === 'bams.report-submissions' ? 'bams-report' : 'imea-report';
    }
}
