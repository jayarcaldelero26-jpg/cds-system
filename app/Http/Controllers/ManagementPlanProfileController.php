<?php

namespace App\Http\Controllers;

use App\Models\ManagementPlanProfile;
use App\Models\ManagementPlanType;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ManagementPlanProfileController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments) {}

    public const APPROVAL_STATUSES = [
        'Draft', 'For Technical Review', 'For Revision', 'For PAMB Approval', 'PAMB Approved',
        'For CENRO Endorsement', 'For PENRO Endorsement', 'For RED Endorsement',
        'For BMB / Central Office Review', 'Affirmed', 'For Updating', 'Archived',
    ];

    public const DOCUMENT_CATEGORIES = [
        'main_plan' => 'Main Plan Document',
        'pamb_resolution' => 'PAMB Resolution',
        'consultation' => 'Consultation Documents',
        'endorsement_affirmation' => 'Endorsement / Affirmation Documents',
        'other' => 'Other Supporting Documents',
    ];

    public function store(Request $request, ManagementPlanType $managementPlanType): RedirectResponse
    {
        abort_unless($managementPlanType->is_active, 404);
        $data = $request->validate($this->rules());
        $newDocuments = [];

        try {
            $newDocuments = $this->storeUploads($request);
            DB::transaction(function () use ($request, $managementPlanType, $data, $newDocuments): void {
                $lockedType = ManagementPlanType::query()->lockForUpdate()->findOrFail($managementPlanType->id);
                if (ManagementPlanProfile::query()->where('management_plan_type_id', $lockedType->id)->exists()) {
                    throw ValidationException::withMessages([
                        'profile' => 'This management plan already has an approval profile. Edit the existing plan information instead.',
                    ]);
                }

                ManagementPlanProfile::create([
                    ...$this->persistenceData($data),
                    'management_plan_type_id' => $lockedType->id,
                    'documents' => $newDocuments,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteDocuments($newDocuments);
            throw $exception;
        }

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Plan information saved successfully.');
    }

    public function update(Request $request, ManagementPlanType $managementPlanType, ManagementPlanProfile $profile): RedirectResponse
    {
        $this->assertOwned($managementPlanType, $profile);
        $data = $request->validate([
            ...$this->rules(),
            'removed_document_paths' => ['nullable', 'array'],
            'removed_document_paths.*' => ['string', 'distinct'],
        ]);

        $current = array_filter($profile->documents ?? [], fn ($document) => $this->documentPath($document) !== null);
        $byKey = collect($current)->mapWithKeys(fn ($document, $index) => [(string) $index => $document]);
        $byPath = collect($current)->keyBy(fn ($document) => $this->documentPath($document));
        $requested = array_values($data['removed_document_paths'] ?? []);
        $requestedPaths = collect($requested)->map(function (string $key) use ($byKey, $byPath): ?string {
            $document = $byKey->get($key) ?? $byPath->get($key);
            return $document ? $this->documentPath($document) : null;
        })->filter()->values()->all();
        if (collect($requested)->contains(fn (string $key) => ! $byKey->has($key) && ! $byPath->has($key))) {
            throw ValidationException::withMessages(['removed_document_paths' => 'One or more selected documents do not belong to this management plan.']);
        }

        $retained = array_values(array_filter($current, fn ($document) => ! in_array($this->documentPath($document), $requestedPaths, true)));
        $newDocuments = [];

        try {
            $newDocuments = $this->storeUploads($request);
            DB::transaction(fn () => $profile->update([
                ...$this->persistenceData($data),
                'documents' => [...$retained, ...$newDocuments],
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            $this->deleteDocuments($newDocuments);
            throw $exception;
        }

        $this->deleteDocuments($requestedPaths);

        return to_route('management-plans.types.show', $managementPlanType->slug)->with('success', 'Plan information updated successfully.');
    }

    public function viewDocument(ManagementPlanType $managementPlanType, ManagementPlanProfile $profile, string $document): BinaryFileResponse
    {
        $this->assertOwned($managementPlanType, $profile);
        return $this->attachments->response('management-plan-profile', $profile, $document);
    }

    public static function profileData(ManagementPlanProfile $profile, ManagementPlanType $type): array
    {
        $checklist = collect(ManagementPlanProfile::CHECKLIST_KEYS)->mapWithKeys(fn (string $key) => [$key => ($profile->completeness_checklist[$key] ?? false) === true])->all();
        return [
            'id' => $profile->id,
            'management_plan_type_id' => $type->id,
            'protected_area_id' => $profile->protected_area_id,
            'protected_area_name' => $profile->protectedArea?->name,
            'plan_name' => $profile->plan_name,
            'planning_period_start' => $profile->planning_period_start,
            'planning_period_end' => $profile->planning_period_end,
            'lead_office' => $profile->lead_office,
            'lead_preparer' => $profile->lead_preparer,
            'date_preparation_started' => $profile->date_preparation_started?->toDateString(),
            'twg_constituted' => $profile->twg_constituted,
            'stakeholder_consultation_conducted' => $profile->stakeholder_consultation_conducted,
            'consultation_dates' => $profile->consultation_dates ?? [],
            'completeness_checklist' => $checklist,
            'completeness_completed' => $profile->completeness_completed,
            'completeness_total' => $profile->completeness_total,
            'approval_status' => $profile->approval_status,
            'pamb_resolution_number' => $profile->pamb_resolution_number,
            'pamb_resolution_date' => $profile->pamb_resolution_date?->toDateString(),
            'cenro_endorsement_date' => $profile->cenro_endorsement_date?->toDateString(),
            'penro_endorsement_date' => $profile->penro_endorsement_date?->toDateString(),
            'red_endorsement_date' => $profile->red_endorsement_date?->toDateString(),
            'date_received_bmb' => $profile->date_received_bmb?->toDateString(),
            'denr_affirmation_date' => $profile->denr_affirmation_date?->toDateString(),
            'affirmation_reference' => $profile->affirmation_reference,
            'harmonized_adsdpp' => $profile->harmonized_adsdpp,
            'harmonized_clup' => $profile->harmonized_clup,
            'other_plans_integrated' => $profile->other_plans_integrated,
            'remarks' => $profile->remarks,
            'updated_at' => $profile->updated_at?->toISOString(),
            'documents' => collect($profile->documents ?? [])->map(function ($document, int $index) use ($profile, $type): array {
                $metadata = is_array($document) ? $document : ['path' => $document];
                $path = $metadata['path'] ?? null;
                $name = $metadata['original_name'] ?? $metadata['name'] ?? ($path ? basename($path) : 'Document');
                $mimeType = $metadata['mime_type'] ?? $metadata['type'] ?? '';
                return ['key' => (string) $index, 'path' => (string) $index, 'original_name' => $name, 'name' => $name, 'mime_type' => $mimeType, 'type' => $mimeType, 'size' => $metadata['size'] ?? null, 'url' => $path ? app(ProtectedAttachmentService::class)->url('management-plan-profile', $profile, (string) $index) : null, 'external' => false];
            })->filter(fn (array $document) => $document['url'] !== null)->values()->all(),
        ];
    }

    private function assertOwned(ManagementPlanType $type, ManagementPlanProfile $profile): void
    {
        abort_unless(
            $type->is_active
            && $profile->management_plan_type_id === $type->id
            && $type->profile()->whereKey($profile->getKey())->exists(),
            404
        );
    }

    private function rules(): array
    {
        $checklistKeys = implode(',', ManagementPlanProfile::CHECKLIST_KEYS);
        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plan_name' => ['nullable', 'string', 'max:255'],
            'planning_period_start' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'planning_period_end' => ['nullable', 'integer', 'min:1900', 'max:2200', 'gte:planning_period_start'],
            'lead_office' => ['nullable', 'string', 'max:255'],
            'lead_preparer' => ['nullable', 'string', 'max:255'],
            'date_preparation_started' => ['nullable', 'date'],
            'twg_constituted' => ['nullable', 'boolean'],
            'stakeholder_consultation_conducted' => ['nullable', 'boolean'],
            'consultation_dates' => ['nullable', 'array'],
            'consultation_dates.*' => ['date'],
            'completeness_checklist' => ['required', 'array:'.$checklistKeys],
            'completeness_checklist.*' => ['boolean'],
            'approval_status' => ['required', 'string', 'in:'.implode(',', self::APPROVAL_STATUSES)],
            'pamb_resolution_number' => ['nullable', 'string', 'max:255'],
            'pamb_resolution_date' => ['nullable', 'date'],
            'cenro_endorsement_date' => ['nullable', 'date'],
            'penro_endorsement_date' => ['nullable', 'date'],
            'red_endorsement_date' => ['nullable', 'date'],
            'date_received_bmb' => ['nullable', 'date'],
            'denr_affirmation_date' => ['nullable', 'date'],
            'affirmation_reference' => ['nullable', 'string', 'max:255'],
            'harmonized_adsdpp' => ['nullable', 'string', 'in:Yes,No,Not Applicable'],
            'harmonized_clup' => ['nullable', 'string', 'in:Yes,No,Not Applicable'],
            'other_plans_integrated' => ['nullable', 'string', 'in:Yes,No,Not Applicable'],
            'remarks' => ['nullable', 'string'],
            'document_uploads' => ['nullable', 'array'],
            'document_uploads.*' => ['file', 'mimes:pdf,doc,docx,zip,jpeg,jpg,png', 'max:20480'],
            'document_categories' => ['nullable', 'array'],
            'document_categories.*' => ['string', 'in:'.implode(',', array_keys(self::DOCUMENT_CATEGORIES))],
        ];
    }

    private function persistenceData(array $data): array
    {
        return collect($data)->except(['document_uploads', 'document_categories', 'removed_document_paths'])->toArray();
    }

    private function storeUploads(Request $request): array
    {
        $files = array_values($request->file('document_uploads', []));
        $categories = array_values($request->input('document_categories', []));
        if (count($files) !== count($categories)) {
            throw ValidationException::withMessages(['document_uploads' => 'Each uploaded document must have a valid category.']);
        }

        $stored = [];
        try {
            foreach ($files as $index => $file) {
                $stored[] = $this->storeDocument($file, $categories[$index]);
            }
        } catch (Throwable $exception) {
            $this->deleteDocuments($stored);
            throw $exception;
        }
        return $stored;
    }

    private function storeDocument(UploadedFile $file, string $category): array
    {
        $path = $this->attachments->store($file, 'management-plan-profile');
        if (! is_string($path)) {
            throw new RuntimeException('The supporting document could not be stored.');
        }
        return ['category' => $category, 'original_name' => $file->getClientOriginalName(), 'stored_name' => basename($path), 'path' => $path, 'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'size' => $file->getSize()];
    }

    private function documentPath(mixed $document): ?string
    {
        $path = is_string($document) ? $document : (is_array($document) ? ($document['path'] ?? null) : null);
        return is_string($path) && $path !== '' ? $path : null;
    }

    private function deleteDocuments(array $documents): void
    {
        $paths = array_values(array_filter(array_map(fn ($document) => $this->documentPath($document), $documents)));
        if ($paths !== []) {
            foreach ($paths as $path) $this->attachments->delete($path);
        }
    }
}
