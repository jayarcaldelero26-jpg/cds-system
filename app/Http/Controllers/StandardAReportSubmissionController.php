<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceMovService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

abstract class StandardAReportSubmissionController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelClass;
    protected string $page;
    protected string $routePrefix;
    protected string $storageFolder;
    protected string $label;

    public function index(Request $request): Response
    {
        $model = $this->modelClass;
        $submissions = $model::query()
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
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['protected_area_id', 'semester', 'search']),
            'moduleLabel' => $this->label,
            'routePrefix' => $this->routePrefix,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules(requireMov: true));
        $newPath = null;
        try {
            if ($request->hasFile('mov')) {
                $file = $request->file('mov');
                $newPath = $file->store($this->storageFolder, 'public');
                if (! is_string($newPath)) {
                    throw new RuntimeException('The MOV could not be stored.');
                }
                $validated['mov_file_name'] = $file->getClientOriginalName();
                $validated['mov_file_path'] = $newPath;
            }
            unset($validated['mov'], $validated['delete_mov']);
            $validated['created_by'] = $request->user()?->id;
            $validated['updated_by'] = $request->user()?->id;
            $model = $this->modelClass;
            DB::transaction(fn () => $model::create($validated));
        } catch (Throwable $exception) {
            if ($newPath) Storage::disk('public')->delete($newPath);
            throw $exception;
        }

        return back()->with('success', "{$this->label} report submission successfully added.");
    }

    public function update(Request $request, int $reportSubmission): RedirectResponse
    {
        $submission = $this->findSubmission($reportSubmission);
        $validated = $request->validate($this->rules($submission->document_type));
        if (! $request->hasFile('mov') && ($request->boolean('delete_mov') || ! app(ComplianceMovService::class)->hasValidSingleFile($submission, 'mov_file_path'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $submission->mov_file_path;
        $newPath = null;
        $removeOld = $request->boolean('delete_mov') || $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) {
                $file = $request->file('mov');
                $newPath = $file->store($this->storageFolder, 'public');
                if (! is_string($newPath)) throw new RuntimeException('The MOV could not be stored.');
                $validated['mov_file_name'] = $file->getClientOriginalName();
                $validated['mov_file_path'] = $newPath;
            } elseif ($request->boolean('delete_mov')) {
                $validated['mov_file_name'] = null;
                $validated['mov_file_path'] = null;
            }
            unset($validated['mov'], $validated['delete_mov']);
            $validated['updated_by'] = $request->user()?->id;
            DB::transaction(fn () => $submission->update($validated));
        } catch (Throwable $exception) {
            if ($newPath) Storage::disk('public')->delete($newPath);
            throw $exception;
        }
        if ($removeOld && $oldPath) Storage::disk('public')->delete($oldPath);

        return back()->with('success', "{$this->label} report submission successfully updated.");
    }

    public function destroy(int $reportSubmission): RedirectResponse
    {
        $submission = $this->findSubmission($reportSubmission);
        $path = $submission->mov_file_path;
        DB::transaction(fn () => $submission->delete());
        if ($path) Storage::disk('public')->delete($path);

        return back()->with('success', "{$this->label} report submission successfully deleted.");
    }

    public function showMov(int $reportSubmission): BinaryFileResponse
    {
        $submission = $this->findSubmission($reportSubmission);
        abort_unless($submission->mov_file_path && Storage::disk('public')->exists($submission->mov_file_path), 404);

        return response()->file(Storage::disk('public')->path($submission->mov_file_path));
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
            'date_report_released_cenro' => ['nullable', 'date'],
            'date_received_penro' => ['nullable', 'date'],
            'date_endorsed_regional' => ['nullable', 'date'],
            'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'delete_mov' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function findSubmission(int $id): Model
    {
        $model = $this->modelClass;
        return $model::query()->findOrFail($id);
    }

    private function submissionData(Model $submission): array
    {
        $data = $submission->toArray();
        $data['mov_url'] = $submission->mov_file_path ? route("{$this->routePrefix}.mov", ['reportSubmission' => $submission->id]) : null;
        return $data;
    }
}
