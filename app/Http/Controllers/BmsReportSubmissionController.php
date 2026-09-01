<?php

namespace App\Http\Controllers;

use App\Models\BmsReportSubmission;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Compliance\ComplianceMovService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BmsReportSubmissionController extends Controller
{
    private const PRIMARY_ATTACHMENT_MAX_KB = 102400;

    public function __construct(private readonly ProtectedAttachmentService $attachments) {}
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(requireMov: true), [
            'mov.required' => 'A primary report attachment is required.',
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        $validated = $this->storeMov($request, $validated);
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        BmsReportSubmission::create($validated);

        return redirect()->back()->with('success', 'BMS report submission successfully added.');
    }

    public function update(Request $request, BmsReportSubmission $bmsReportSubmission)
    {
        $validated = $request->validate($this->rules($bmsReportSubmission->document_type), [
            'mov.max' => 'The report attachment must not exceed 100 MB.',
        ]);
        if (! $request->hasFile('mov') && ! app(ComplianceMovService::class)->hasValidSingleFile($bmsReportSubmission, 'mov_file_path')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $bmsReportSubmission->mov_file_path;
        $newPath = null;
        $replaceOld = $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) {
                $validated = $this->storeMov($request, $validated);
                $newPath = $validated['mov_file_path'] ?? null;
            }
            unset($validated['mov']);
            $validated['updated_by'] = $request->user()?->id;
            $bmsReportSubmission->update($validated);
        } catch (\Throwable $exception) {
            if ($newPath) $this->attachments->delete($newPath);
            throw $exception;
        }
        if ($replaceOld && $oldPath) $this->attachments->delete($oldPath);

        return redirect()->back()->with('success', 'BMS report submission successfully updated.');
    }

    public function destroy(BmsReportSubmission $bmsReportSubmission)
    {
        $this->deleteMov($bmsReportSubmission);
        $bmsReportSubmission->delete();

        return redirect()->back()->with('success', 'BMS report submission successfully deleted.');
    }

    public function destroyMov(BmsReportSubmission $bmsReportSubmission)
    {
        return redirect()->back()->withErrors(['mov' => 'An existing MOV cannot be removed without a replacement.']);
    }

    private function rules(?string $legacyDocumentType = null, bool $requireMov = false): array
    {
        $documentTypes = array_values(array_unique(array_filter(['Final Report', 'Progress Report', $legacyDocumentType])));

        return [
            'protected_area_id' => ['nullable', 'exists:protected_areas,id'],
            'target_office' => ['nullable', 'string', 'max:255'],
            'activity_name' => ['nullable', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', Rule::in($documentTypes)],
            'semester' => ['required', Rule::in(['1st Semester', '2nd Semester'])],
            'date_conducted' => ['nullable', 'string', 'max:255'],
            'date_accomplished' => ['nullable', 'date'],
            'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.self::PRIMARY_ATTACHMENT_MAX_KB],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function storeMov(Request $request, array $validated): array
    {
        unset($validated['mov']);

        if ($request->hasFile('mov')) {
            $file = $request->file('mov');
            $validated['mov_file_name'] = $file->getClientOriginalName();
            $validated['mov_file_path'] = $this->attachments->store($file, 'bms-report');
        }

        return $validated;
    }

    private function deleteMov(BmsReportSubmission $submission): void
    {
        if ($submission->mov_file_path) {
            $this->attachments->delete($submission->mov_file_path);
        }
    }

}
