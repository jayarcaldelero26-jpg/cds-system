<?php

namespace App\Services;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Read-only calendar projections of persisted report submissions. */
final class CalendarMovEventService
{
    private ?Collection $moduleDefinitions = null;
    public function __construct(
        private readonly ProtectedAttachmentService $attachments,
        private readonly ConservationReportWorkflowRegistry $conservationWorkflows,
        private readonly EngpReportWorkflowRegistry $engpWorkflows,
    ) {}

    /** @return list<array{key:string,label:string}> */
    public function modules(User $user): array
    {
        return collect($this->sources())
            ->filter(fn (array $source): bool => $user->can($source['ability']))
            ->map(fn (array $source, string $key): array => ['key' => $key, 'label' => $source['label']])
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function events(User $user, CarbonImmutable $month, ?string $module = null, ?int $protectedAreaId = null): Collection
    {
        return $this->eventsBetween($user, $month->startOfMonth(), $month->endOfMonth(), $module, $protectedAreaId);
    }

    /** @return array{months:array<string,array{submitted_movs:int,modules:list<array{key:string,label:string,count:int}>,days:array<string,list<array{source_type:string}>>}>,overview:array{submitted_movs:int,active_modules:int,months_with_submissions:int}} */
    public function yearSummary(User $user, CarbonImmutable $year, ?string $module = null, ?int $protectedAreaId = null): array
    {
        $months = collect(range(1, 12))->mapWithKeys(fn (int $month): array => [str_pad((string) $month, 2, '0', STR_PAD_LEFT) => ['submitted_movs' => 0, 'modules' => [], 'days' => []]])->all();
        $activeModules = [];

        collect($this->sources())
            ->filter(fn (array $source, string $key): bool => ($module === null || $module === $key) && $user->can($source['ability']))
            ->each(function (array $source, string $key) use (&$months, &$activeModules, $year, $protectedAreaId): void {
                if ($protectedAreaId !== null && $source['protected_area'] === null) {
                    return;
                }

                $query = $source['model']::query()
                    ->whereBetween($source['date'], [$year->startOfYear()->toDateString(), $year->endOfYear()->toDateString()]);
                if ($source['protected_area'] !== null && $protectedAreaId !== null) {
                    $query->where($source['protected_area'], $protectedAreaId);
                }

                $columns = [$source['date']];
                if ($key === 'conservation-reports') {
                    $columns[] = 'workflow_key';
                }
                $query->get($columns)->each(function (Model $record) use (&$months, &$activeModules, $source, $key): void {
                    $date = $this->dateString($record->getAttribute($source['date']));
                    if ($date === null) {
                        return;
                    }
                    $month = substr($date, 5, 2);
                    $day = substr($date, 8, 2);
                    $months[$month]['submitted_movs']++;
                    $months[$month]['modules'][$key] = ($months[$month]['modules'][$key] ?? 0) + 1;
                    $definition = $this->definitionFor($key, $record);
                    $months[$month]['days'][$day][] = ['source_type' => $key, 'program_area' => $definition?->program_area?->value];
                    $activeModules[$key] = true;
                });
            });

        foreach ($months as &$summary) {
            $summary['modules'] = collect($summary['modules'])
                ->map(fn (int $count, string $key): array => ['key' => $key, 'label' => $this->sources()[$key]['label'], 'count' => $count])
                ->sortBy('label')->values()->all();
        }
        unset($summary);

        return [
            'months' => $months,
            'overview' => [
                'submitted_movs' => array_sum(array_column($months, 'submitted_movs')),
                'active_modules' => count($activeModules),
                'months_with_submissions' => count(array_filter($months, fn (array $summary): bool => $summary['submitted_movs'] > 0)),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eventsBetween(User $user, CarbonImmutable $startDate, CarbonImmutable $endDate, ?string $module = null, ?int $protectedAreaId = null): Collection
    {
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        return collect($this->sources())
            ->filter(fn (array $source, string $key): bool => ($module === null || $module === $key) && $user->can($source['ability']))
            ->flatMap(function (array $source, string $key) use ($start, $end, $protectedAreaId): Collection {
                if ($protectedAreaId !== null && $source['protected_area'] === null) {
                    return collect();
                }

                $query = $source['model']::query()
                    ->whereBetween($source['date'], [$start, $end]);

                if ($source['protected_area'] !== null) {
                    $query->when($protectedAreaId !== null, fn ($query) => $query->where($source['protected_area'], $protectedAreaId))
                        ->with('protectedArea:id,name');
                }
                if ($key === 'management-plans') {
                    $query->with('managementPlanType:id,name,slug');
                }

                return $query->orderBy($source['date'])->orderBy('id')->get()
                    ->map(fn (Model $record): array => $this->normalize($key, $source, $record));
            })
            ->sortBy([['submission_date', 'asc'], ['module', 'asc'], ['title', 'asc']])
            ->values();
    }

    /** @return array<string, array<string, mixed>> */
    private function sources(): array
    {
        return [
            'bms' => $this->source(BmsReportSubmission::class, 'BMS', 'bms.view', 'date_received_penro', 'bms-report', 'mov', 'semester', '/bms'),
            'bams' => $this->source(BamsReportSubmission::class, 'BAMS', 'bams.view', 'date_received_penro', 'bams-report', 'mov', 'semester', '/bams/report-submissions'),
            'imea' => $this->source(ImeaReportSubmission::class, 'IMEA', 'imea.view', 'date_received_penro', 'imea-report', 'mov', 'semester', '/imea/report-submissions'),
            'imea-maintenance' => $this->source(ImeaFacilityMaintenanceReport::class, 'IMEA Facility Maintenance', 'imea.view', 'date_received_penro', 'imea-maintenance', 'mov', 'quarter', '/imea/maintenance-reports'),
            'aws' => $this->source(Aws::class, 'Automated Weather Station', 'aws.view', 'date_received_penro', 'aws', 'report_file', 'semester', '/aws'),
            'conservation-reports' => $this->source(ConservationReportSubmission::class, 'Conservation Reports', 'technical-reports.view', 'date_received_penro', 'conservation-report', 'mov', 'reporting_period', null),
            'engp' => $this->source(EngpReportSubmission::class, 'ENGP', 'technical-reports.view', 'date_received_penro', 'engp-report', 'mov', 'period_label', null, null),
            'ipaf' => $this->source(IpafManagementReport::class, 'IPAF', 'technical-reports.view', 'date_received_penro', 'ipaf-management', 'mov', null, '/ipaf'),
            'revenue' => $this->source(IpafRevenueCollection::class, 'Revenue', 'technical-reports.view', 'date_received_penro', 'ipaf-revenue', 'mov', null, '/ipaf'),
            'management-plans' => $this->source(ManagementPlan::class, 'Management Plans', 'management-plans.view', 'date_received_penro', 'management-plan', '0', 'semester', '/management-plans'),
        ];
    }

    /** @return array<string, mixed> */
    private function source(string $model, string $label, string $ability, string $date, string $attachmentSource, string $attachmentKey, ?string $period, ?string $detail, ?string $protectedArea = 'protected_area_id'): array
    {
        return [
            'model' => $model,
            'label' => $label,
            'ability' => $ability,
            'date' => $date,
            'attachment_source' => $attachmentSource,
            'attachment_key' => $attachmentKey,
            'period' => $period,
            'detail' => $detail,
            'protected_area' => $protectedArea,
        ];
    }

    /** @return array<string, mixed> */
    private function normalize(string $key, array $source, Model $record): array
    {
        $protectedArea = $source['protected_area'] !== null ? $record->getRelation('protectedArea') : null;
        $office = $record->getAttribute('target_office') ?? $record->getAttribute('office');
        $module = $source['label'];
        $title = $record->getAttribute('activity_name') ?? $record->getAttribute('title') ?? $module;
        $period = $source['period'] ? $record->getAttribute($source['period']) : null;
        $dateAccomplished = $record->getAttribute('date_accomplished');
        $submissionDate = $record->getAttribute($source['date']);
        $timeliness = $record->getAttribute('timeliness') ?? $record->getAttribute('timeliness_rating');
        $status = $record->getAttribute('submission_status') ?? $record->getAttribute('status');
        $detailUrl = $source['detail'];
        $definition = $this->definitionFor($key, $record);

        if ($key === 'conservation-reports') {
            $workflow = $this->conservationWorkflows->find((string) $record->getAttribute('workflow_key'));
            $module = $definition?->name ?? $source['label'];
            $title = $definition?->name ?? $workflow['label'] ?? $title;
            $detailUrl = route('conservation-reports.index', ['workflow' => $record->getAttribute('workflow_key')]);
        } elseif ($key === 'engp') {
            $workflow = $this->engpWorkflows->find((string) $record->getAttribute('workflow_key'));
            $title = $workflow['label'] ?? $title;
            $detailUrl = route('engp-reports.index', ['workflow' => $record->getAttribute('workflow_key')]);
        } elseif ($key === 'revenue') {
            $period = CarbonImmutable::create((int) $record->getAttribute('reporting_year'), (int) $record->getAttribute('reporting_month'), 1)->format('F Y');
        } elseif ($key === 'management-plans') {
            $module = 'Management Plans';
            $title = $record->managementPlanType?->name ?? $record->getAttribute('plan_type') ?? $title;
        }

        $attachment = $this->attachment($key, $source, $record);

        return [
            'id' => $record->getKey(),
            'source_type' => $key,
            'source_key' => $key.':'.$record->getKey(),
            'module' => $module,
            'program_area' => $definition?->program_area?->value,
            'title' => $title,
            'source_name' => $protectedArea?->name ?? $office,
            'protected_area_id' => $protectedArea?->getKey(),
            'protected_area_name' => $protectedArea?->name,
            'office' => $office,
            'reporting_period' => $period,
            'submission_date' => $this->dateString($submissionDate),
            'date_accomplished' => $this->dateString($dateAccomplished),
            'timeliness' => $this->available($timeliness),
            'status' => $this->available($status),
            'remarks' => $record->getAttribute('remarks') ?? $record->getAttribute('recommendations'),
            'attachment' => $attachment,
            'detail_url' => $detailUrl,
        ];
    }

    /** @return array{exists:bool,name:?string,url:?string} */
    private function attachment(string $key, array $source, Model $record): array
    {
        $attachmentKey = $source['attachment_key'];
        if ($key === 'management-plans') {
            $attachmentKey = array_key_first($record->getAttribute('attachments') ?? []) !== null ? '0' : '';
        }

        $descriptor = $attachmentKey !== '' ? $this->attachments->descriptor($source['attachment_source'], $record, $attachmentKey) : null;
        if ($descriptor === null && $key === 'engp') {
            $url = $record->getAttribute('mov_external_url');
            $descriptor = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
                ? ['name' => 'External MOV reference', 'url' => $url]
                : null;
        }

        return ['exists' => $descriptor !== null, 'name' => $descriptor['name'] ?? null, 'url' => $descriptor['url'] ?? null];
    }

    private function dateString(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        return is_string($date) && $date !== '' ? $date : null;
    }

    private function available(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' || in_array(mb_strtolower($value), ['no data', 'no rating'], true) ? null : $value;
    }

    private function definitionFor(string $sourceKey, Model $record): ?ModuleDefinition
    {
        $definitions = $this->moduleDefinitions();
        if ($sourceKey === 'conservation-reports') {
            return $definitions->firstWhere('code', (string) $record->getAttribute('workflow_key'));
        }

        return $definitions->firstWhere('existing_source_key', $sourceKey);
    }

    /** @return Collection<int, ModuleDefinition> */
    private function moduleDefinitions(): Collection
    {
        return $this->moduleDefinitions ??= ModuleDefinition::query()->notRetired()->get(['id', 'code', 'program_area', 'existing_source_key']);
    }
}
