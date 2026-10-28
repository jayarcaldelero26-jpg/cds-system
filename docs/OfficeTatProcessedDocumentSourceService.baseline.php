<?php

namespace App\Services;

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Pamo\PamoNativeSourceRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only inventory of source reports with explicit processing evidence. */
final class OfficeTatProcessedDocumentSourceService
{
    private const PROCESSED_EVENT_KEYS = [
        'forwarded', 'received', 'endorsed', 'recommended', 'approved', 'released',
        'returned_for_correction', 'correction_received',
    ];

    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly PamoNativeSourceRegistry $nativeSources,
        private readonly EngpReportWorkflowRegistry $engpWorkflows,
    ) {}

    /**
     * Return only existing source rows with processing evidence at the assessment cutoff.
     * The assessment supplies the already-authorized participant roster; this service
     * adds a read-only details sidecar and never changes its score or ranking fields.
     *
     * @param array<string,mixed> $assessment
     * @return list<array<string,mixed>>
     */
    public function forAssessment(User $user, array $assessment): array
    {
        $unit = (string) ($assessment['unit'] ?? '');
        abort_unless($user->can('office-tat.view'), 403);
        abort_unless(in_array($unit, $this->organization->effectiveUnits($user), true), 403);
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);

        $unitAssessment = is_array($assessment['office_tat_assessment'] ?? null)
            ? $assessment['office_tat_assessment']
            : [];
        $periodKey = (string) ($unitAssessment['period_key'] ?? $assessment['period_key'] ?? '');
        $cutoffDate = $this->dateValue($unitAssessment['as_of_date'] ?? $assessment['as_of_date'] ?? null);
        if ($periodKey === '' || $cutoffDate === null) return [];
        [$periodStart, $periodEnd] = $this->assessmentBounds($periodKey);
        if ($periodStart === null || $periodEnd === null) return [];
        $cutoff = CarbonImmutable::parse($cutoffDate, BusinessCalendarService::TIMEZONE)->endOfDay();

        $participants = [];
        $assessmentRows = is_array($unitAssessment['rows'] ?? null)
            ? $unitAssessment['rows']
            : (is_array($assessment['rows'] ?? null) ? $assessment['rows'] : []);
        foreach ($assessmentRows as $row) {
            if (! is_array($row) || ! is_string($row['participant_key'] ?? null)) continue;
            $participants[$row['participant_key']] = [
                'participant_key' => $row['participant_key'],
                'name' => (string) ($row['name'] ?? 'Office'),
                'office_binding' => $row['office_binding'] ?? null,
                'details' => [],
            ];
        }
        if ($participants === []) return [];

        if ($unit === 'conservation') {
            $areaIds = [];
            foreach ($participants as $participant) {
                if (preg_match('/^pa:(\d+)$/', $participant['participant_key'], $match)) $areaIds[] = (int) $match[1];
            }
            $this->addConservationSources($participants, array_values(array_unique($areaIds)), $periodKey, $periodStart, $periodEnd, $cutoff);
        } else {
            $this->addDevelopmentSources($user, $participants, $periodKey, $periodStart, $periodEnd, $cutoff);
        }

        return array_values(array_map(static fn (array $participant): array => [
            'participant_key' => $participant['participant_key'],
            'name' => $participant['name'],
            'office_binding' => $participant['office_binding'],
            'obligation_details' => $participant['details'],
        ], array_filter($participants, static fn (array $participant): bool => $participant['details'] !== [])));
    }

    /** @param array<string,array<string,mixed>> $participants @param list<int> $areaIds */
    private function addConservationSources(array &$participants, array $areaIds, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff): void
    {
        if ($areaIds === []) return;
        $records = ConservationReportSubmission::query()->whereIn('protected_area_id', $areaIds);
        $this->excludeHistoricalRows($records, (new ConservationReportSubmission())->getTable());
        $genericRows = $records->get();
        $processed = $this->processedSourceIds('conservation', $genericRows, $cutoff);
        foreach ($genericRows as $record) {
            $id = (int) $record->getKey();
            if (! isset($processed[$id]) || ! $this->belongsToAssessmentPeriod($record, $periodKey, $periodStart, $periodEnd)) continue;
            $participantKey = 'pa:'.(int) $record->protected_area_id;
            if (! isset($participants[$participantKey])) continue;
            $workflow = (string) $record->workflow_key;
            $this->appendRecord($participants[$participantKey], 'conservation', $id, $workflow,
                trim((string) ($record->activity_name ?: $workflow)), (string) $record->activity_name,
                (string) $record->document_type, $record->target_office, $record, $periodKey, $cutoff);
        }

        foreach ($this->nativeSources->all() as $workflow => $source) {
            $modelClass = $source['model'];
            $model = new $modelClass;
            $table = $model->getTable();
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'protected_area_id')) continue;
            $nativeQuery = $modelClass::query()->whereIn('protected_area_id', $areaIds);
            $this->excludeHistoricalRows($nativeQuery, $table);
            $nativeRows = $nativeQuery->get();
            $nativeProcessed = $this->processedSourceIds((string) $source['source'], $nativeRows, $cutoff);
            foreach ($nativeRows as $record) {
                $id = (int) $record->getKey();
                if (! isset($nativeProcessed[$id]) || ! $this->belongsToAssessmentPeriod($record, $periodKey, $periodStart, $periodEnd)) continue;
                $participantKey = 'pa:'.(int) $record->getAttribute('protected_area_id');
                if (! isset($participants[$participantKey])) continue;
                $activity = trim((string) ($record->getAttribute('activity_name') ?? $source['label']));
                $label = $this->engpWorkflows->find((string) $workflow)['label'] ?? (string) $source['label'];
                $this->appendRecord($participants[$participantKey], (string) $source['source'], $id, (string) $workflow,
                    $label, $activity, (string) ($record->getAttribute('document_type') ?? ''),
                    $record->getAttribute('target_office'), $record, $periodKey, $cutoff);
            }
        }
    }

    /** @param array<string,array<string,mixed>> $participants */
    private function addDevelopmentSources(User $user, array &$participants, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff): void
    {
        $officeNames = array_values(array_unique(array_map(
            static fn (array $participant): string => (string) $participant['name'], $participants,
        )));
        if ($officeNames === []) return;

        $query = EngpReportSubmission::query()->whereIn('office', $officeNames);
        $this->excludeHistoricalRows($query, (new EngpReportSubmission())->getTable());
        $this->organization->scopeDevelopmentQuery($query, $user);
        $records = $query->get();
        $processed = $this->processedSourceIds('engp', $records, $cutoff);
        foreach ($records as $record) {
            $id = (int) $record->getKey();
            if (! isset($processed[$id]) || ! $this->belongsToAssessmentPeriod($record, $periodKey, $periodStart, $periodEnd)) continue;
            $participant = collect($participants)->first(fn (array $row): bool => mb_strtolower(trim($row['name'])) === mb_strtolower(trim((string) $record->office)));
            if (! $participant) continue;
            $workflow = (string) $record->workflow_key;
            $definition = $this->engpWorkflows->find($workflow) ?? [];
            $this->appendRecord($participants[$participant['participant_key']], 'engp', $id, $workflow,
                (string) ($definition['label'] ?? $record->activity_name ?? $workflow),
                (string) ($record->activity_name ?? ''), (string) ($record->document_type ?? ''),
                (string) $record->office, $record, $periodKey, $cutoff);
        }
    }

    private function excludeHistoricalRows($query, string $table): void
    {
        if (Schema::hasColumn($table, 'is_historical')) {
            $query->where(fn ($builder) => $builder->whereNull('is_historical')->orWhere('is_historical', false));
        }
    }

    /** @param iterable<Model> $records @return array<int,true> */
    private function processedSourceIds(string $sourceKey, iterable $records, CarbonImmutable $cutoff): array
    {
        $ids = [];
        $processed = [];
        foreach ($records as $record) {
            $id = (int) $record->getKey();
            if ($id < 1) continue;
            $ids[] = $id;
            foreach (['date_received_penro', 'date_report_released_cenro'] as $field) {
                $date = $this->recordDate($record, [$field]);
                if ($date !== null && $date <= $cutoff->toDateString()) $processed[$id] = true;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) return $processed;

        if (Schema::hasTable('document_routing_events')) {
            $eventIds = DB::table('document_routing_events')->where('source_type', $sourceKey)->whereIn('source_id', $ids)
                ->whereNotNull('occurred_at')->where('occurred_at', '<=', $cutoff)
                ->whereIn('event_key', self::PROCESSED_EVENT_KEYS)->whereNotNull('to_stage')
                ->distinct()->pluck('source_id');
            foreach ($eventIds as $id) $processed[(int) $id] = true;
        }

        if ($sourceKey === 'conservation' && Schema::hasTable('pamb_routing_events')) {
            $eventIds = DB::table('pamb_routing_events')->whereIn('conservation_report_submission_id', $ids)
                ->whereNotNull('occurred_at')->where('occurred_at', '<=', $cutoff)->whereNotNull('stage_key')
                ->distinct()->pluck('conservation_report_submission_id');
            foreach ($eventIds as $id) $processed[(int) $id] = true;
        }

        if ($sourceKey === 'engp' && Schema::hasTable('engp_report_release_events')) {
            $eventIds = DB::table('engp_report_release_events')->whereIn('engp_report_submission_id', $ids)
                ->whereDate('date_report_released_cenro', '<=', $cutoff->toDateString())
                ->distinct()->pluck('engp_report_submission_id');
            foreach ($eventIds as $id) $processed[(int) $id] = true;
        }

        return $processed;
    }

    /** @param array<string,mixed> $participant */
    private function appendRecord(array &$participant, string $source, int $id, string $workflow, string $workflowLabel, string $activity, string $documentType, ?string $office, Model $record, string $periodKey, CarbonImmutable $cutoff): void
    {
        $recordPeriod = $this->recordPeriodLabel($record) ?? substr($periodKey, 5) ?: $periodKey;
        $year = $this->recordYear($record) ?? (int) substr($periodKey, 0, 4);
        $identity = [
            'source_key' => $source, 'source_id' => $id, 'source_ids' => [$id],
            'participant_key' => $participant['participant_key'], 'workflow_key' => $workflow,
            'workflow_label' => $workflowLabel, 'activity_name' => $activity,
            'submitted_activity_name' => $activity, 'document_type' => $documentType,
            'reporting_year' => $year, 'period_key' => $recordPeriod,
            'period_label' => $this->recordPeriodLabel($record) ?? $recordPeriod,
            'calendar_office' => $office,
        ];
        $participant['details'][] = [
            'label' => $workflowLabel, 'deadline' => $this->recordDate($record, ['deadline_submission']),
            'received' => $this->recordDate($record, ['date_received_penro']),
            'source_reference' => 'Report #'.$id, 'source_identity' => $identity,
            'processed_evidence' => true,
        ];
    }

    private function belongsToAssessmentPeriod(Model $record, string $periodKey, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $periodYear = (int) substr($periodKey, 0, 4);
        $recordYear = $this->recordYear($record);
        if ($recordYear !== null && $recordYear !== $periodYear) return false;
        if (! preg_match('/^\d{4}-H([12])$/', $periodKey, $match)) return $recordYear === $periodYear;
        $selectedHalf = (int) $match[1];

        foreach (['period_key', 'period_label', 'reporting_period', 'semester', 'quarter', 'reporting_month', 'month'] as $field) {
            $marker = $record->getAttribute($field);
            if ($field === 'quarter' && is_numeric($marker) && (int) $marker >= 1 && (int) $marker <= 4) {
                return (int) $marker <= 2 ? 1 : 2;
            }
            $half = $this->markerHalfYear($marker);
            if ($half !== null) return $half === $selectedHalf;
        }
        foreach ([
            $this->recordDate($record, ['date_accomplished', 'start_date', 'monitoring_period_start', 'date_conducted']),
            $this->recordDate($record, ['deadline_submission']),
            $this->recordDate($record, ['date_received_penro', 'date_report_released_cenro']),
        ] as $date) {
            if ($date !== null && $date >= $start->toDateString() && $date <= $end->toDateString()) return true;
        }
        return false;
    }

    private function markerHalfYear(mixed $marker): ?int
    {
        if (! is_string($marker) && ! is_numeric($marker)) return null;
        $value = strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim((string) $marker)));
        if (in_array($value, ['h1', 's1', 'semester1', '1stsemester', 'firstsemester', 'semesterone'], true)) return 1;
        if (in_array($value, ['h2', 's2', 'semester2', '2ndsemester', 'secondsemester', 'semestertwo'], true)) return 2;
        if (preg_match('/^(?:q|quarter)([1-4])$/', $value, $match)) return (int) $match[1] <= 2 ? 1 : 2;
        if (preg_match('/^(?:[1-4](?:st|nd|rd|th)?quarter|(?:first|second|third|fourth)quarter)$/', $value)) {
            if (preg_match('/^(?:1|first)/', $value)) return 1;
            if (preg_match('/^(?:2|second)/', $value)) return 1;
            return 2;
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match)) return (int) $match[2] <= 6 ? 1 : 2;
        if (preg_match('/^(\d{1,2})$/', $value, $match) && (int) $match[1] >= 1 && (int) $match[1] <= 12) return (int) $match[1] <= 6 ? 1 : 2;
        foreach (range(1, 12) as $number) {
            if (in_array($value, [strtolower(CarbonImmutable::create(2000, $number, 1)->format('F')), strtolower(CarbonImmutable::create(2000, $number, 1)->format('M'))], true)) {
                return $number <= 6 ? 1 : 2;
            }
        }
        return null;
    }

    private function recordYear(Model $record): ?int
    {
        foreach (['reporting_year', 'prepared_year', 'year'] as $field) {
            $value = $record->getAttribute($field);
            if (is_numeric($value)) return (int) $value;
        }
        foreach ([
            $this->recordDate($record, ['date_accomplished', 'start_date', 'monitoring_period_start', 'date_conducted']),
            $this->recordDate($record, ['deadline_submission']),
            $this->recordDate($record, ['date_received_penro', 'date_report_released_cenro']),
        ] as $date) {
            if ($date !== null) return (int) substr($date, 0, 4);
        }
        return null;
    }

    private function recordPeriodLabel(Model $record): ?string
    {
        foreach (['period_label', 'reporting_period', 'semester', 'quarter', 'period_key', 'reporting_month', 'month'] as $field) {
            $value = $record->getAttribute($field);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        try {
            $date = CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param list<string> $fields */
    private function recordDate(Model $record, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);
            if ($value instanceof CarbonInterface) return $value->toDateString();
            if (! is_string($value) || trim($value) === '') continue;
            try {
                return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }
        return null;
    }

    /** @return array{0:?CarbonImmutable,1:?CarbonImmutable} */
    private function assessmentBounds(string $periodKey): array
    {
        if (! preg_match('/^(\d{4})(?:-H([12]))?$/', $periodKey, $match)) return [null, null];
        $year = (int) $match[1];
        $half = (int) ($match[2] ?? 0);
        $firstMonth = $half === 2 ? 7 : 1;
        $lastMonth = $half === 1 ? 6 : 12;
        return [
            CarbonImmutable::create($year, $firstMonth, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE)->startOfDay(),
            CarbonImmutable::create($year, $lastMonth, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE)->endOfMonth(),
        ];
    }
}
