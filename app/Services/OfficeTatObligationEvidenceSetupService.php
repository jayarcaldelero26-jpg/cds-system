<?php

namespace App\Services;

use App\Models\OfficeTatApplicabilityVersion;
use App\Models\OfficeTatApprovalEvidence;
use App\Models\OfficeTatNonReceiptRegisterVersion;
use App\Models\OfficeTatObligationScheduleVersion;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Reports\ReportRequirementRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Append-only owner evidence contract for Conservation compliance obligations. */
final class OfficeTatObligationEvidenceSetupService
{
    public function __construct(private readonly ReportRequirementRegistry $requirements) {}

    public function schemaReady(): bool
    {
        return Schema::hasTable('office_tat_applicability_versions')
            && Schema::hasTable('office_tat_obligation_schedule_versions')
            && Schema::hasTable('office_tat_non_receipt_register_versions')
            && Schema::hasTable('office_tat_approval_evidence');
    }

    /** Canonical PA/activity/document choices; no default applicability is implied. */
    public function catalogue(): array
    {
        $items = [];
        foreach ($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic') as $workflow) {
            $activityDocuments = $workflow['activity_documents'] ?? array_fill_keys($workflow['activities'] ?? [''], $workflow['documents'] ?? ['Report']);
            foreach ($activityDocuments as $activity => $documents) {
                foreach ((array) $documents as $document) {
                    $items[] = [
                        'workflow_key' => (string) $workflow['key'],
                        'workflow_label' => (string) $workflow['label'],
                        'activity_name' => (string) $activity,
                        'document_type' => (string) $document,
                        'schedule_kind' => in_array((string) $workflow['key'], PambComplianceCalculator::MEETING_WORKFLOWS, true) ? 'event' : 'recurring',
                        'period_options' => array_map(fn (array $period): array => ['id' => $period['key'], 'label' => $period['label']], $this->requirements->periods(ReportRequirementRegistry::PA, (string) $workflow['key'], (int) now(BusinessCalendarService::TIMEZONE)->year)),
                    ];
                }
            }
        }
        return $items;
    }

    public function snapshot(int $paId, int $year): array
    {
        if (! $this->schemaReady()) return ['applicability' => [], 'schedules' => []];
        $applicability = OfficeTatApplicabilityVersion::query()->where('protected_area_id', $paId)->orderBy('version')->get()
            ->groupBy(fn ($row) => implode('|', [$row->workflow_key, $row->activity_name, $row->document_type, $row->effective_from->toDateString()]))
            ->map(fn ($rows) => $rows->last())
            ->map(fn ($row) => [
                'workflow_key' => $row->workflow_key, 'activity_name' => $row->activity_name, 'document_type' => $row->document_type,
                'status' => $row->status, 'effective_from' => $row->effective_from->toDateString(), 'effective_to' => $row->effective_to?->toDateString(),
                'approving_authority' => $row->approving_authority, 'approval_reference' => $row->approval_reference,
                'rationale' => $row->rationale, 'version' => $row->version,
                'evidence_reference' => OfficeTatApprovalEvidence::query()->find($row->evidence_id)?->public_id,
            ])->values()->all();
        $schedules = OfficeTatObligationScheduleVersion::query()->where('protected_area_id', $paId)->where('reporting_year', $year)->orderBy('version')->get()
            ->groupBy(fn ($row) => implode('|', [$row->workflow_key, $row->activity_name, $row->document_type, $row->period_key]))
            ->map(fn ($rows) => $rows->last())
            ->map(fn ($row) => [
                'workflow_key' => $row->workflow_key, 'activity_name' => $row->activity_name, 'document_type' => $row->document_type,
                'reporting_year' => $row->reporting_year, 'period_key' => $row->period_key, 'schedule_kind' => $row->schedule_kind,
                'occurrences' => $row->occurrences, 'inventory_complete' => $row->inventory_complete,
                'approving_authority' => $row->approving_authority, 'approval_reference' => $row->approval_reference,
                'rationale' => $row->rationale, 'version' => $row->version,
                'evidence_reference' => OfficeTatApprovalEvidence::query()->find($row->evidence_id)?->public_id,
            ])->values()->all();
        return ['applicability' => $applicability, 'schedules' => $schedules];
    }

    public function evidenceScopeForApplicability(int $paId, string $workflow, string $activity, string $document): string
    {
        return 'applicability:'.$paId.':'.$workflow.':'.hash('sha256', $activity."\0".$document);
    }

    public function evidenceScopeForSchedule(int $paId, string $workflow, string $activity, string $document, int $year, string $period): string
    {
        return 'schedule:'.$paId.':'.$workflow.':'.hash('sha256', $activity."\0".$document).':'.$year.':'.hash('sha256', $period);
    }

    public function evidenceScopeForNonReceipt(string $participantKey, string $cutoff): string
    {
        return 'nonreceipt:'.hash('sha256', $participantKey."\0".$cutoff);
    }

    public function evidenceScopeForClassification(string $domain, string $workflow): string
    {
        return 'classification:'.$domain.':'.$workflow;
    }

    public function uploadScope(User $actor, string $kind, array $identity): string
    {
        $this->authorizeAndRequireSchema($actor);
        return match ($kind) {
            'applicability' => $this->applicabilityUploadScope($identity),
            'schedule' => $this->scheduleUploadScope($identity),
            'nonreceipt' => $this->nonReceiptUploadScope($identity),
            'classification' => $this->classificationUploadScope($identity),
            default => throw ValidationException::withMessages(['kind' => 'Unsupported approval evidence type.']),
        };
    }

    public function uploadApprovalEvidence(User $actor, UploadedFile $file, string $scopeKey): OfficeTatApprovalEvidence
    {
        $this->authorizeAndRequireSchema($actor);
        if (! preg_match('/^(?:applicability:\d+:[a-z0-9_]+:[a-f0-9]{64}|schedule:\d+:[a-z0-9_]+:[a-f0-9]{64}:\d{4}:[a-f0-9]{64}|nonreceipt:[a-f0-9]{64}|classification:(?:conservation|engp):[a-z0-9_]+)$/', $scopeKey)) {
            throw ValidationException::withMessages(['evidence' => 'The evidence scope is not a supported Office TAT setup reference.']);
        }
        if ($file->getSize() > 25 * 1024 * 1024 || ! in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)) {
            throw ValidationException::withMessages(['evidence' => 'Attach a supported approval file up to 25 MB.']);
        }

        $publicId = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('office-tat-approval-evidence', $publicId.'.'.$extension, 'local');
        if (! is_string($path)) throw ValidationException::withMessages(['evidence' => 'The approval evidence file could not be stored.']);

        try {
            return OfficeTatApprovalEvidence::query()->create([
                'public_id' => $publicId,
                'scope_key' => $scopeKey,
                'storage_path' => $path,
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'media_type' => $file->getMimeType() ?: 'application/octet-stream',
                'byte_size' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $actor->getKey(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function evidenceForDownload(string $publicId): OfficeTatApprovalEvidence
    {
        $evidence = OfficeTatApprovalEvidence::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($evidence->storage_path), 404);
        abort_unless(hash_file('sha256', Storage::disk('local')->path($evidence->storage_path)) === $evidence->sha256, 409, 'Stored approval evidence has changed since registration.');
        return $evidence;
    }

    public function saveApplicability(User $actor, array $data): OfficeTatApplicabilityVersion
    {
        $this->authorizeAndRequireSchema($actor);
        $paId = (int) $data['protected_area_id'];
        ProtectedArea::query()->findOrFail($paId);
        $this->requirePair($data['workflow_key'], $data['activity_name'], $data['document_type']);
        $this->validateBounds($data['effective_from'], $data['effective_to'] ?? null);
        if (! in_array($data['status'], ['unknown', 'applicable', 'not_applicable'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose Unknown, Applicable or Not applicable.']);
        }
        $evidence = $data['status'] === 'unknown' ? null : $this->requireEvidence(
            (string) $data['evidence_reference'],
            $this->evidenceScopeForApplicability($paId, $data['workflow_key'], $data['activity_name'], $data['document_type']),
        );
        $this->validateApprovalCitation($data, $data['status'] !== 'unknown');

        return DB::transaction(function () use ($actor, $data, $paId, $evidence): OfficeTatApplicabilityVersion {
            // Serialize version checks against a stable parent row so concurrent appends see
            // the latest committed version before choosing their next version number.
            ProtectedArea::query()->whereKey($paId)->lockForUpdate()->firstOrFail();
            $query = OfficeTatApplicabilityVersion::query()->where('protected_area_id', $paId)
                ->where('workflow_key', $data['workflow_key'])->where('activity_name', $data['activity_name'])->where('document_type', $data['document_type']);
            $latestVersion = (int) (clone $query)->max('version');
            if ((int) $data['expected_version'] !== $latestVersion) $this->staleWrite('expected_version');

            $start = $data['effective_from'];
            $end = $data['effective_to'] ?? null;
            $latestWindows = (clone $query)->orderBy('version')->get()->groupBy(fn ($row) => $row->effective_from->toDateString());
            foreach ($latestWindows as $windowRows) {
                $window = $windowRows->last();
                if ($window->effective_from->toDateString() === $start) continue;
                $windowEnd = $window->effective_to?->toDateString() ?? '9999-12-31';
                $newEnd = $end ?? '9999-12-31';
                if ($start <= $windowEnd && $newEnd >= $window->effective_from->toDateString()) {
                    throw ValidationException::withMessages(['effective_from' => 'Applicability effective windows cannot overlap. Append the corrected version to the existing window first.']);
                }
            }
            return $query->create([
                'protected_area_id' => $paId, 'workflow_key' => $data['workflow_key'], 'activity_name' => $data['activity_name'],
                'document_type' => $data['document_type'], 'status' => $data['status'], 'effective_from' => $start,
                'effective_to' => $end, 'approving_authority' => trim($data['approving_authority'] ?? 'Unresolved'),
                'approval_reference' => trim($data['approval_reference'] ?? 'Unresolved'), 'rationale' => trim($data['rationale']),
                'evidence_id' => $evidence?->id, 'version' => $latestVersion + 1, 'configured_by' => $actor->getKey(),
            ]);
        });
    }

    public function saveSchedule(User $actor, array $data): OfficeTatObligationScheduleVersion
    {
        $this->authorizeAndRequireSchema($actor);
        $paId = (int) $data['protected_area_id'];
        ProtectedArea::query()->findOrFail($paId);
        $this->requirePair($data['workflow_key'], $data['activity_name'], $data['document_type']);
        $year = (int) $data['reporting_year'];
        $period = (string) $data['period_key'];
        $allowedPeriods = $this->requirements->periods(ReportRequirementRegistry::PA, $data['workflow_key'], $year);
        abort_unless(collect($allowedPeriods)->contains(fn (array $row): bool => $row['key'] === $period), 422, 'Reporting period is not supported by the active source catalogue.');
        $expectedKind = in_array($data['workflow_key'], \App\Services\Conservation\PambComplianceCalculator::MEETING_WORKFLOWS, true) ? 'event' : 'recurring';
        abort_unless($data['schedule_kind'] === $expectedKind, 422, 'Schedule kind must match the source workflow.');
        if (! (bool) ($data['inventory_complete'] ?? false)) throw ValidationException::withMessages(['inventory_complete' => 'A schedule inventory must be explicitly complete.']);
        $occurrences = array_values($data['occurrences'] ?? []);
        if ($expectedKind === 'recurring' && $occurrences === []) throw ValidationException::withMessages(['occurrences' => 'An applicable recurring pair requires at least one scheduled obligation.']);
        if (count($occurrences) > 50) throw ValidationException::withMessages(['occurrences' => 'A period inventory is limited to 50 occurrences.']);
        $keys = [];
        foreach ($occurrences as $index => $occurrence) {
            $key = trim((string) ($occurrence['occurrence_key'] ?? ''));
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,119}$/', $key) || in_array($key, $keys, true)) {
                throw ValidationException::withMessages(["occurrences.$index.occurrence_key" => 'Occurrence identities must be unique, stable source references.']);
            }
            $keys[] = $key;
            $this->validateBounds($occurrence['start_date'] ?? '', $occurrence['deadline'] ?? null);
            if (($occurrence['deadline'] ?? '') < ($occurrence['start_date'] ?? '')) throw ValidationException::withMessages(["occurrences.$index.deadline" => 'Deadline must be on or after the supported start date.']);
            if (! $this->applicabilityAt($paId, $data['workflow_key'], $data['activity_name'], $data['document_type'], $occurrence['start_date'])?->status === 'applicable') {
                throw ValidationException::withMessages(["occurrences.$index.start_date" => 'The occurrence date is outside an approved Applicable window for this exact PA activity/document pair.']);
            }
        }
        if ($expectedKind === 'event' && $occurrences === []) {
            $yearStart = $year.'-01-01';
            if ($this->applicabilityAt($paId, $data['workflow_key'], $data['activity_name'], $data['document_type'], $yearStart)?->status !== 'applicable') {
                throw ValidationException::withMessages(['occurrences' => 'An explicit zero-event inventory still needs an approved Applicability window.']);
            }
        }
        $evidence = $this->requireEvidence(
            (string) $data['evidence_reference'],
            $this->evidenceScopeForSchedule($paId, $data['workflow_key'], $data['activity_name'], $data['document_type'], $year, $period),
        );
        $this->validateApprovalCitation($data, true);

        return DB::transaction(function () use ($actor, $data, $paId, $year, $period, $expectedKind, $occurrences, $evidence): OfficeTatObligationScheduleVersion {
            $query = OfficeTatObligationScheduleVersion::query()->where('protected_area_id', $paId)->where('workflow_key', $data['workflow_key'])
                ->where('activity_name', $data['activity_name'])->where('document_type', $data['document_type'])->where('reporting_year', $year)->where('period_key', $period);
            $latestVersion = (int) (clone $query)->max('version');
            if ((int) $data['expected_version'] !== $latestVersion) $this->staleWrite('expected_version');
            return $query->create([
                'protected_area_id' => $paId, 'workflow_key' => $data['workflow_key'], 'activity_name' => $data['activity_name'],
                'document_type' => $data['document_type'], 'reporting_year' => $year, 'period_key' => $period,
                'schedule_kind' => $expectedKind, 'occurrences' => $occurrences, 'inventory_complete' => true,
                'approving_authority' => trim($data['approving_authority']), 'approval_reference' => trim($data['approval_reference']),
                'rationale' => trim($data['rationale']), 'evidence_id' => $evidence->id, 'version' => $latestVersion + 1,
                'configured_by' => $actor->getKey(),
            ]);
        });
    }

    public function saveNonReceiptRegister(User $actor, array $data, array $allowedObligationDetails): OfficeTatNonReceiptRegisterVersion
    {
        $this->authorizeAndRequireSchema($actor);
        $participant = (string) $data['participant_key'];
        if (! preg_match('/^(?:pa:\d+|cenro:\d+)$/', $participant)) throw ValidationException::withMessages(['participant_key' => 'Choose a participant from the current assessment roster.']);
        $allowed = collect($allowedObligationDetails)->keyBy('key');
        $keys = collect($data['obligation_keys'])->map(fn ($key) => (string) $key)->unique()->values()->all();
        if ($keys === [] || count($keys) !== count($data['obligation_keys'])) throw ValidationException::withMessages(['obligation_keys' => 'Select one or more exact, unique obligation identities.']);
        $manifest = [];
        foreach ($keys as $key) {
            $obligation = $allowed->get($key);
            abort_unless($obligation && str_starts_with($key, $participant.'|'), 422, 'The obligation identity is stale or belongs to another participant. Refresh the assessment.');
            if ($obligation['deadline'] >= OfficeTatComplianceAssessmentService::POLICY_EFFECTIVE_DATE) throw ValidationException::withMessages(['obligation_keys' => 'This register only verifies pre-effective obligations.']);
            if (($obligation['received'] !== null && $obligation['received'] <= $data['cutoff_date']) || ! empty($obligation['historical_receipt_conflict'])) throw ValidationException::withMessages(['obligation_keys' => 'Positive or conflicting source evidence prevents a non-receipt declaration for this obligation.']);
            if ($data['cutoff_date'] < $obligation['deadline']) throw ValidationException::withMessages(['cutoff_date' => 'The register cutoff must be on or after each selected deadline.']);
            $identity = $obligation['source_identity'] ?? [];
            if (($identity['participant_key'] ?? null) !== $participant || blank($identity['workflow_key'] ?? null)
                || blank($identity['activity_name'] ?? null) || blank($identity['document_type'] ?? null)
                || ! is_numeric($identity['reporting_year'] ?? null) || blank($identity['period_key'] ?? null)) {
                throw ValidationException::withMessages(['obligation_keys' => 'The selected obligation lacks a complete, server-resolved source identity. Refresh the assessment.']);
            }
            $identityYear = (int) $identity['reporting_year'];
            $identityPeriod = (string) $identity['period_key'];
            $occurrenceKey = $identity['occurrence_key'] ?? null;
            $expectedKey = str_starts_with($participant, 'pa:')
                ? self::obligationKey((int) substr($participant, 3), (string) $identity['workflow_key'], (string) $identity['activity_name'], (string) $identity['document_type'], $identityYear, $identityPeriod, (string) ($occurrenceKey ?? ''))
                : implode('|', [$participant, (string) $identity['workflow_key'], $identityYear, $identityPeriod]);
            if ($key !== $expectedKey) throw ValidationException::withMessages(['obligation_keys' => 'The selected obligation identity does not match its source workflow, activity, document and period.']);
            $this->validateBounds((string) $obligation['start_date'], (string) $obligation['deadline']);
            $manifest[] = [
                'key' => $key, 'participant_key' => $participant,
                'domain' => str_starts_with($participant, 'pa:') ? 'conservation' : 'development',
                'workflow_key' => (string) $identity['workflow_key'], 'workflow_label' => (string) ($identity['workflow_label'] ?? $identity['workflow_key']),
                'activity_name' => (string) $identity['activity_name'], 'document_type' => (string) $identity['document_type'],
                'reporting_year' => $identityYear, 'period_key' => $identityPeriod,
                'occurrence_key' => $occurrenceKey, 'start_date' => (string) $obligation['start_date'],
                'deadline' => (string) $obligation['deadline'],
            ];
        }
        $evidence = $this->requireEvidence((string) $data['evidence_reference'], $this->evidenceScopeForNonReceipt($participant, $data['cutoff_date']));
        $this->validateApprovalCitation($data, true);

        return DB::transaction(function () use ($actor, $data, $participant, $keys, $manifest, $evidence): OfficeTatNonReceiptRegisterVersion {
            $query = OfficeTatNonReceiptRegisterVersion::query()->where('participant_key', $participant)->whereDate('cutoff_date', $data['cutoff_date']);
            $latest = (int) (clone $query)->max('version');
            if ((int) ($data['expected_version'] ?? -1) !== $latest) $this->staleWrite('expected_version');
            $version = $latest + 1;
            return $query->create([
                'participant_key' => $participant, 'cutoff_date' => $data['cutoff_date'], 'obligation_keys' => $keys,
                'obligation_manifest' => $manifest,
                'approving_authority' => trim($data['approving_authority']), 'approval_reference' => trim($data['approval_reference']),
                'rationale' => trim($data['rationale']), 'evidence_id' => $evidence->id, 'version' => $version, 'configured_by' => $actor->getKey(),
            ]);
        });
    }

    public function applicabilityAt(int $paId, string $workflow, string $activity, string $document, string $date): ?OfficeTatApplicabilityVersion
    {
        if (! $this->schemaReady()) return null;
        return OfficeTatApplicabilityVersion::query()->where('protected_area_id', $paId)->where('workflow_key', $workflow)
            ->where('activity_name', $activity)->where('document_type', $document)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    public function latestSchedule(int $paId, string $workflow, string $activity, string $document, int $year, string $period): ?OfficeTatObligationScheduleVersion
    {
        if (! $this->schemaReady()) return null;
        return OfficeTatObligationScheduleVersion::query()->where('protected_area_id', $paId)->where('workflow_key', $workflow)
            ->where('activity_name', $activity)->where('document_type', $document)->where('reporting_year', $year)->where('period_key', $period)
            ->orderByDesc('version')->first();
    }

    public function nonReceiptEvidence(string $participant, string $obligationKey, string $deadline, string $cutoff): ?OfficeTatNonReceiptRegisterVersion
    {
        if (! $this->schemaReady() || $deadline >= OfficeTatComplianceAssessmentService::POLICY_EFFECTIVE_DATE) return null;
        return OfficeTatNonReceiptRegisterVersion::query()->with('evidence')->where('participant_key', $participant)->whereDate('cutoff_date', '>=', $deadline)
            ->whereDate('cutoff_date', '<=', $cutoff)->whereJsonContains('obligation_keys', $obligationKey)
            ->whereHas('evidence', fn ($query) => $query->whereNotNull('public_id'))
            ->orderByDesc('cutoff_date')->orderByDesc('version')->first();
    }

    public function evidenceFileIsValid(?int $id): bool
    {
        if (! $id || ! Schema::hasTable('office_tat_approval_evidence')) return false;
        $evidence = OfficeTatApprovalEvidence::query()->find($id);
        if (! $evidence || ! Storage::disk('local')->exists($evidence->storage_path)) return false;
        return hash_file('sha256', Storage::disk('local')->path($evidence->storage_path)) === $evidence->sha256;
    }

    public static function obligationKey(int $paId, string $workflow, string $activity, string $document, int $year, string $period, string $occurrence): string
    {
        return implode('|', ['pa:'.$paId, $workflow, $year, $period, hash('sha256', $activity."\0".$document), $occurrence]);
    }

    private function requirePair(string $workflow, string $activity, string $document): void
    {
        $pair = collect($this->catalogue())->first(fn (array $row): bool => $row['workflow_key'] === $workflow && $row['activity_name'] === $activity && $row['document_type'] === $document);
        abort_unless($pair, 422, 'Workflow, activity and document must match an active Conservation catalogue pair.');
    }

    private function applicabilityUploadScope(array $identity): string
    {
        $paId = (int) ($identity['protected_area_id'] ?? 0);
        ProtectedArea::query()->findOrFail($paId);
        $workflow = (string) ($identity['workflow_key'] ?? '');
        $activity = (string) ($identity['activity_name'] ?? '');
        $document = (string) ($identity['document_type'] ?? '');
        $this->requirePair($workflow, $activity, $document);
        return $this->evidenceScopeForApplicability($paId, $workflow, $activity, $document);
    }

    private function scheduleUploadScope(array $identity): string
    {
        $scope = $this->applicabilityUploadScope($identity);
        $paId = (int) $identity['protected_area_id'];
        $workflow = (string) $identity['workflow_key'];
        $year = (int) ($identity['reporting_year'] ?? 0);
        $period = (string) ($identity['period_key'] ?? '');
        abort_unless(collect($this->requirements->periods(ReportRequirementRegistry::PA, $workflow, $year))->contains(fn (array $row): bool => $row['key'] === $period), 422);
        return $this->evidenceScopeForSchedule($paId, $workflow, (string) $identity['activity_name'], (string) $identity['document_type'], $year, $period);
    }

    private function nonReceiptUploadScope(array $identity): string
    {
        $participant = (string) ($identity['participant_key'] ?? '');
        abort_unless(preg_match('/^(?:pa:\\d+|cenro:\\d+)$/', $participant), 422);
        $cutoff = (string) ($identity['cutoff_date'] ?? '');
        $this->validateBounds($cutoff, $cutoff);
        return $this->evidenceScopeForNonReceipt($participant, $cutoff);
    }

    private function classificationUploadScope(array $identity): string
    {
        $domain = (string) ($identity['domain'] ?? '');
        $workflow = (string) ($identity['workflow_key'] ?? '');
        if ($domain === 'conservation') $this->requireWorkflow($workflow);
        elseif ($domain === 'engp') abort_unless(collect(app(\App\Services\Engp\EngpReportWorkflowRegistry::class)->all())->contains(fn (array $row): bool => $row['key'] === $workflow), 422);
        else abort(422);
        return $this->evidenceScopeForClassification($domain, $workflow);
    }

    private function requireWorkflow(string $workflow): void
    {
        abort_unless(collect($this->requirements->definitions(ReportRequirementRegistry::PA, true, 'generic'))->contains(fn (array $row): bool => $row['key'] === $workflow), 422);
    }

    private function requireEvidence(string $publicId, string $expectedScope): OfficeTatApprovalEvidence
    {
        $evidence = OfficeTatApprovalEvidence::query()->where('public_id', $publicId)->first();
        if (! $evidence || $evidence->scope_key !== $expectedScope || ! $this->evidenceFileIsValid($evidence->id)) {
            throw ValidationException::withMessages(['evidence_reference' => 'Use a stored approval evidence reference registered for this exact setup scope.']);
        }
        return $evidence;
    }

    private function validateApprovalCitation(array $data, bool $required): void
    {
        foreach (['approving_authority', 'approval_reference', 'rationale'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($required && $value === '') throw ValidationException::withMessages([$field => 'Record the approving authority, written reference and rationale.']);
            if (mb_strlen($value) > ($field === 'rationale' ? 4000 : 180)) throw ValidationException::withMessages([$field => 'The approval citation is too long.']);
        }
        if ($required && mb_strlen(trim($data['rationale'])) < 10) throw ValidationException::withMessages(['rationale' => 'Provide a specific rationale of at least 10 characters.']);
    }

    private function validateBounds(string $from, ?string $to): void
    {
        try {
            $start = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $from);
            if (! $start || $start->format('Y-m-d') !== $from) throw new \RuntimeException();
            if ($to !== null) {
                $end = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $to);
                if (! $end || $end->format('Y-m-d') !== $to || $to < $from) throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_from' => 'Use supported YYYY-MM-DD dates with effective-to on or after effective-from.']);
        }
    }

    private function staleWrite(string $field): never
    {
        throw ValidationException::withMessages([$field => 'This setup version changed since it was opened. Refresh and re-enter the approved facts.']);
    }

    private function authorizeAndRequireSchema(User $actor): void
    {
        abort_unless($actor->can('office-tat.configure'), 403);
        abort_unless($this->schemaReady(), 409, 'Install the Office TAT obligation evidence migration before editing setup facts.');
    }
}
