<?php

namespace App\Http\Controllers;

use App\Models\BmsReportSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BmsReportSubmissionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $validated = $this->storeMov($request, $validated);
        $validated['created_by'] = $request->user()?->id;
        $validated['updated_by'] = $request->user()?->id;

        BmsReportSubmission::create($validated);

        return redirect()->back()->with('success', 'BMS report submission successfully added.');
    }

    public function update(Request $request, BmsReportSubmission $bmsReportSubmission)
    {
        $validated = $request->validate($this->rules($bmsReportSubmission->document_type));

        if ($request->boolean('delete_mov')) {
            $this->deleteMov($bmsReportSubmission);
            $validated['mov_file_name'] = null;
            $validated['mov_file_path'] = null;
        }

        if ($request->hasFile('mov')) {
            $this->deleteMov($bmsReportSubmission);
            $validated = $this->storeMov($request, $validated);
        }

        unset($validated['mov'], $validated['delete_mov']);

        $validated['updated_by'] = $request->user()?->id;
        $bmsReportSubmission->update($validated);

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
        $this->deleteMov($bmsReportSubmission);
        $bmsReportSubmission->update([
            'mov_file_name' => null,
            'mov_file_path' => null,
            'updated_by' => request()->user()?->id,
        ]);

        return redirect()->back()->with('success', 'MOV attachment successfully deleted.');
    }

    private function rules(?string $legacyDocumentType = null): array
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
            'date_report_released_cenro' => ['nullable', 'date'],
            'date_received_penro' => ['nullable', 'date'],
            'date_endorsed_regional' => ['nullable', 'date'],
            'mov' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'delete_mov' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function storeMov(Request $request, array $validated): array
    {
        unset($validated['mov'], $validated['delete_mov']);

        if ($request->hasFile('mov')) {
            $file = $request->file('mov');
            $validated['mov_file_name'] = $file->getClientOriginalName();
            $validated['mov_file_path'] = $file->store('bms-report-movs', 'public');
        }

        return $validated;
    }

    private function deleteMov(BmsReportSubmission $submission): void
    {
        if ($submission->mov_file_path) {
            Storage::disk('public')->delete($submission->mov_file_path);
        }
    }
}
