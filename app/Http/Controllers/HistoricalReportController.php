<?php

namespace App\Http\Controllers;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\HistoricalReports\HistoricalReportCatalog;
use App\Services\HistoricalReports\HistoricalDateGapProjector;
use App\Services\HistoricalReports\ConservationHistoricalEvidenceProjection;
use App\Services\HistoricalReports\EngpHistoricalEvidenceProjection;
use App\Services\HistoricalReports\HistoricalReportEncoder;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Support\LocalNavigationTrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class HistoricalReportController extends Controller
{
    public function index(Request $request, HistoricalReportCatalog $catalog, SubmissionTrackingService $sourceLinks): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Admin/Settings/HistoricalReports', [
            'catalog' => $catalog->for($user),
            'records' => $this->recentRecords($user, $request, $sourceLinks),
            'filters' => $request->only(['search', 'source', 'year']),
            'canEncode' => $user->can('historical-reports.encode'),
            'success' => $request->session()->get('historical_record'),
        ]);
    }

    public function preview(Request $request, HistoricalReportCatalog $catalog, ConservationReportWorkflowRegistry $conservation, EngpReportWorkflowRegistry $engp): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $month = filter_var($request->input('reporting_month'), FILTER_VALIDATE_INT);
        $year = filter_var($request->input('reporting_year'), FILTER_VALIDATE_INT);
        $workflow = collect($catalog->for($user)['workflows'])->firstWhere('id', $request->input('workflow_id'));
        abort_unless($workflow, 404);

        $record = $this->previewRecord($request, $workflow, $conservation, $engp);
        $deadline = $record?->getAttribute('deadline_submission');
        $basis = match ($workflow['kind']) {
            'engp' => 'Reporting period and deadline from the active ENGP workflow registry.',
            'conservation' => app(PambComplianceCalculator::class)->isMeeting($workflow['workflow_key'])
                ? 'Existing PAMB rule: Date Accomplished, with Date Conducted fallback.'
                : 'Existing workflow requirement and its configured date basis.',
            'pa' => 'Existing Standard A rule: 15 conservation working days after Date Accomplished.',
            'maintenance', 'ipaf-management' => 'Existing module rule: 7 conservation working days after Date Accomplished.',
            'aws' => 'Existing AWS report deadline calculation based on Date Accomplished.',
            'revenue' => 'Financial deadline entered under the existing monthly revenue report contract.',
            'management-plans' => 'Existing management plan requirement and its configured date basis.',
            default => 'Existing source module deadline calculation.',
        };

        $period = match ($workflow['kind']) {
            'engp' => $request->input('reporting_year') && $request->input('period_key')
                ? data_get($engp->period((string) $workflow['workflow_key'], (int) $request->input('reporting_year'), (string) $request->input('period_key')), 'label')
                : null,
            'conservation' => $request->input('reporting_period'),
            'pa' => $request->input('semester'),
            'maintenance' => $request->input('quarter'),
            'aws' => $request->input('reporting_period') ?: $request->input('report_period_type'),
            'revenue' => $month !== false && $month >= 1 && $month <= 12 && $year !== false && $year >= 2000 && $year <= 2100
                ? CarbonImmutable::create($year, $month, 1)->format('F Y')
                : null,
            'management-plans' => $request->input('semester'),
            default => null,
        };

        return response()->json([
            'deadline' => $deadline ? CarbonImmutable::parse($deadline)->toDateString() : null,
            'deadline_basis' => $basis,
            'period' => $period,
            'evidence' => 'Original receipt, handoff, and completion dates are stored as manually recorded historical evidence. They do not create custody events.',
        ]);
    }

    public function periods(Request $request, HistoricalReportCatalog $catalog, EngpReportWorkflowRegistry $engp): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workflow = collect($catalog->for($user)['workflows'])->firstWhere('id', $request->input('workflow_id'));
        abort_unless($workflow && $workflow['kind'] === 'engp', 404);
        $data = Validator::make($request->all(), ['reporting_year' => ['required', 'integer', 'between:2000,2100']])->validate();

        return response()->json(['periods' => $engp->periods($workflow['workflow_key'], (int) $data['reporting_year'])]);
    }

    public function store(Request $request, HistoricalReportEncoder $encoder): RedirectResponse
    {
        $entry = $encoder->encode($request);
        $entry['url'] = route('settings.historical-reports.show', [$entry['source'], $entry['id']]);

        return redirect()->route('settings.historical-reports.index')->with('historical_record', $entry);
    }

    public function show(Request $request, string $source, int $record, HistoricalDateGapProjector $dateGaps, SubmissionTrackingService $sourceLinks): Response|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (LocalNavigationTrace::eligible($request)) LocalNavigationTrace::activate($request);
        $entry = HistoricalReportOrigin::query()->where('source', $source)->where('source_record_id', $record)->firstOrFail();
        $modelClass = $this->modelForSource($source);
        abort_unless($modelClass, 404);
        $query = $modelClass::query();
        if ($source !== 'engp') $query->with('protectedArea');
        $model = $query->findOrFail($record);
        abort_unless((bool) $model->getAttribute('is_historical'), 404);
        $this->assertCanView($user, $source, $model, $entry);

        $projection = match ($source) {
            'conservation' => app(ConservationHistoricalEvidenceProjection::class)->project($model, $entry),
            'engp' => app(EngpHistoricalEvidenceProjection::class)->project($model, $entry),
            default => null,
        };
        if ($projection) $model = $projection['record'];
        $milestones = $projection['milestones'] ?? data_get($entry->original_milestones, 'milestones', []);
        $evaluation = $projection['timeliness_evaluation'] ?? $this->historicalTimeliness($model, $milestones);
        $attachments = $this->attachmentDescriptors($source, $model);
        $historicalDateGaps = $dateGaps->project($source, $model, $entry);
        $detail = collect($model->attributesToArray())
            ->except(['mov_file_path', 'mov_file_name', 'report_file_path', 'report_file_name', 'attachments', 'created_by', 'updated_by'])
            ->all();
        $detail['historical_date_gaps'] = $historicalDateGaps;
        if ($source === 'engp' && $projection) {
            $detail['days_complied'] = $projection['days_complied'];
            $detail['timeliness_rating'] = $projection['timeliness'];
            $detail['submission_status'] = $projection['submission_status'];
            $detail['monitoring_status'] = $projection['monitoring_status'];
            $detail['historical_evidence'] = $projection['historical_evidence'];
        }

        $props = [
            'record' => [
                'source' => $source,
                'source_id' => (int) $model->getKey(),
                'workflow_key' => $entry->workflow_key,
                'label' => $this->moduleLabel($source, $entry->workflow_key),
                'office' => $source === 'engp' ? $model->office : $model->target_office,
                'protected_area' => $model->protectedArea?->name,
                'period' => $this->periodLabel($model, $source),
                'created_at' => $model->created_at?->toIso8601String(),
                'encoded_at' => $entry->encoded_at?->toIso8601String(),
                'encoded_by' => User::query()->whereKey($entry->encoded_by)->value('name') ?: 'Unavailable',
                'original_milestones' => $milestones,
                'historical_date_gaps' => $historicalDateGaps,
                'timeline' => collect($milestones)->map(fn (mixed $date, string $key): array => [
                    'key' => $key,
                    'label' => match ($key) {
                        'date_report_released_cenro' => 'Original CENRO report release date',
                        'date_received_penro' => 'Original PENRO received date',
                        'date_endorsed_regional' => 'Original regional endorsement date',
                        default => str($key)->replace('_', ' ')->title()->toString(),
                    },
                    'date' => filled($date) ? (string) $date : null,
                    'provenance' => 'Manually recorded original date',
                ])->values()->all(),
                'activity_timeline' => $this->activityTimeline($historicalDateGaps),
                'timeliness' => $evaluation,
                'attachments' => $attachments,
                'remarks' => $model->getAttribute('remarks'),
                'module_url' => $this->moduleUrl($source, $model, $sourceLinks),
                'detail' => $detail,
            ],
        ];

        return $request->expectsJson()
            ? response()->json($props)
            : Inertia::render('Admin/Settings/HistoricalReportDetail', $props);
    }

    private function recentRecords(User $user, Request $request, SubmissionTrackingService $sourceLinks): array
    {
        $sourceMap = [
            'conservation' => ConservationReportSubmission::class,
            'engp' => EngpReportSubmission::class,
            'bms' => BmsReportSubmission::class,
            'bams' => BamsReportSubmission::class,
            'imea' => ImeaReportSubmission::class,
            'imea-maintenance' => ImeaFacilityMaintenanceReport::class,
            'aws' => Aws::class,
            'ipaf-management' => IpafManagementReport::class,
            'revenue' => IpafRevenueCollection::class,
            'management-plans' => ManagementPlan::class,
        ];
        $origins = HistoricalReportOrigin::query()
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')->toString()))
            ->latest('encoded_at')->limit(150)->get();
        $rows = [];

        foreach ($origins->groupBy('source') as $source => $sourceOrigins) {
            $modelClass = $sourceMap[$source] ?? null;
            if (! $modelClass) continue;
            $sourceOrigins = $sourceOrigins->filter(fn (HistoricalReportOrigin $origin): bool => $this->canViewSource($user, $source)
                || (int) $origin->encoded_by === (int) $user->getKey());
            if ($sourceOrigins->isEmpty()) continue;
            $query = $modelClass::query()->where('is_historical', true)->whereIn('id', $sourceOrigins->pluck('source_record_id'));
            if ($source !== 'engp') $query->with('protectedArea');
            if ($source === 'engp') {
                $query = app(OrganizationalAccessService::class)->scopeDevelopmentQuery($query, $user);
            } else {
                $query = app(OrganizationalAccessService::class)->scopeProtectedAreaQuery($query, $user);
            }
            $records = $query->get()->keyBy(fn (Model $model): int => (int) $model->getKey());

            foreach ($sourceOrigins as $origin) {
                $record = $records->get((int) $origin->source_record_id);
                if (! $record) continue;
                if ($request->filled('year') && (int) $record->getAttribute('reporting_year') !== (int) $request->input('year')) continue;
                $searchable = mb_strtolower(implode(' ', [
                    $this->moduleLabel($source, $origin->workflow_key),
                    $source === 'engp' ? $record->getAttribute('office') : $record->getAttribute('target_office'),
                    $record->protectedArea?->name,
                    $record->getAttribute('activity_name'),
                    $record->getAttribute('document_type'),
                ]));
                if ($request->filled('search') && ! str_contains($searchable, mb_strtolower($request->string('search')->toString()))) continue;
                $rows[] = [
                    'source' => $source,
                    'id' => (int) $record->getKey(),
                    'label' => $this->moduleLabel($source, $origin->workflow_key),
                    'office' => $source === 'engp' ? $record->getAttribute('office') : $record->getAttribute('target_office'),
                    'protected_area' => $record->protectedArea?->name,
                    'period' => $this->periodLabel($record, $source),
                    'encoded_at' => $origin->encoded_at?->toIso8601String(),
                    'url' => route('settings.historical-reports.show', [$source, $record->getKey()]),
                    'module_url' => $this->moduleUrl($source, $record, $sourceLinks),
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $right['encoded_at'], (string) $left['encoded_at']));
        return array_slice($rows, 0, 50);
    }

    /** @param array<string,mixed> $workflow */
    private function previewRecord(Request $request, array $workflow, ConservationReportWorkflowRegistry $conservation, EngpReportWorkflowRegistry $engp): ?Model
    {
        $attrs = $request->only([
            'workflow_key', 'protected_area_id', 'target_office', 'activity_name', 'document_type', 'reporting_period',
            'date_conducted', 'date_accomplished', 'date_conducted_ranges', 'semester', 'reporting_year', 'period_key',
            'office', 'quarter', 'report_period_type', 'monitoring_period_start', 'monitoring_period_end', 'reporting_month',
            'deadline_submission', 'management_plan_type_id',
        ]);
        $attrs['workflow_key'] = $workflow['workflow_key'];
        $model = match ($workflow['kind']) {
            'conservation' => new ConservationReportSubmission($attrs),
            'engp' => $this->engpPreview($workflow, $attrs, $engp),
            'pa' => $workflow['source'] === 'bms' ? new BmsReportSubmission($attrs) : ($workflow['source'] === 'bams' ? new BamsReportSubmission($attrs) : new ImeaReportSubmission($attrs)),
            'maintenance' => new ImeaFacilityMaintenanceReport($attrs),
            'aws' => new Aws($attrs),
            'ipaf-management' => new IpafManagementReport($attrs),
            'revenue' => new IpafRevenueCollection($attrs),
            'management-plans' => new ManagementPlan($attrs),
            default => null,
        };

        return $model;
    }

    /** @param array<string,mixed> $workflow @param array<string,mixed> $attrs */
    private function engpPreview(array $workflow, array $attrs, EngpReportWorkflowRegistry $engp): EngpReportSubmission
    {
        $year = (int) ($attrs['reporting_year'] ?? 0);
        $periodKey = (string) ($attrs['period_key'] ?? '');
        $period = $year && $periodKey ? $engp->period($workflow['workflow_key'], $year, $periodKey) : null;
        return new EngpReportSubmission([
            'workflow_key' => $workflow['workflow_key'],
            'reporting_year' => $year ?: null,
            'period_key' => $periodKey ?: null,
            'period_label' => $period['label'] ?? null,
            'deadline_submission' => $year && $periodKey ? $engp->deadline($workflow['workflow_key'], $year, $periodKey) : null,
        ]);
    }

    /** @param array<string,string> $milestones */
    private function historicalTimeliness(Model $record, array $milestones): array
    {
        $receipt = $milestones['date_received_penro'] ?? null;
        if (! $receipt) return ['status' => 'N/A', 'reason' => 'No original receipt date was supplied.'];
        $record->setAttribute('date_received_penro', $receipt);
        if (isset($milestones['date_report_released_cenro'])) $record->setAttribute('date_report_released_cenro', $milestones['date_report_released_cenro']);
        if (isset($milestones['date_endorsed_regional'])) $record->setAttribute('date_endorsed_regional', $milestones['date_endorsed_regional']);
        $deadline = $record->getAttribute('deadline_submission');
        $rating = $record->getAttribute('timeliness') ?? $record->getAttribute('timeliness_rating');
        if (! $deadline) return ['status' => 'N/A', 'reason' => 'The current source requirement could not calculate a deadline from the recorded dates.'];
        return [
            'status' => is_string($rating) ? $rating : 'N/A',
            'deadline' => CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE)->toDateString(),
            'receipt_date' => $receipt,
            'basis' => 'Existing source-module calculator with manually recorded original receipt evidence.',
        ];
    }

    private function assertCanView(User $user, string $source, Model $record, HistoricalReportOrigin $entry): void
    {
        abort_unless($this->canViewSource($user, $source) || (int) $entry->encoded_by === (int) $user->getKey(), 403);
        $organization = app(OrganizationalAccessService::class);
        $query = $source === 'engp'
            ? $organization->scopeDevelopmentQuery($record::query(), $user)
            : $organization->scopeProtectedAreaQuery($record::query(), $user);
        abort_unless($query->whereKey($record->getKey())->exists(), 403);
    }

    private function attachmentDescriptors(string $source, Model $record): array
    {
        $attachmentSource = match ($source) {
            'conservation' => ['conservation-report', 'mov'], 'engp' => ['engp-report', 'mov'],
            'bms' => ['bms-report', 'mov'], 'bams' => ['bams-report', 'mov'], 'imea' => ['imea-report', 'mov'],
            'imea-maintenance' => ['imea-maintenance', 'mov'], 'aws' => ['aws', 'report_file'],
            'ipaf-management' => ['ipaf-management', 'mov'], 'revenue' => ['ipaf-revenue', 'mov'],
            'management-plans' => ['management-plan', 'attachments'], default => null,
        };
        if (! $attachmentSource) return [];
        $service = app(ProtectedAttachmentService::class);
        if ($source !== 'management-plans') {
            $descriptor = $service->previewDescriptor($attachmentSource[0], $record, $attachmentSource[1]);
            return $descriptor ? [$descriptor + ['can_preview' => true]] : [];
        }
        $values = $record->getAttribute('attachments') ?? [];
        if (is_string($values)) $values = json_decode($values, true) ?: [];
        return collect($values)->keys()->map(fn ($key) => $service->previewDescriptor('management-plan', $record, (string) $key))
            ->filter()->map(fn (array $descriptor): array => $descriptor + ['can_preview' => true])->values()->all();
    }

    private function periodLabel(Model $record, string $source): ?string
    {
        if ($source === 'engp') return $record->getAttribute('period_label');
        return $record->getAttribute('reporting_period')
            ?: $record->getAttribute('semester')
            ?: $record->getAttribute('quarter')
            ?: (filled($record->getAttribute('reporting_month')) && filled($record->getAttribute('reporting_year'))
                ? CarbonImmutable::create((int) $record->reporting_year, (int) $record->reporting_month, 1)->format('F Y')
                : null);
    }

    private function moduleLabel(string $source, ?string $workflow): string
    {
        return match ($source) {
            'conservation' => app(ConservationReportWorkflowRegistry::class)->find((string) $workflow)['label'] ?? 'Conservation Report',
            'engp' => app(EngpReportWorkflowRegistry::class)->find((string) $workflow)['label'] ?? 'ENGP Report',
            'bms' => 'BMS Report', 'bams' => 'BAMS Report', 'imea' => 'IMEA Report',
            'imea-maintenance' => 'IMEA Facility Maintenance', 'aws' => 'AWS Report',
            'ipaf-management' => 'Management of IPAF', 'revenue' => 'Revenue Collection',
            'management-plans' => 'Management Plans', default => 'Historical Report',
        };
    }

    private function moduleUrl(string $source, Model $record, SubmissionTrackingService $sourceLinks): ?string
    {
        $sourceDefinition = $sourceLinks->source($source);
        $resolver = $sourceDefinition['url'] ?? null;

        return is_callable($resolver) ? (string) $resolver($record) : null;
    }

    /** @param array<string,array<string,mixed>> $gaps
     *  @return list<array{key:string,label:string,date:?string,provenance:string}>
     */
    private function activityTimeline(array $gaps): array
    {
        $items = [];
        $preparation = $gaps['preparation'] ?? [];
        foreach ([
            ['key' => 'date_conducted', 'label' => $preparation['start_label'] ?? 'Date Conducted', 'date' => $preparation['start_date'] ?? null],
            ['key' => 'date_accomplished', 'label' => 'Date Accomplished', 'date' => $preparation['end_date'] ?? null],
        ] as $item) {
            if (! filled($item['date'])) continue;
            $items[] = [...$item, 'provenance' => str_contains($item['label'], 'structured range') ? 'Original structured source ranges' : 'Original source record date'];
        }

        return $items;
    }

    private function modelForSource(string $source): ?string
    {
        return match ($source) {
            'conservation' => ConservationReportSubmission::class, 'engp' => EngpReportSubmission::class,
            'bms' => BmsReportSubmission::class, 'bams' => BamsReportSubmission::class,
            'imea' => ImeaReportSubmission::class, 'imea-maintenance' => ImeaFacilityMaintenanceReport::class,
            'aws' => Aws::class, 'ipaf-management' => IpafManagementReport::class,
            'revenue' => IpafRevenueCollection::class, 'management-plans' => ManagementPlan::class,
            default => null,
        };
    }

    private function canViewSource(User $user, string $source): bool
    {
        $ability = match ($source) {
            'conservation', 'engp', 'ipaf-management', 'revenue' => 'technical-reports.view',
            'bms' => 'bms.view',
            'bams' => 'bams.view',
            'imea', 'imea-maintenance' => 'imea.view',
            'aws' => 'aws.view',
            'management-plans' => 'management-plans.view',
            default => null,
        };
        if (! $ability || ! $user->can($ability)) return false;
        return $source !== 'engp' || app(OrganizationalAccessService::class)->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT);
    }
}
