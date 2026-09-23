<?php

namespace App\Http\Controllers;

use App\Models\EngpReportSubmission;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Engp\EngpMonitoringStatusResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
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
    public function __construct(private readonly EngpReportWorkflowRegistry $workflows, private readonly ProtectedAttachmentService $attachments, private readonly OrganizationalAccessService $organization, private readonly EngpMonitoringStatusResolver $monitoringStatuses, private readonly SubmissionTrackingService $tracking) {}

    public function index(Request $request, ?string $workflow = null): Response
    {
        if ($workflow === 'summary') {
            $workflow = null;
        }
        $config = $workflow ? $this->workflows->find($workflow) : null;
        abort_if($workflow && ! $config, 404);
        $year = $this->resolveYear($request->input('year') ?? $request->input('reporting_year'));
        $query = $this->organization->scopeDevelopmentQuery(EngpReportSubmission::query(), $request->user())->when($workflow, fn ($q) => $q->where('workflow_key', $workflow));
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
        $existingYears = $this->organization->scopeDevelopmentQuery(EngpReportSubmission::query(), $request->user())
            ->select('reporting_year')->distinct()->pluck('reporting_year')->all();
        $years = $this->workflows->availableYears($existingYears, $year);
        $periodsByYear = $config
            ? collect($years)->mapWithKeys(fn (int $candidateYear): array => [(string) $candidateYear => $this->workflows->periods($workflow, $candidateYear)])->all()
            : [];
        $offices = collect($config['offices'] ?? $this->allOffices())
            ->filter(fn (string $office): bool => $this->organization->canUseDevelopmentOffice($request->user(), $office))
            ->values()
            ->all();

        return Inertia::render('Engp/Index', [
            'workflow' => $workflow,
            'workflowConfig' => $config,
            'workflows' => $this->workflows->all(),
            'submissions' => $rows,
            'year' => $year,
            'periods' => $config ? $this->workflows->periods($workflow, $year) : [],
            'periodsByYear' => $periodsByYear,
            'years' => $years,
            'offices' => $offices,
            'filters' => [...$request->only(['workflow', 'office', 'year', 'period_key', 'status', 'search']), 'year' => $year],
            'summary' => $workflow ? null : $this->summary($year, $request->user()),
            'summaryRows' => $workflow ? [] : $this->organization->scopeDevelopmentQuery(EngpReportSubmission::query(), $request->user())->with('releaseEvents')->where('reporting_year', $year)->where('workflow_key', '!=', 'weekly_accomplishment')->latest('id')->get()->map(fn (EngpReportSubmission $row) => $this->data($row))->values(),
        ]);
    }

    public function store(Request $request, string $workflow): RedirectResponse
    {
        $config = $this->workflows->find($workflow);
        abort_unless($config, 404);
        $this->rejectRoutingFields($request);
        $validated = $this->validateData($request, $workflow, $config, false);
        abort_unless($this->organization->canUseDevelopmentOffice($request->user(), $validated['office']), 403);
        $record = $this->findSubmissionForPeriod($workflow, $validated) ?? new EngpReportSubmission;
        return $this->persist($request, $record, $validated, $workflow, $config, 'ENGP report saved.');
    }

    public function update(Request $request, string $workflow, EngpReportSubmission $engpReportSubmission): RedirectResponse
    {
        abort_unless($this->organization->canViewDevelopmentRecord($request->user(), $engpReportSubmission), 403);
        $config = $this->workflows->find($workflow);
        abort_unless($config && $engpReportSubmission->workflow_key === $workflow, 404);
        $this->rejectRoutingFields($request);
        $this->tracking->assertMutable($engpReportSubmission);
        $validated = $this->validateData($request, $workflow, $config, true);
        return $this->persist($request, $engpReportSubmission, $validated, $workflow, $config, 'ENGP report updated.');
    }

    public function destroy(string $workflow, EngpReportSubmission $engpReportSubmission): RedirectResponse
    {
        abort_unless($this->organization->canViewDevelopmentRecord(request()->user(), $engpReportSubmission), 403);
        abort_unless($this->workflows->find($workflow) && $engpReportSubmission->workflow_key === $workflow, 404);
        $this->tracking->assertMutable($engpReportSubmission);
        $path = $engpReportSubmission->mov_file_path;
        $engpReportSubmission->delete();
        if ($path) $this->attachments->delete($path);
        return back()->with('success', 'ENGP report deleted.');
    }

    public function mov(string $workflow, EngpReportSubmission $engpReportSubmission)
    {
        abort_unless($this->organization->canViewDevelopmentRecord(request()->user(), $engpReportSubmission), 403);
        abort_unless($this->workflows->find($workflow) && $engpReportSubmission->workflow_key === $workflow, 404);
        return $this->attachments->response('engp-report', $engpReportSubmission, 'mov');
    }

    private function validateData(Request $request, string $workflow, array $config, bool $editing): array
    {
        $year = $request->integer('reporting_year') ?: CarbonImmutable::now('Asia/Manila')->year;
        $periodKeys = collect($this->workflows->periods($workflow, $year))->pluck('key')->all();
        return $request->validate([
            'office' => ['required', Rule::in($config['offices'])],
            'section_name' => ['nullable', 'string', 'max:255'],
            'reporting_year' => ['required', 'integer', 'between:2000,2100'],
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
        $oldPath = $record->exists ? $record->mov_file_path : null;
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
            $oldPath = $existing->mov_file_path;
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
        if ($newPath && $oldPath && $newPath !== $oldPath) {
            $this->attachments->delete($oldPath);
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
        $monitoringStatus = $this->monitoringStatuses->resolve($record->deadline_submission?->toDateString(), $record->date_received_penro?->toDateString());
        $releaseEvents = $record->releaseEvents->map(fn ($event) => ['period_component' => $event->period_component, 'component_label' => $event->component_label, 'date_report_released_cenro' => $event->date_report_released_cenro?->toDateString()])->values();
        $mov = $record->mov_file_path
            ? $this->attachments->descriptor('engp-report', $record, 'mov')
            : ($externalUrl ? ['name' => 'External MOV reference', 'mime_type' => null, 'type' => null, 'size' => null, 'url' => $externalUrl, 'external' => true] : null);
        return [...$data,
            'workflow_label' => $record->workflow()['label'] ?? 'ENGP Report',
            'monitoring_status_key' => $monitoringStatus['key'],
            'monitoring_status' => $monitoringStatus['label'],
            'record_source' => 'Actual encoded submission',
            'submission_matched' => true,
            'date_released_cenro' => $releaseEvents->pluck('date_report_released_cenro')->filter()->sort()->last(),
            'release_events' => $releaseEvents,
            'mov_status' => $mov ? 'Recorded' : 'Not yet recorded',
            'mov' => $mov,
        ];
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private function summary(int $year, \App\Models\User $user): array
    {
        return collect($this->workflows->keys())
            ->reject(fn (string $key): bool => $key === 'weekly_accomplishment')
            ->map(function (string $key) use ($year, $user): array {
                $query = $this->organization->scopeDevelopmentQuery(EngpReportSubmission::query(), $user);

                return [
                    'workflow_key' => $key,
                    'label' => $this->workflows->find($key)['label'],
                    'records' => $query->where('workflow_key', $key)->where('reporting_year', $year)->count(),
                ];
            })->values()->all();
    }

    private function allOffices(): array
    {
        return collect($this->workflows->all())->flatMap(fn (array $workflow) => $workflow['offices'])->unique()->values()->all();
    }

    private function resolveYear(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);

        return $year !== false && $year >= 2000 && $year <= 2100
            ? (int) $year
            : CarbonImmutable::now('Asia/Manila')->year;
    }
}
