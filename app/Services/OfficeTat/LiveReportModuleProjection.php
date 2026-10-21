<?php

namespace App\Services\OfficeTat;

use App\Models\FinalDocumentArchiveManifest;
use App\Models\ModuleDefinition;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Reports\ReportRequirementRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Projects active native Tracking sources without inferring obligations for empty modules. */
final class LiveReportModuleProjection
{
    public function __construct(
        private readonly SubmissionTrackingService $tracking,
        private readonly LiveReportSourceRegistry $sources,
        private readonly ReportRequirementRegistry $requirements,
        private readonly OrganizationalAccessService $organization,
    ) {}

    /**
     * @param list<array<string,mixed>> $participants
     * @param array<string,mixed> $classifications
     * @return array{by_participant:array<string,array<string,mixed>>,coverage:list<array<string,mixed>>}
     */
    public function project(User $user, string $unit, array $participants, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $cutoff, array $classifications = []): array
    {
        $byParticipant = [];
        $participantNames = [];
        foreach ($participants as $participant) {
            $key = (string) $participant['participant_key'];
            $byParticipant[$key] = $this->emptyParticipantProjection();
            $participantNames[$this->normalize((string) $participant['name'])] = $key;
        }

        $coverage = [];
        $sourceModels = $this->tracking->reportSourceModels();
        foreach ($this->sources->native() as $source) {
            $modelClass = $sourceModels[$source] ?? null;
            if (! is_string($modelClass)) continue;
            $sourceDefinition = $this->tracking->source($source);
            if (! $sourceDefinition) continue;
            $model = new $modelClass;
            $sourceUnit = ($sourceDefinition['archive_unit'] ?? '') === 'Development Unit' ? 'development' : 'conservation';
            if ($sourceUnit !== $unit) continue;

            $moduleCode = is_callable($sourceDefinition['archive_module_code'] ?? null)
                ? (string) ($sourceDefinition['archive_module_code'])($model)
                : '';
            $module = $moduleCode !== ''
                ? ModuleDefinition::query()->where('code', $moduleCode)->active()->notRetired()->first()
                : null;
            $moduleLabel = $this->tracking->archiveReportMetadata($source, $model, $sourceDefinition)['module'];
            $entry = [
                'source_key' => $source,
                'module_code' => $moduleCode,
                'module' => $moduleLabel,
                'participant_scope' => $unit === 'conservation' ? 'Authorized Protected Areas' : 'Authorized CENRO offices',
                'live_record_count' => 0,
                'completed_archived_count' => 0,
                'eligible_recorded_count' => 0,
                'received_count' => 0,
                'due_count' => 0,
                'live_tat_samples' => 0,
                'excluded_count' => 0,
                'exclusion_reasons' => [],
            ];
            if (! $module || $module->implementation_type !== ModuleDefinition::IMPLEMENTATION_SPECIALIZED) {
                $this->addExclusion($entry, 'No active specialized Module Definition is linked to this Tracking source.');
                $coverage[] = $entry;
                continue;
            }
            if (! $this->moduleIsEffective($module, $periodStart, $periodEnd)) {
                $this->addExclusion($entry, 'The source module is inactive for the selected reporting period.');
                $coverage[] = $entry;
                continue;
            }

            $schema = Schema::connection($model->getConnectionName());
            $table = $model->getTable();
            $query = $modelClass::query();

            if ($schema->hasColumn($table, 'is_historical')) {
                $query->where(fn ($live) => $live->whereNull($table.'.is_historical')->orWhere($table.'.is_historical', false));
            }
            if (Schema::hasTable('historical_report_origins')) {
                $query->whereNotExists(function ($origins) use ($source, $table): void {
                    $origins->selectRaw('1')->from('historical_report_origins')
                        ->where('historical_report_origins.source', $source)
                        ->whereColumn('historical_report_origins.source_record_id', $table.'.id');
                });
            }
            if ($source === 'aws' && $schema->hasColumn($table, 'timestamps')) {
                $query->whereNull($table.'.timestamps');
            }

            if ($unit === 'conservation') {
                $this->organization->scopeProtectedAreaQuery($query, $user);
            } elseif (! $this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT)) {
                $query->whereRaw('1 = 0');
            } elseif (! $this->organization->isGlobal($user)
                && ! in_array($this->organization->effectiveCategory($user), [
                    OrganizationalAccessService::PENRO_RECORDS, OrganizationalAccessService::OFFICE_PENRO,
                    OrganizationalAccessService::PENRO_TSD_CHIEF, OrganizationalAccessService::PENRO_CHIEF,
                    OrganizationalAccessService::PENRO_FOCAL,
                ], true)) {
                $office = mb_strtolower($this->organization->normalizeOffice($user->office_designated) ?? '');
                $query->whereRaw('LOWER(TRIM('.$table.'.target_office)) = ?', [$office]);
            }

            $records = $query->get();
            $periodIds = [];
            foreach ($records as $record) {
                $participantKey = $this->participantKey($record, $unit, $participantNames);
                if ($participantKey === null || ! isset($byParticipant[$participantKey])) continue;

                $start = null;
                $periodReason = null;
                if ($source === 'revenue') {
                    $year = (int) $record->getAttribute('reporting_year');
                    $month = (int) $record->getAttribute('reporting_month');
                    if ($year >= 2000 && $month >= 1 && $month <= 12) {
                        $start = $this->requirements->periodStartDate('pa', (string) $module->requirement_key, $year, sprintf('%04d-%02d', $year, $month));
                    } else {
                        $periodReason = 'Revenue report has no valid reporting year/month registry period.';
                    }
                } else {
                    $start = $this->dateValue($record->getAttribute('date_accomplished'));
                }
                $deadline = $this->dateValue($record->getAttribute('deadline_submission'));
                $periodDate = $start ?? $deadline;
                if ($periodDate !== null && ! $this->moduleAppliesToDate($module, $periodDate)) {
                    $this->addExclusion($entry, 'Module effective dates do not include this source reporting date.');
                    continue;
                }
                $received = $this->dateValue($record->getAttribute('date_received_penro'));
                $startInPeriod = $this->dateInPeriod($start, $periodStart, $periodEnd);
                $deadlineInPeriod = $this->dateInPeriod($deadline, $periodStart, $periodEnd);

                if (! $startInPeriod && ! $deadlineInPeriod && ($start !== null || $deadline !== null)) continue;
                $recordId = (int) $record->getKey();
                $periodIds[$recordId] = true;
                $entry['live_record_count']++;

                $identity = [
                    'source_key' => $source, 'source_id' => $recordId, 'module_code' => $moduleCode,
                    'participant_key' => $participantKey,
                    'workflow_key' => (string) ($record->getAttribute('workflow_key') ?: $module->requirement_key ?: $module->code),
                    'period_key' => $this->reportPeriodKey($record, $source),
                ];
                $label = $moduleLabel.' · '.$this->periodLabel($record, $source);
                $reference = strtoupper($source).' report #'.$recordId;

                if ($start === null || $deadline === null || $periodReason !== null) {
                    $reason = $periodReason ?? 'Required source start or authoritative deadline is missing; this report is excluded from scoring.';
                    $entry['excluded_count']++;
                    $entry['exclusion_reasons'][$reason] = ($entry['exclusion_reasons'][$reason] ?? 0) + 1;
                    $byParticipant[$participantKey]['incomplete_date_count']++;
                    $byParticipant[$participantKey]['incomplete_details'][] = [
                        'label' => $label, 'status' => 'Incomplete source dates', 'start_date' => $start,
                        'deadline' => $deadline, 'received' => $received, 'receipt_endpoint' => 'date_received_penro',
                        'allowed_days' => null, 'days_used' => null, 'day_convention' => null, 'technical' => null,
                        'issue' => $reason, 'source_reference' => $reference, 'source_identity' => $identity,
                        'source_rows_matched' => 1,
                    ];
                    continue;
                }

                if (! $deadlineInPeriod) {
                    $reason = 'Source deadline '.$deadline.' is outside the selected reporting period.';
                    $entry['excluded_count']++;
                    $entry['exclusion_reasons'][$reason] = ($entry['exclusion_reasons'][$reason] ?? 0) + 1;
                    $byParticipant[$participantKey]['incomplete_details'][] = [
                        'label' => $label, 'status' => 'Outside selected reporting period', 'start_date' => $start,
                        'deadline' => $deadline, 'received' => $received, 'receipt_endpoint' => 'date_received_penro',
                        'allowed_days' => null, 'days_used' => null, 'day_convention' => null, 'technical' => null,
                        'issue' => $reason, 'source_reference' => $reference, 'source_identity' => $identity,
                        'source_rows_matched' => 1,
                    ];
                    continue;
                }

                $entry['eligible_recorded_count']++;
                $byParticipant[$participantKey]['activity_count']++;
                if ($received !== null && $received <= $cutoff->toDateString()) {
                    $entry['received_count']++;
                    $byParticipant[$participantKey]['received_activity_count']++;
                }
                if ($deadline <= $cutoff->toDateString()) $entry['due_count']++;
                else $byParticipant[$participantKey]['not_yet_due_count']++;

                $obligation = [
                    'key' => implode('|', ['recorded', $source, $recordId, $participantKey, $identity['workflow_key'], $identity['period_key']]),
                    'start' => $start, 'deadline' => $deadline, 'received' => $received,
                    'source_deadline' => $deadline, 'technical' => $classifications[$unit][$module->requirement_key] ?? null,
                    'record_id' => $recordId, 'historical' => false, 'domain' => $source,
                    // All native contracts represented by the source registry use the shared
                    // Conservation working-day calendar to calculate their actual deadlines.
                    'day_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
                    'office' => $record->getAttribute('target_office'), 'classification_version' => null,
                    'label' => $label, 'source_rows_matched' => 1, 'source_identity' => $identity,
                    'historical_receipt_conflict' => false, 'historical_receipt_evidence' => null,
                ];
                $byParticipant[$participantKey]['obligations'][] = $obligation;
            }

            $entry['completed_archived_count'] = $this->archivedCount($source, array_map('intval', array_keys($periodIds)));
            $coverage[] = $entry;
        }

        return ['by_participant' => $byParticipant, 'coverage' => $coverage];
    }

    /** @param list<int> $sourceIds */
    public function archivedCount(string $source, array $sourceIds): int
    {
        if ($sourceIds === [] || ! Schema::hasTable('final_document_archive_manifests')) return 0;

        return (int) FinalDocumentArchiveManifest::query()
            ->where('source_type', $source)->whereIn('source_id', array_values(array_unique($sourceIds)))
            ->where('archive_phase', FinalDocumentArchiveManifest::PHASE)->where('archive_status', 'ARCHIVED')
            ->whereNotNull('finalized_at')->distinct('source_id')->count('source_id');
    }

    private function moduleAppliesToDate(ModuleDefinition $module, string $date): bool
    {
        $reportDate = CarbonImmutable::parse($date, BusinessCalendarService::TIMEZONE)->startOfDay();
        return ($module->first_applicable_year === null || (int) $module->first_applicable_year <= $reportDate->year)
            && (! $module->effective_from || ! $reportDate->lessThan($module->effective_from))
            && (! $module->effective_to || ! $reportDate->greaterThan($module->effective_to));
    }
    private function moduleIsEffective(ModuleDefinition $module, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return ($module->first_applicable_year === null || (int) $module->first_applicable_year <= $end->year)
            && (! $module->effective_from || ! $module->effective_from->greaterThan($end))
            && (! $module->effective_to || ! $module->effective_to->lessThan($start));
    }

    private function participantKey(Model $record, string $unit, array $participantNames): ?string
    {
        if ($unit === 'conservation') {
            $id = $record->getAttribute('protected_area_id');
            return $id === null ? null : 'pa:'.(int) $id;
        }
        $office = $this->normalize((string) $record->getAttribute('target_office'));
        return $office !== '' ? ($participantNames[$office] ?? null) : null;
    }

    private function reportPeriodKey(Model $record, string $source): string
    {
        if ($source === 'revenue') {
            return sprintf('%04d-%02d', (int) $record->getAttribute('reporting_year'), (int) $record->getAttribute('reporting_month'));
        }
        foreach (['period_key', 'semester', 'quarter', 'reporting_period'] as $field) {
            $value = trim((string) $record->getAttribute($field));
            if ($value !== '') return $value;
        }
        return $this->dateValue($record->getAttribute('date_accomplished')) ?? 'period-unavailable';
    }

    private function periodLabel(Model $record, string $source): string
    {
        if ($source === 'revenue') return $this->reportPeriodKey($record, $source);
        foreach (['period_label', 'semester', 'quarter', 'reporting_period'] as $field) {
            $value = trim((string) $record->getAttribute($field));
            if ($value !== '') return $value;
        }
        return $this->reportPeriodKey($record, $source);
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) return $value->toDateString();
        if (! is_string($value) || trim($value) === '') return null;
        try { return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->toDateString(); }
        catch (\Throwable) { return null; }
    }

    private function dateInPeriod(?string $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $date !== null && $date >= $start->toDateString() && $date <= $end->toDateString();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function addExclusion(array &$entry, string $reason): void
    {
        $entry['excluded_count']++;
        $entry['exclusion_reasons'][$reason] = ($entry['exclusion_reasons'][$reason] ?? 0) + 1;
    }

    /** @return array<string,mixed> */
    private function emptyParticipantProjection(): array
    {
        return ['obligations' => [], 'incomplete_details' => [], 'activity_count' => 0, 'received_activity_count' => 0,
            'not_yet_due_count' => 0, 'incomplete_date_count' => 0, 'incomplete_evidence_count' => 0];
    }
}
