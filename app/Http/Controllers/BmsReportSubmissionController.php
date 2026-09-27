<?php

namespace App\Http\Controllers;

use App\Models\BmsReportSubmission;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Compliance\ComplianceMovService;
use App\Services\DateConductedRangeService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BmsReportSubmissionController extends Controller
{
    private const PRIMARY_ATTACHMENT_MAX_KB = 102400;

    public function __construct(
        private readonly ProtectedAttachmentService $attachments,
        private readonly OrganizationalAccessService $organization,
        private readonly DateConductedRangeService $dateConductedRanges,
        private readonly CurrentDocumentReplacementService $documents,
        private readonly ReportDocumentAdapterResolver $documentAdapters,
    ) {}
    public function store(Request $request)
    {
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $validated = $request->validate($this->rules(requireMov: true), [
            'mov.required' => 'A primary report attachment is required.',
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        $this->organization->assertCanUseOptionalProtectedArea($request->user(), $validated['protected_area_id'] ?? null);
        $validated = $this->dateConductedRanges->applyToPayload($validated, $request->input('date_conducted_ranges'));
        unset($validated['mov']);
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;
        $submission = new BmsReportSubmission($validated);
        $submission->saveOrFail();
        try {
            $adapter = $this->documentAdapters->resolve('bms-report', $submission, 'mov');
            $this->documents->replaceUsingAdapter($submission, 'bms', 'mov', $request->file('mov'), $adapter, 'mov', (int) $request->user()->id,
                fn ($record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                $request->input('remarks'), [], 'UPLOAD');
        } catch (\Throwable $exception) {
            $submission->delete();
            throw $exception;
        }

        return redirect()->back()->with('success', 'BMS report submission successfully added.');
    }

    public function update(Request $request, BmsReportSubmission $bmsReportSubmission)
    {
        $this->organization->assertCanAccessProtectedArea($request->user(), $bmsReportSubmission->protected_area_id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($bmsReportSubmission);
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $validated = $request->validate($this->rules($bmsReportSubmission->document_type), [
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        $this->organization->assertCanUseOptionalProtectedArea($request->user(), $validated['protected_area_id'] ?? null);
        $validated = $this->dateConductedRanges->applyToPayload($validated, $request->input('date_conducted_ranges'));
        if (! $request->hasFile('mov') && ! app(ComplianceMovService::class)->hasValidSingleFile($bmsReportSubmission, 'mov_file_path')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        unset($validated['mov']);
        $validated['updated_by'] = $request->user()?->id;
        if ($request->hasFile('mov')) {
            $attributes = $validated;
            unset($attributes['mov_file_path'], $attributes['mov_file_name']);
            $adapter = $this->documentAdapters->resolve('bms-report', $bmsReportSubmission, 'mov');
            $this->documents->replaceUsingAdapter(
                $bmsReportSubmission,
                'bms',
                'mov',
                $request->file('mov'),
                $adapter,
                'mov',
                (int) $request->user()->id,
                fn ($record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                $request->input('remarks'),
                $attributes,
            );
        } else {
            $bmsReportSubmission->update($validated);
        }

        return redirect()->back()->with('success', 'BMS report submission successfully updated.');
    }

    public function destroy(BmsReportSubmission $bmsReportSubmission)
    {
        $this->organization->assertCanAccessProtectedArea(request()->user(), $bmsReportSubmission->protected_area_id);
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($bmsReportSubmission);
        $path = $bmsReportSubmission->mov_file_path;
        app(\App\Services\Reports\ReportTrackingReferenceLifecycle::class)
            ->deleteSource($bmsReportSubmission, fn () => $bmsReportSubmission->delete());
        // Keep the protected file until the database deletion has committed.
        if ($path) $this->attachments->delete($path);

        return redirect()->back()->with('success', 'BMS report submission successfully deleted.');
    }

    public function destroyMov(BmsReportSubmission $bmsReportSubmission)
    {
        $this->organization->assertCanAccessProtectedArea(request()->user(), $bmsReportSubmission->protected_area_id);
        return redirect()->back()->withErrors(['mov' => 'An existing MOV cannot be removed without a replacement.']);
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

}
