<?php

namespace App\Http\Controllers;

use App\Models\ManagementPlan;
use App\Models\ManagementPlanType;
use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceMovService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ManagementPlanController extends Controller
{
    public function index(): Response
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
            'planProfile' => $managementPlanType->profile
                ? ManagementPlanProfileController::profileData($managementPlanType->profile, $managementPlanType)
                : null,
            'managementPlans' => $managementPlanType->managementPlans()
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
            'protectedAreas' => $this->protectedAreaOptions(),
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
            'protectedAreas' => $this->protectedAreaOptions(),
        ]);
    }

    public function storeReport(Request $request, ManagementPlanType $managementPlanType): RedirectResponse
    {
        abort_unless($managementPlanType->is_active, 404);
        $data = $request->validate($this->reportRules(requireAttachments: true));
        $newAttachments = [];

        try {
            foreach ($request->file('attachments', []) as $file) {
                $newAttachments[] = $this->storeAttachment($file);
            }

            DB::transaction(fn () => ManagementPlan::create([
                ...collect($data)->except('attachments')->toArray(),
                'management_plan_type_id' => $managementPlanType->id,
                'plan_type' => $managementPlanType->name,
                'attachments' => $newAttachments,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            $this->deleteAttachments($newAttachments);
            throw $exception;
        }

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report added successfully.');
    }

    public function editReport(ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): Response
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);

        return Inertia::render('ManagementPlans/Edit', [
            'managementPlanType' => $this->typeData($managementPlanType),
            'managementPlan' => $this->planData($managementPlan->load('protectedArea:id,name'), $managementPlanType),
            'protectedAreas' => $this->protectedAreaOptions(),
        ]);
    }

    public function updateReport(Request $request, ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): RedirectResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $data = $request->validate([
            ...$this->reportRules(),
            'removed_attachments' => ['nullable', 'array'],
            'removed_attachments.*' => ['string', 'distinct'],
        ]);

        $currentAttachments = array_values(array_filter($managementPlan->attachments ?? [], fn ($attachment) => $this->attachmentPath($attachment) !== null));
        $attachmentsByPath = collect($currentAttachments)->keyBy(fn ($attachment) => $this->attachmentPath($attachment));
        $requestedRemovals = array_values($data['removed_attachments'] ?? []);
        $uploadedAttachments = $request->file('attachments', []);
        if ($uploadedAttachments === [] && ! app(ComplianceMovService::class)->hasValidAttachments($currentAttachments, $requestedRemovals)) {
            throw ValidationException::withMessages(['attachments' => 'At least one supporting document is required.']);
        }
        $unownedRemovals = array_values(array_filter($requestedRemovals, fn (string $path) => ! $attachmentsByPath->has($path)));

        if ($unownedRemovals !== []) {
            throw ValidationException::withMessages(['removed_attachments' => 'One or more selected attachments do not belong to this management plan report.']);
        }

        $retainedAttachments = array_values(array_filter($currentAttachments, fn ($attachment) => ! in_array($this->attachmentPath($attachment), $requestedRemovals, true)));
        $newAttachments = [];

        try {
            foreach ($uploadedAttachments as $file) {
                $newAttachments[] = $this->storeAttachment($file);
            }

            DB::transaction(fn () => $managementPlan->update([
                ...collect($data)->except(['attachments', 'removed_attachments'])->toArray(),
                'plan_type' => $managementPlanType->name,
                'attachments' => [...$retainedAttachments, ...$newAttachments],
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            $this->deleteAttachments($newAttachments);
            throw $exception;
        }

        $this->deleteAttachments($requestedRemovals);

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report updated successfully.');
    }

    public function destroyReport(Request $request, ManagementPlanType $managementPlanType, ManagementPlan $managementPlan): RedirectResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);
        $managementPlan->update(['updated_by' => $request->user()->id]);
        $managementPlan->delete();

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Management plan report deleted successfully.');
    }

    public function viewScopedAttachment(ManagementPlanType $managementPlanType, ManagementPlan $managementPlan, string $attachment): BinaryFileResponse
    {
        $this->assertOwnedByType($managementPlanType, $managementPlan);

        return $this->attachmentResponse($managementPlan, $attachment);
    }

    public function viewAttachment(ManagementPlan $managementPlan, string $attachment): BinaryFileResponse
    {
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

    private function attachmentResponse(ManagementPlan $plan, string $attachment): BinaryFileResponse
    {
        abort_unless(ctype_digit($attachment), 404);
        $path = $this->attachmentPath(($plan->attachments ?? [])[(int) $attachment] ?? null);
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path));
    }

    private function reportRules(bool $requireAttachments = false): array
    {
        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'target_office' => ['required', 'string', 'max:255'],
            'activity_name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::in(['Final Report', 'Progress Report'])],
            'semester' => ['required', 'string', 'in:1st Semester,2nd Semester'],
            'date_conducted' => ['nullable', 'string', 'max:255'],
            'date_accomplished' => ['nullable', 'date'],
            'date_report_released_cenro' => ['nullable', 'date'],
            'date_received_penro' => ['nullable', 'date'],
            'date_endorsed_regional' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'attachments' => [$requireAttachments ? 'required' : 'nullable', 'array', ...($requireAttachments ? ['min:1'] : [])],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'],
        ];
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
                return [...$metadata, 'path' => $path, 'original_name' => $name, 'name' => $name, 'mime_type' => $mimeType, 'type' => $mimeType, 'url' => $path ? route('management-plans.types.reports.attachments.view', [$type->slug, $plan, $index]) : null];
            })->filter(fn (array $attachment) => $attachment['path'] !== null)->values()->all(),
        ];
    }

    private function protectedAreaOptions(): array
    {
        return ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map(fn (ProtectedArea $area) => ['id' => $area->id, 'name' => $area->name])->all();
    }

    private function storeAttachment(UploadedFile $file): array
    {
        $path = $file->store('management-plans', 'public');
        if (! is_string($path)) {
            throw new RuntimeException('The attachment could not be stored.');
        }
        return ['original_name' => $file->getClientOriginalName(), 'stored_name' => basename($path), 'path' => $path, 'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'size' => $file->getSize()];
    }

    private function attachmentPath(mixed $attachment): ?string
    {
        $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
        return is_string($path) && $path !== '' ? $path : null;
    }

    private function deleteAttachments(array $attachments): void
    {
        $paths = array_values(array_filter(array_map(fn ($attachment) => $this->attachmentPath($attachment), $attachments)));
        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
