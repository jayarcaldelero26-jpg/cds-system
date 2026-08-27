<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAlertRecipient;
use App\Models\ComplianceNotificationRun;
use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Compliance\ComplianceAlertSettingsService;
use App\Services\Compliance\ComplianceConfirmationService;
use App\Services\Compliance\OverdueReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceAlertController extends Controller
{
    public function index(OverdueReportService $reports, ComplianceAlertDeliveryService $deliveryService, ComplianceAlertSettingsService $settings): Response
    {
        $overdue = $reports->overdueReports();
        $pendingMov = $reports->pendingMovReports();
        $groups = $deliveryService->memorandumGroups($overdue);
        $plan = $deliveryService->deliveryPlan($overdue);
        $effectiveSettings = $settings->effective();
        $readiness = $deliveryService->recipientReadiness($overdue);
        $testData = $deliveryService->testMemorandumData($overdue);
        $automaticState = $deliveryService->automaticDeliveryState();
        $today = now(ComplianceAlertSettingsService::TIMEZONE)->toDateString();
        $todayRuns = ComplianceNotificationRun::query()->whereDate('run_date', $today)->latest('id')->get();
        $productionRuns = $todayRuns->whereIn('run_type', [
            ComplianceNotificationRun::TYPE_AUTOMATIC,
            ComplianceNotificationRun::TYPE_MANUAL,
        ]);
        $runs = ComplianceNotificationRun::query()->with(['createdBy:id,name', 'reports'])->latest('id')->limit(100)->get();
        $pendingRecordsVerification = $reports->pendingRecordsVerification();
        $confirmationHistory = $reports->confirmationHistory();
        $lastRun = $todayRuns->first();
        $requestUser = request()->user()?->can('compliance-alerts.manage');
        $oldestDeadline = $overdue->min('deadline');
        $maximumDaysOverdue = $overdue->max('daysOverdue') ?? 0;
        $nextRun = CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE)->setTimeFromTimeString($effectiveSettings['send_time']);
        if ($nextRun->isWeekend() || $nextRun->lessThanOrEqualTo(CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE))) {
            do { $nextRun = $nextRun->addDay()->setTimeFromTimeString($effectiveSettings['send_time']); } while ($nextRun->isWeekend());
        }
        $activeOverdueSources = $overdue->groupBy('module')
            ->map(fn ($items, string $module): array => [
                'module' => $module,
                'overdue_count' => $items->count(),
                'report_not_submitted' => $items->where('complianceIssue', 'Report Not Yet Submitted')->count(),
                'missing_mov' => $items->where('complianceIssue', 'MOV Not Yet Submitted')->count(),
            ])
            ->sortBy('module')
            ->values();

        return Inertia::render('ComplianceAlerts/Index', [
            'groups' => $groups,
            'deliveryPlan' => [
                'deliveries' => $plan['deliveries']->map(fn (array $deliveryItem) => [
                    'recipient' => $deliveryItem['recipient']->toArray(),
                    'report_count' => $deliveryItem['reports']->count(),
                    'groups' => $deliveryService->memorandumGroups($deliveryItem['reports']),
                ])->values(),
                'unmapped' => $deliveryService->memorandumGroups($plan['unmapped']),
            ],
            'recipientReadiness' => $readiness,
            'monitoredSources' => collect($reports->sourceDefinitions())
                ->map(fn (array $definition, string $sourceType) => ['source_type' => $sourceType, 'module' => $definition['module']])
                ->values(),
            'activeOverdueSources' => $activeOverdueSources,
            'testDelivery' => [
                'destination' => filter_var($effectiveSettings['test_recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $effectiveSettings['test_recipient_email'] : null,
                'reports_included' => $testData['reports']->count(),
                'using_fixture' => $testData['using_fixture'],
            ],
            'summary' => [
                'overdue_reports' => $overdue->count(),
                'report_not_submitted' => $overdue->where('complianceIssue', 'Report Not Yet Submitted')->count(),
                'missing_mov' => $overdue->where('complianceIssue', 'MOV Not Yet Submitted')->count(),
                'pending_mov' => $pendingMov->count(),
                'affected_groups' => count($groups),
                'sent_today' => $productionRuns->where('status', ComplianceNotificationRun::STATUS_SENT)->count(),
                'failed_today' => $productionRuns->where('status', ComplianceNotificationRun::STATUS_FAILED)->count(),
                'unmapped_recipients' => $readiness->where('status', 'unmapped')->count(),
                'ready_recipients' => $readiness->where('status', 'ready')->count(),
                'notification_status' => $lastRun?->status,
                'last_sent' => $productionRuns->where('status', ComplianceNotificationRun::STATUS_SENT)->first()?->sent_at?->toIso8601String(),
                'oldest_deadline' => $oldestDeadline,
                'maximum_days_overdue' => $maximumDaysOverdue,
                'production_delivery' => $automaticState['effective'],
                'next_automatic_run' => $nextRun->toIso8601String(),
            ],
            'runs' => $runs->map(fn (ComplianceNotificationRun $run) => $this->runPayload($run)),
            'pendingRecordsVerification' => $pendingRecordsVerification,
            'pendingMovReports' => $pendingMov->map->toArray()->values(),
            'confirmationHistory' => $confirmationHistory,
            'recipients' => $requestUser ? ComplianceAlertRecipient::query()->with('protectedArea:id,name')->latest('id')->get()->map(fn (ComplianceAlertRecipient $recipient) => $this->recipientPayload($recipient)) : [],
            'protectedAreas' => $requestUser ? ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name']) : [],
            'settings' => $requestUser ? $effectiveSettings : null,
            'safeMode' => ! config('compliance_alerts.enabled') || ! (bool) $effectiveSettings['alerts_enabled'],
            'testEmailEnabled' => $settings->testEmailEnabled(),
            'automaticDeliveryState' => $automaticState,
        ]);
    }

    public function preview(Request $request, OverdueReportService $reports, ComplianceAlertDeliveryService $deliveryService, ComplianceAlertSettingsService $settings)
    {
        if ($request->boolean('test')) {
            abort_unless($request->user()->can('compliance-alerts.manage'), 403);
            $testData = $deliveryService->testMemorandumData($reports->overdueReports());
            $effectiveSettings = $settings->effective();
            $testRecipient = filter_var($effectiveSettings['test_recipient_email'] ?? '', FILTER_VALIDATE_EMAIL)
                ? $effectiveSettings['test_recipient_email'] : 'Configured test recipient';

            return response()->view('emails.compliance.overdue-memorandum', [
                'groups' => $deliveryService->memorandumGroups($testData['reports']),
                'settings' => $effectiveSettings,
                'recipient' => ['name' => 'Test Recipient', 'email' => $testRecipient],
            ]);
        }

        $overdue = $reports->overdueReports();
        $plan = $deliveryService->deliveryPlan($overdue);
        $selected = $plan['deliveries']->first(fn (array $deliveryItem) => $deliveryItem['recipient']->key === $request->query('recipient_key'))
            ?? $plan['deliveries']->first();

        if (! $selected) {
            return redirect()->route('compliance-alerts.index')
                ->withErrors(['preview' => $overdue->isEmpty()
                    ? 'No current overdue reports to preview.'
                    : 'No mapped recipient is available for the current overdue reports.']);
        }

        return response()->view('emails.compliance.overdue-memorandum', [
            'groups' => $deliveryService->memorandumGroups($selected['reports']),
            'settings' => $settings->effective(),
            'recipient' => $selected['recipient']->toArray(),
        ]);
    }

    public function send(Request $request, OverdueReportService $reports, ComplianceAlertDeliveryService $delivery): RedirectResponse
    {
        $runs = $delivery->sendManual($reports->overdueReports(), $request->user());
        $sent = $runs->where('status', ComplianceNotificationRun::STATUS_SENT)->count();
        $failed = $runs->where('status', ComplianceNotificationRun::STATUS_FAILED)->count();
        $skipped = $runs->where('status', ComplianceNotificationRun::STATUS_SKIPPED)->count();

        if ($failed > 0 && $sent > 0) {
            return back()->withErrors(['delivery' => "Partial delivery: {$sent} destination(s) succeeded and {$failed} failed. Retry will send only failed destinations; successful destinations remain protected from duplicates."]);
        }

        if ($failed > 0) {
            return back()->withErrors(['delivery' => "Delivery failed for all {$failed} attempted destination(s). Failed claims remain retryable; see Notification History."]);
        }

        if ($sent === 0 && $skipped > 0) {
            return back()->with('success', "No duplicate was sent. {$skipped} destination(s) were already delivered or are currently claimed by another request.");
        }

        if ($sent > 0 && $skipped > 0) {
            return back()->with('success', "Compliance memorandum processing completed: {$sent} destination(s) sent and {$skipped} already delivered/in progress destination(s) skipped.");
        }

        return back()->with('success', $runs->isEmpty() ? 'No overdue reports are currently eligible for a memorandum.' : 'Compliance memorandum processing completed.');
    }

    public function sendTest(Request $request, OverdueReportService $reports, ComplianceAlertDeliveryService $delivery): RedirectResponse
    {
        $runs = $delivery->sendTest($reports->overdueReports(), $request->user());

        if ($runs->contains('status', ComplianceNotificationRun::STATUS_FAILED)) {
            return back()->withErrors(['test_email' => 'Test email delivery failed. No successful-send record was created. See notification history for status.']);
        }

        return back()->with('success', 'Test memorandum processing completed.');
    }

    public function storeRecipient(Request $request): RedirectResponse
    {
        $data = $this->recipientData($request);
        $this->ensureNoActiveDuplicate($data);
        $this->persistRecipient(fn () => ComplianceAlertRecipient::query()->create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]));

        return back()->with('success', 'Compliance alert recipient saved.');
    }

    public function updateRecipient(Request $request, ComplianceAlertRecipient $recipient): RedirectResponse
    {
        $data = $this->recipientData($request);
        $this->ensureNoActiveDuplicate($data, $recipient->id);
        $this->persistRecipient(fn () => $recipient->update([...$data, 'updated_by' => $request->user()->id]));

        return back()->with('success', 'Compliance alert recipient updated.');
    }

    public function toggleRecipient(Request $request, ComplianceAlertRecipient $recipient): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        if ($data['is_active']) {
            $this->ensureNoActiveDuplicate([
                'is_active' => true,
                'protected_area_id' => $recipient->protected_area_id,
                'target_office' => $recipient->target_office,
            ], $recipient->id);
        }
        $this->persistRecipient(fn () => $recipient->update(['is_active' => $data['is_active'], 'updated_by' => $request->user()->id]));

        return back()->with('success', $recipient->is_active ? 'Compliance alert recipient activated.' : 'Compliance alert recipient deactivated.');
    }

    public function destroyRecipient(ComplianceAlertRecipient $recipient): RedirectResponse
    {
        $used = ComplianceNotificationRun::query()->get(['payload', 'recipients'])->contains(
            fn (ComplianceNotificationRun $run): bool => data_get($run->payload, 'recipient.mapping_id') === $recipient->id
                || in_array($recipient->recipient_email, $run->recipients ?? [], true)
        );
        if ($used) {
            return back()->withErrors(['recipient' => 'This mapping is referenced by notification history. Deactivate it instead of deleting it.']);
        }

        $recipient->delete();

        return back()->with('success', 'Unused compliance alert recipient deleted.');
    }

    public function updateSettings(Request $request, ComplianceAlertSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'alerts_enabled' => ['required', 'boolean'], 'automatic_send_enabled' => ['required', 'boolean'],
            'send_time' => ['required', 'date_format:H:i'], 'timezone' => ['required', Rule::in([ComplianceAlertSettingsService::TIMEZONE])],
            'email_subject' => ['required', 'string', 'max:255'], 'to_label' => ['nullable', 'string', 'max:255'],
            'attention_line' => ['nullable', 'string', 'max:255'], 'from_line' => ['required', 'string', 'max:255'],
            'memorandum_subject' => ['required', 'string', 'max:255'], 'introductory_text' => ['required', 'string', 'max:5000'],
            'compliance_warning_text' => ['required', 'string', 'max:5000'], 'strict_compliance_text' => ['required', 'string', 'max:5000'],
            'signatory_name' => ['required', 'string', 'max:255'], 'signatory_position' => ['required', 'string', 'max:255'],
            'office_name' => ['required', 'string', 'max:255'], 'office_address' => ['required', 'string', 'max:255'],
            'focal_person_name' => ['nullable', 'string', 'max:255'], 'focal_person_position' => ['nullable', 'string', 'max:255'],
            'focal_person_contact' => ['nullable', 'string', 'max:2000'], 'do_not_reply_text' => ['required', 'string', 'max:1000'],
            'system_generated_footer_text' => ['required', 'string', 'max:2000'], 'sender_display_name' => ['required', 'string', 'max:255'],
            'fallback_recipient_email' => ['nullable', 'email:rfc', 'max:255'], 'fallback_cc_emails' => ['nullable'],
            'test_recipient_email' => ['nullable', 'email:rfc', 'max:255'],
        ]);
        $data['fallback_cc_emails'] = $this->emails($data['fallback_cc_emails'] ?? '', 'fallback_cc_emails');
        $settings->update($data);

        return back()->with('success', 'Compliance alert settings updated.');
    }

    public function confirm(Request $request, OverdueReportService $reports, ComplianceConfirmationService $confirmations): RedirectResponse
    {
        $data = $request->validate(['source_type' => ['required', 'string'], 'source_id' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $source = $reports->findSource($data['source_type'], $data['source_id']);
        abort_unless($source, 404);
        $confirmations->confirm($source, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', 'Records confirmation saved. The report has moved to Records Confirmation History.');
    }

    public function unconfirm(Request $request, OverdueReportService $reports, ComplianceConfirmationService $confirmations): RedirectResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'string'],
            'source_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $source = $reports->findSource($data['source_type'], $data['source_id']);
        abort_unless($source, 404);
        $confirmations->unconfirm($source, $request->user(), $data['reason']);

        return back()->with('success', 'Records confirmation revoked. The report returned to Pending Records Verification and the audit history was preserved.');
    }

    /** @return array<string, mixed> */
    private function recipientData(Request $request): array
    {
        $data = $request->validate([
            'protected_area_id' => ['nullable', 'integer', Rule::exists('protected_areas', 'id')],
            'target_office' => ['nullable', 'string', 'max:255'], 'recipient_name' => ['nullable', 'string', 'max:255'],
            'attention_line' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['required', 'email:rfc', 'max:255'], 'cc_emails' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['protected_area_id'] = ($data['protected_area_id'] ?? null) ?: null;
        $data['target_office'] = filled($data['target_office'] ?? null) ? trim($data['target_office']) : null;
        $data['recipient_name'] = filled($data['recipient_name'] ?? null) ? trim($data['recipient_name']) : null;
        $data['attention_line'] = array_key_exists('attention_line', $data) ? trim((string) $data['attention_line']) : null;
        if (! $data['protected_area_id'] && ! $data['target_office']) {
            throw ValidationException::withMessages([
                'target_office' => 'Select a Protected Area or provide a target office for this recipient mapping.',
            ]);
        }
        $data['recipient_email'] = Str::lower(trim($data['recipient_email']));
        $data['cc_emails'] = $this->emails($data['cc_emails'] ?? '', 'cc_emails');

        return $data;
    }

    /** @param array<string,mixed> $data */
    private function ensureNoActiveDuplicate(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }
        $query = ComplianceAlertRecipient::query()->where('is_active', true)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));
        $duplicate = $data['protected_area_id']
            ? $query->where('protected_area_id', $data['protected_area_id'])->exists()
            : $query->whereNull('protected_area_id')->get()->contains(fn (ComplianceAlertRecipient $item) => mb_strtolower(trim((string) $item->target_office)) === mb_strtolower($data['target_office']));
        if ($duplicate) {
            $field = $data['protected_area_id'] ? 'protected_area_id' : 'target_office';
            throw ValidationException::withMessages([
                $field => 'An active recipient mapping already exists for this Protected Area or target office.',
            ]);
        }
    }

    /** @return array<int, string> */
    private function emails(string|array $emails, string $field): array
    {
        $values = is_array($emails) ? $emails : explode(',', $emails);
        $values = array_values(array_filter(array_map('trim', $values)));
        foreach ($values as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    $field => 'Each CC recipient must be a valid email address.',
                ]);
            }
        }

        return array_values(array_unique(array_map('strtolower', $values)));
    }

    private function persistRecipient(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'active_scope')) {
                throw ValidationException::withMessages([
                    'target_office' => 'An active recipient mapping already exists for this Protected Area or target office.',
                ]);
            }

            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function recipientPayload(ComplianceAlertRecipient $recipient): array
    {
        return ['id' => $recipient->id, 'protected_area_id' => $recipient->protected_area_id, 'protected_area_name' => $recipient->protectedArea?->name,
            'target_office' => $recipient->target_office, 'recipient_name' => $recipient->recipient_name, 'attention_line' => $recipient->attention_line, 'recipient_email' => $recipient->recipient_email,
            'cc_emails' => $recipient->cc_emails ?? [], 'is_active' => $recipient->is_active, 'notes' => $recipient->notes,
            'created_at' => $recipient->created_at?->toIso8601String(), 'updated_at' => $recipient->updated_at?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function runPayload(ComplianceNotificationRun $run): array
    {
        return ['id' => $run->id, 'run_date' => $run->run_date?->toDateString(), 'run_type' => $run->run_type,
            'status' => $run->status, 'idempotency_key' => $run->idempotency_key, 'report_count' => $run->report_count, 'recipients' => $run->recipients, 'cc_recipients' => $run->cc_recipients,
            'sent_at' => $run->sent_at?->toIso8601String(), 'error_message' => $run->error_message, 'created_by' => $run->createdBy?->name,
            'payload' => $run->payload, 'reports' => $run->reports->map(fn ($report) => $report->snapshot)->filter()->values()];
    }
}
