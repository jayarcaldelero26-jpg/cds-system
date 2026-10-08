<?php

namespace App\Http\Controllers;

use App\Models\ManagementPlan;
use App\Models\ManagementPlanType;
use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceMovService;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class ManagementPlanController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments, private readonly OrganizationalAccessService $organization, private readonly CurrentDocumentReplacementService $documents, private readonly ReportDocumentAdapterResolver $documentAdapters) {}

    public function index(Request $request): Response
    {
        return Inertia::render('ManagementPlans/Index', [
            'planTypes' => ManagementPlanType::query()
                ->where('is_active', true)
                ->with(['profile.protectedArea:id,name'])
                ->withCount('managementPlans')
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description'])
                ->map(function (ManagementPlanType $type) use ($request): ManagementPlanType {
                    if ($type->profile && ! $this->organization->canAccessProtectedAreaRecord($request->user(), $type->profile)) $type->unsetRelation('profile');
                    return $type;
                })
                ->map(fn (ManagementPlanType $type) => $this->typeData($type)),
            'selectedPlanType' => null,
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('management_plan_types', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $baseSlug = Str::slug($data['name']) ?: 'plan';
        $slug = $baseSlug;
        $suffix = 2;
        while (ManagementPlanType::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $type = ManagementPlanType::create([
            ...$data,
            'slug' => $slug,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('management-plans.types.show', $type->slug)->with('success', 'Management plan created successfully.');
    }

    public function tracker(Request $request, ManagementPlanType $managementPlanType): Response
    {
        abort_unless($managementPlanType->is_active, 404);
        $managementPlanType->load(['profile.protectedArea:id,name'])->loadCount('managementPlans');
        $search = trim((string) $request->string('search'));
        $filters = [
            'search' => $search,
            'protected_area_id' => $request->integer('protected_area_id') ?: null,
            'semester' => $request->string('semester')->toString(),
        ];

        return Inertia::render('ManagementPlans/Index', [
            'selectedPlanType' => $this->typeData($managementPlanType),
            'planProfile' => $managementPlanType->profile && $this->organization->canAccessProtectedAreaRecord($request->user(), $managementPlanType->profile)
                ? ManagementPlanProfileController::profileData($managementPlanType->profile, $managementPlanType)
                : null,
            'managementPlans' => $this->organization->scopeProtectedAreaQuery(ManagementPlan::query()->where('management_plan_type_id', $managementPlanType->id), $request->user())
                ->with('protectedArea:id,name')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('target_office', 'like', "%{$search}%")
                        ->orWhere('activity_name', 'like', "%{$search}%")
                        ->orWhere('document_type', 'like', "%{$search}%")
                        ->orWhere('semester', 'like', "%{$search}%")
                        ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                }))
                ->when($filters['protected_area_id'], fn ($query, $id) => $query->where('protected_area_id', $id))
                ->when($filters['semester'], fn ($query, $semester) => $query->where('semester', $semester))
                ->latest('id')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (ManagementPlan $plan) => $this->planData($plan, $managementPlanType)),
            'filters' => $filters,
            'protectedAreas' => $this->protectedAreaOptions($request->user()),
            ...app(SubmissionFormScopeService::class)->options($request->user()),
            'planTypes' => [],
            'approvalStatuses' => ManagementPlanProfileController::APPROVAL_STATUSES,
            'documentCategories' => ManagementPlanProfileController::DOCUMENT_CATEGORIES,
        ]);
    }

    public function createReport(ManagementPlanType $managementPlanType): Response
    {
        abort_unless($managementPlanType->is_active, 404);

        return Inertia::render('ManagementPlans/Create', [
            'managementPlanType' => $this->typeData($managementPlanType),
            'protectedAreas' => $this->protectedAreaOptions(request()->user()),
            ...app(SubmissionFormScopeService::class)->options(request()->user()),
        ]);
    }

    public function storeReport(Request $request, ManagementPlanType $managementPlanType): RedirectResponse
    {
        abort_unless($managementPlanType->is_active, 404);
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $this->rejectRoutingFields($request);
        $data = $request->validate($this->reportRules(requireAttachments: true));
        $this->assertNoFutureActualDates($data);
        $this->organization->assertCanAccessProtectedArea($request->user(), $data['protected_area_id']);
        $files = $request->file('attachments', []);
        $plan = DB::transaction(fn () => ManagementPlan::query()->create([
            ...collect($data)->except('attachments')->toArray(),
            'management_plan_type_id' => $managementPlanType->id,
            'plan_type' => $managementPlanType->name,
            'attachments' => [],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));
        $createdSlots = [];
        try {
            foreach ($files as $index => $file) {
                $slot = (string) $index;
                $adapter = $this->documentAdapters->resolve('management-plan', $plan, $slot);
                $this->documents->replaceUsingAdapter($plan, 'management-plan', $slot, $file, $adapter, $slot, (int) $request->user()->id,
                    fn (ManagementPlan $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                    null, [], 'UPLOAD');
                $createdSlots[] = $slot;
            }
        } catch (Throwable $exception) {
            foreach ($createdSlots as $slot) {
                try { $plan->refresh(); $this->documents->clearUsingAdapter($plan, 'management-plan', $slot, $this->documentAdapters->resolve('management-plan', $plan, $slot), (int) $request->user()->id, static fn (): null => null, 'Failed multi-document upload cleanup'); } catch (Throwable $cleanupFailure) { report($cleanupFailure); }
            }
            $plan->forceDelete();
            throw $exception;
        }

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report added successfully.');
    }

    public function editReport(ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): Response
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $this->organization->assertCanAccessProtectedArea(request()->user(), $managementPlan->protected_area_id);

        return Inertia::render('ManagementPlans/Edit', [
            'managementPlanType' => $this->typeData($managementPlanType),
            'managementPlan' => $this->planData($managementPlan->load('protectedArea:id,name'), $managementPlanType),
            'protectedAreas' => $this->protectedAreaOptions(request()->user()),
            ...app(SubmissionFormScopeService::class)->options(request()->user()),
        ]);
    }

    public function updateReport(Request $request, ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): RedirectResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $managementPlan = $this->authorizedPlan($request, $managementPlan->id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($managementPlan);
        $this->rejectRoutingFields($request);
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $data = $request->validate([
            ...$this->reportRules(),
            'removed_attachments' => ['nullable', 'array'],
            'removed_attachments.*' => ['string', 'distinct'],
        ]);
        $this->assertNoFutureActualDates($data, $managementPlan);
        $this->organization->assertCanAccessProtectedArea($request->user(), $data['protected_area_id'] ?? $managementPlan->protected_area_id);

        $currentAttachments = array_filter($managementPlan->attachments ?? [], fn ($attachment) => $this->attachmentPath($attachment) !== null);
        $attachmentsByKey = collect($currentAttachments)->mapWithKeys(fn ($attachment, $index) => [(string) $index => $attachment]);
        $attachmentsByPath = collect($currentAttachments)->keyBy(fn ($attachment) => $this->attachmentPath($attachment));
        $requestedRemovals = array_values($data['removed_attachments'] ?? []);
        $uploadedAttachments = $request->file('attachments', []);
        $requestedPaths = collect($requestedRemovals)->map(function (string $key) use ($attachmentsByKey, $attachmentsByPath): ?string {
            $attachment = $attachmentsByKey->get($key) ?? $attachmentsByPath->get($key);
            return $attachment ? $this->attachmentPath($attachment) : null;
        })->filter()->values()->all();
        if ($uploadedAttachments === [] && ! app(ComplianceMovService::class)->hasValidAttachments(array_values($currentAttachments), $requestedPaths)) {
            throw ValidationException::withMessages(['attachments' => 'At least one supporting document is required.']);
        }
        $unownedRemovals = array_values(array_filter($requestedRemovals, fn (string $key) => ! $attachmentsByKey->has($key) && ! $attachmentsByPath->has($key)));

        if ($unownedRemovals !== []) {
            throw ValidationException::withMessages(['removed_attachments' => 'One or more selected attachments do not belong to this management plan report.']);
        }

        $requestedSlots = collect($requestedRemovals)->map(function (string $key) use ($attachmentsByKey, $attachmentsByPath) {
            return $attachmentsByKey->has($key) ? $key : $attachmentsByPath->search(fn ($attachment) => $this->attachmentPath($attachment) === $key);
        })->filter(fn ($slot) => $slot !== false)->map(fn ($slot) => (string) $slot)->unique()->values();
        if ($uploadedAttachments !== []) {
            $existingKeys = array_map('intval', array_keys($currentAttachments));
            $nextSlot = $existingKeys === [] ? 0 : max($existingKeys) + 1;
            $formAttributes = [
                ...collect($data)->except(['attachments', 'removed_attachments'])->toArray(),
                'plan_type' => $managementPlanType->name,
                'updated_by' => $request->user()->id,
            ];
            $createdSlots = [];
            try {
                foreach (array_values($uploadedAttachments) as $offset => $file) {
                    $slot = (string) ($nextSlot + $offset);
                    $adapter = $this->documentAdapters->resolve('management-plan', $managementPlan, $slot);
                    $this->documents->replaceUsingAdapter($managementPlan, 'management-plan', $slot, $file, $adapter, $slot, (int) $request->user()->id,
                        fn (ManagementPlan $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                        null, $offset === count($uploadedAttachments) - 1 ? $formAttributes : [], 'UPLOAD');
                    $createdSlots[] = $slot;
                }
            } catch (Throwable $exception) {
                foreach ($createdSlots as $slot) {
                    try { $managementPlan->refresh(); $this->documents->clearUsingAdapter($managementPlan, 'management-plan', $slot, $this->documentAdapters->resolve('management-plan', $managementPlan, $slot), (int) $request->user()->id, static fn (): null => null, 'Failed multi-document upload cleanup'); } catch (Throwable $cleanupFailure) { report($cleanupFailure); }
                }
                throw $exception;
            }

            foreach ($requestedSlots as $slot) {
                $managementPlan->refresh();
                $this->documents->clearUsingAdapter(
                    $managementPlan,
                    'management-plan',
                    $slot,
                    $this->documentAdapters->resolve('management-plan', $managementPlan, $slot),
                    (int) $request->user()->id,
                    fn (ManagementPlan $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                    'Management plan attachment removed',
                );
            }

            return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report updated successfully.');
        }
        $formAttributes = [
            ...collect($data)->except(['attachments', 'removed_attachments'])->toArray(),
            'plan_type' => $managementPlanType->name,
            'updated_by' => $request->user()->id,
        ];
        DB::transaction(fn () => $managementPlan->update($formAttributes));
        foreach ($requestedSlots as $slot) {
            $managementPlan->refresh();
            $this->documents->clearUsingAdapter(
                $managementPlan,
                'management-plan',
                $slot,
                $this->documentAdapters->resolve('management-plan', $managementPlan, $slot),
                (int) $request->user()->id,
                fn (ManagementPlan $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                'Management plan attachment removed',
            );
        }

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report updated successfully.');
    }

    public function destroyReport(Request $request, ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): RedirectResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $managementPlan = $this->authorizedPlan($request, $managementPlan->id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($managementPlan);
        $managementPlan->update(['updated_by' => $request->user()->id]);
        app(\App\Services\Reports\ReportTrackingReferenceLifecycle::class)
            ->deleteSource($managementPlan, fn () => $managementPlan->delete());

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report deleted successfully.');
    }

    public function viewScopedAttachment(ManagementPlanType $managementPlanType, ManagementPlan $managementPlan, string $attachment): HttpResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $this->organization->assertCanAccessProtectedArea(request()->user(), $managementPlan->protected_area_id);

        return $this->attachmentResponse($managementPlan, $attachment);
    }

    public function viewAttachment(ManagementPlan $managementPlan, string $attachment): HttpResponse
    {
        $managementPlan = $this->authorizedPlan(request(), $managementPlan->id);
        return $this->attachmentResponse($managementPlan, $attachment);
    }

    public function legacyEdit(ManagementPlan $managementPlan): RedirectResponse
    {
        abort_unless($managementPlan->managementPlanType, 404);

        return to_route('management-plans.types.reports.edit', [$managementPlan->managementPlanType->slug, $managementPlan]);
    }

    public function summary(): RedirectResponse
    {
        return to_route('management-plans.index');
    }

    private function assertOwnedByType(ManagementPlanType $type, ManagementPlan $plan): void
    {
        abort_unless($type->is_active && $plan->management_plan_type_id === $type->id, 404);
    }

    private function attachmentResponse(ManagementPlan $plan, string $attachment): HttpResponse
    {
        return $this->attachments->response('management-plan', $plan, $attachment);
    }

    private function reportRules(bool $requireAttachments = false): array
    {
        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'target_office' => ['required', 'string', 'max:255'],
            'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::in(['Final Report', 'Progress Report'])],
            'semester' => ['required', 'string', 'in:1st Semester,2nd Semester'],
            'date_conducted' => ['required', 'date'],
            'date_accomplished' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'attachments' => [$requireAttachments ? 'required' : 'nullable', 'array', ...($requireAttachments ? ['min:1'] : [])],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertNoFutureActualDates(array $data, ?ManagementPlan $existing = null): void
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        $dates->assertNotFuture($data['date_conducted'] ?? null, 'date_conducted', 'Date Conducted', $existing?->date_conducted);
        $dates->assertNotFuture($data['date_accomplished'] ?? null, 'date_accomplished', 'Date Accomplished', $existing?->date_accomplished);
    }

    private function rejectRoutingFields(Request $request): void
    {
        $fields = collect(['date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional'])
            ->filter(fn (string $field): bool => $request->exists($field))
            ->values();

        if ($fields->isNotEmpty()) {
            throw ValidationException::withMessages([
                'routing' => 'Routing dates are recorded through Submission Tracking only.',
            ]);
        }
    }

    private function typeData(ManagementPlanType $type): array
    {
        $profile = $type->relationLoaded('profile') ? $type->profile : null;

        return [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'description' => $type->description,
            'management_plans_count' => $type->management_plans_count ?? null,
            'has_profile' => $profile !== null,
            'approval_status' => $profile?->approval_status,
            'completeness_completed' => $profile?->completeness_completed,
            'completeness_total' => $profile?->completeness_total,
        ];
    }

    private function planData(ManagementPlan $plan, ManagementPlanType $type): array
    {
        return [
            'id' => $plan->id,
            'management_plan_type_id' => $type->id,
            'protected_area_id' => $plan->protected_area_id,
            'protected_area_name' => $plan->protectedArea?->name,
            'plan_type' => $type->name,
            'target_office' => $plan->target_office,
            'activity_name' => $plan->activity_name,
            'document_type' => $plan->document_type,
            'semester' => $plan->semester,
            'date_conducted' => $plan->date_conducted,
            'date_accomplished' => $plan->date_accomplished?->toDateString(),
            'date_report_released_cenro' => $plan->date_report_released_cenro?->toDateString(),
            'date_received_penro' => $plan->date_received_penro?->toDateString(),
            'date_endorsed_regional' => $plan->date_endorsed_regional?->toDateString(),
            'deadline_submission' => $plan->deadline_submission,
            'number_days_complied' => $plan->number_days_complied,
            'timeliness' => $plan->timeliness,
            'submission_status' => $plan->submission_status,
            'total_days_delayed_penro' => $plan->total_days_delayed_penro,
            'title' => $plan->title,
            'version' => $plan->version,
            'prepared_year' => $plan->prepared_year,
            'approval_date' => $plan->approval_date?->toDateString(),
            'valid_from' => $plan->valid_from?->toDateString(),
            'valid_until' => $plan->valid_until?->toDateString(),
            'status' => $plan->status,
            'remarks' => $plan->remarks,
            'attachments' => collect($plan->attachments ?? [])->map(function ($attachment, int $index) use ($plan, $type): array {
                $path = $this->attachmentPath($attachment);
                $metadata = is_array($attachment) ? $attachment : [];
                $name = $metadata['original_name'] ?? $metadata['name'] ?? ($path ? basename($path) : 'Attachment');
                $mimeType = $metadata['mime_type'] ?? $metadata['type'] ?? '';
                return ['key' => (string) $index, 'path' => (string) $index, 'original_name' => $name, 'name' => $name, 'mime_type' => $mimeType, 'type' => $mimeType, 'size' => $metadata['size'] ?? null, 'url' => $path ? $this->attachments->url('management-plan', $plan, (string) $index) : null, 'external' => false];
            })->filter(fn (array $attachment) => $attachment['url'] !== null)->values()->all(),
        ];
    }

    private function protectedAreaOptions(?\App\Models\User $user = null): array
    {
        return $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user ?: request()->user(), 'id')->orderBy('name')->get(['id', 'name'])->map(fn (ProtectedArea $area) => ['id' => $area->id, 'name' => $area->name])->all();
    }

    private function authorizedPlan(Request $request, int $id): ManagementPlan
    {
        return $this->organization->scopeProtectedAreaQuery(ManagementPlan::query(), $request->user())->findOrFail($id);
    }

    private function attachmentPath(mixed $attachment): ?string
    {
        $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
        return is_string($path) && $path !== '' ? $path : null;
    }

}
