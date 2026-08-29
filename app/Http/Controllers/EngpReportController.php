<?php

namespace App\Http\Controllers;

use App\Models\EngpReportSubmission;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class EngpReportController extends Controller
{
    public function __construct(private readonly EngpReportWorkflowRegistry $workflows, private readonly ProtectedAttachmentService $attachments) {}

    public function index(Request $request, ?string $workflow = null): Response
    {
        if ($workflow === 'summary') {
            $workflow = null;
        }
        $config = $workflow ? $this->workflows->find($workflow) : null;
        abort_if($workflow && ! $config, 404);
        $year = $request->integer('year') ?: 2026;
        $query = EngpReportSubmission::query()->when($workflow, fn ($q) => $q->where('workflow_key', $workflow));
        $query->when($request->filled('office'), fn ($q) => $q->where('office', $request->input('office')))
            ->when($request->filled('period_key'), fn ($q) => $q->where('period_key', $request->input('period_key')))
            ->when($request->filled('status'), function ($q) use ($request): void {
                $status = (string) $request->input('status');
                if ($status === 'Report Submitted') $q->whereNotNull('date_received_penro');
                if ($status === 'Report Not Yet Submitted') $q->whereNull('date_received_penro')->whereDate('deadline_submission', '<', CarbonImmutable::now('Asia/Manila')->toDateString());
                if ($status === 'Within Allowable Preparation Period') $q->whereNull('date_received_penro')->whereDate('deadline_submission', '>=', CarbonImmutable::now('Asia/Manila')->toDateString());
            })
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = trim((string) $request->input('search'));
                $q->where(fn ($inner) => $inner->where('office', 'like', "%{$search}%")->orWhere('activity_name', 'like', "%{$search}%")->orWhere('section_name', 'like', "%{$search}%"));
            });
        $rows = $query->with('releaseEvents')->where('reporting_year', $year)->latest('id')->paginate(15)->withQueryString()->through(fn (EngpReportSubmission $row) => $this->data($row));

        return Inertia::render('Engp/Index', [
            'workflow' => $workflow,
            'workflowConfig' => $config,
            'workflows' => $this->workflows->all(),
            'submissions' => $rows,
            'periods' => $config ? $this->workflows->periods($workflow, $year) : [],
            'offices' => $config['offices'] ?? $this->allOffices(),
            'filters' => $request->only(['workflow', 'office', 'year', 'period_key', 'status', 'search']),
            'summary' => $workflow ? null : $this->summary($year),
            'summaryRows' => $workflow ? [] : EngpReportSubmission::query()->with('releaseEvents')->where('reporting_year', $year)->where('workflow_key', '!=', 'weekly_accomplishment')->latest('id')->get()->map(fn (EngpReportSubmission $row) => $this->data($row))->values(),
        ]);
    }

    public function store(Request $request, string $workflow): RedirectResponse
    {
        $config = $this->workflows->find($workflow);
        abort_unless($config, 404);
        $this->rejectRoutingFields($request);
        $validated = $this->validateData($request, $workflow, $config, false);
        $record = $this->findSubmissionForPeriod($workflow, $validated) ?? new EngpReportSubmission;
        return $this->persist($request, $record, $validated, $workflow, $config, 'ENGP report saved.');
    }

    public function update(Request $request, string $workflow, EngpReportSubmission $engpReportSubmission): RedirectResponse
    {
        $config = $this->workflows->find($workflow);
        abort_unless($config && $engpReportSubmission->workflow_key === $workflow, 404);
        $this->rejectRoutingFields($request);
        $validated = $this->validateData($request, $workflow, $config, true);
        return $this->persist($request, $engpReportSubmission, $validated, $workflow, $config, 'ENGP report updated.');
    }

    public function destroy(string $workflow, EngpReportSubmission $engpReportSubmission): RedirectResponse
    {
        abort_unless($this->workflows->find($workflow) && $engpReportSubmission->workflow_key === $workflow, 404);
        $path = $engpReportSubmission->mov_file_path;
        $engpReportSubmission->delete();
        if ($path) $this->attachments->delete($path);
        return back()->with('success', 'ENGP report deleted.');
    }

    public function mov(string $workflow, EngpReportSubmission $engpReportSubmission)
    {
        abort_unless($this->workflows->find($workflow) && $engpReportSubmission->workflow_key === $workflow, 404);
        return $this->attachments->response('engp-report', $engpReportSubmission, 'mov');
    }

    private function validateData(Request $request, string $workflow, array $config, bool $editing): array
    {
        $year = $request->integer('reporting_year') ?: 2026;
        $periodKeys = collect($this->workflows->periods($workflow, $year))->pluck('key')->all();
        return $request->validate([
            'office' => ['required', Rule::in($config['offices'])],
            'section_name' => ['nullable', 'string', 'max:255'],
            'reporting_year' => ['required', 'integer', 'between:2000,2100', Rule::in([2026])],
            'period_key' => ['required', Rule::in($periodKeys)],
            'mov' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            'mov_external_url' => ['nullable', 'url', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                $scheme = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_SCHEME)) : '';
                if ($scheme !== 'https') {
                    $fail('The external MOV URL must use HTTPS.');
                }
            }],
            'remarks' => ['nullable', 'string'],
        ]) + ['_editing' => $editing];
    }

    private function persist(Request $request, EngpReportSubmission $record, array $validated, string $workflow, array $config, string $message): RedirectResponse
    {
        $wasNew = ! $record->exists;
        unset($validated['_editing'], $validated['mov']);
        $period = $this->workflows->period($workflow, (int) $validated['reporting_year'], $validated['period_key']);
        $validated = [...$validated, 'workflow_key' => $workflow, 'activity_name' => $config['activity'], 'document_type' => $config['document'], 'period_label' => $period['label'], 'deadline_submission' => $this->workflows->deadline($workflow, (int) $validated['reporting_year'], $validated['period_key']), 'updated_by' => $request->user()?->id];
        $newPath = null;
        $save = function (EngpReportSubmission $target) use (&$validated, &$record): void {
            DB::transaction(function () use ($target, $validated): void {
                if ($target->trashed()) $target->restore();
                $target->fill($validated)->save();
            });
            $record = $target;
        };

        try {
            if ($request->hasFile('mov')) {
                $file = $request->file('mov');
                $newPath = $this->attachments->store($file, 'engp-report');
                if (! is_string($newPath)) throw new RuntimeException('The report file could not be stored.');
                $validated['mov_file_path'] = $newPath;
            }
            if (! $record->exists) $validated['created_by'] = $request->user()?->id;
            $save($record);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $wasNew) throw $exception;

            $existing = $this->findSubmissionForPeriod($workflow, $validated);
            if (! $existing) {
                if ($newPath) $this->attachments->delete($newPath);
                throw ValidationException::withMessages(['period_key' => 'A submission already exists for this office and reporting period. Refresh the page and try again.']);
            }

            unset($validated['created_by']);
            try {
                $save($existing);
            } catch (Throwable $fallbackException) {
                if ($newPath) $this->attachments->delete($newPath);
                throw $fallbackException;
            }
        } catch (Throwable $exception) {
            if ($newPath) $this->attachments->delete($newPath);
            throw $exception;
        }
        return back()->with('success', $message);
    }

    private function rejectRoutingFields(Request $request): void
    {
        $fields = collect(['date_received_penro', 'release_events'])
            ->filter(fn (string $field): bool => $request->exists($field))
            ->values();

        if ($fields->isNotEmpty()) {
            throw ValidationException::withMessages([
                'routing' => 'Routing dates are recorded through Submission Tracking only.',
            ]);
        }
    }

    private function findSubmissionForPeriod(string $workflow, array $validated): ?EngpReportSubmission
    {
        return EngpReportSubmission::withTrashed()
            ->where('workflow_key', $workflow)
            ->where('office', $validated['office'])
            ->where('reporting_year', $validated['reporting_year'])
            ->where('period_key', $validated['period_key'])
            ->first();
    }

    private function data(EngpReportSubmission $record): array
    {
        $data = collect($record->toArray())->except(['mov_file_path'])->all();
        $externalUrl = $this->safeExternalUrl($record->mov_external_url);
        $mov = $record->mov_file_path
            ? $this->attachments->descriptor('engp-report', $record, 'mov')
            : ($externalUrl ? ['name' => 'External MOV reference', 'mime_type' => null, 'type' => null, 'size' => null, 'url' => $externalUrl, 'external' => true] : null);
        return [...$data, 'workflow_label' => $record->workflow()['label'] ?? 'ENGP Report', 'release_events' => $record->releaseEvents->map(fn ($event) => ['period_component' => $event->period_component, 'component_label' => $event->component_label, 'date_report_released_cenro' => $event->date_report_released_cenro?->toDateString()])->values(), 'mov' => $mov];
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private function summary(int $year): array
    {
        return collect($this->workflows->keys())->reject(fn (string $key): bool => $key === 'weekly_accomplishment')->map(fn (string $key): array => ['workflow_key' => $key, 'label' => $this->workflows->find($key)['label'], 'records' => EngpReportSubmission::query()->where('workflow_key', $key)->where('reporting_year', $year)->count()])->values()->all();
    }

    private function allOffices(): array
    {
        return collect($this->workflows->all())->flatMap(fn (array $workflow) => $workflow['offices'])->unique()->values()->all();
    }
}
