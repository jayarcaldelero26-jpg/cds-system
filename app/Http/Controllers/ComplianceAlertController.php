<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAlertRecipient;
use App\Models\ComplianceAlertSetting;
use App\Models\ComplianceNotificationRun;
use App\Models\NonWorkingDay;
use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Compliance\ComplianceAlertSettingsService;
use App\Services\Compliance\ComplianceConfirmationService;
use App\Services\Compliance\ComplianceAlertTemplateResolver;
use App\Services\Compliance\ComplianceRichTextSanitizer;
use App\Services\Compliance\OverdueReport;
use App\Services\Compliance\OverdueReportService;
use App\Services\CalendarMovEventService;
use App\Services\BusinessCalendarService;
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
use App\Services\AuditLogService;

class ComplianceAlertController extends Controller
{
    public function index(OverdueReportService $reports, ComplianceAlertDeliveryService $deliveryService, ComplianceAlertSettingsService $settings, ComplianceAlertTemplateResolver $templates): Response
    {
        $overdue = $reports->overdueReports();
        $dueSoon = $reports->dueSoonReports((int) config('notifications.due_soon_days', 3));
        $dueToday = $reports->dueTodayReports();
        $pendingMov = $reports->pendingMovReports();
        $groups = $deliveryService->memorandumGroups($overdue);
        $plan = $deliveryService->deliveryPlan($overdue, ComplianceNotificationRun::ALERT_OVERDUE);
        $manualPlan = $deliveryService->deliveryPlan($dueSoon->merge($dueToday)->merge($overdue)->values());
        $effectiveSettings = $settings->effective();
        $currentAlerts = $dueSoon->merge($dueToday)->merge($overdue)->values();
        $readiness = $deliveryService->recipientReadiness($currentAlerts);
        $destinationCoverage = $deliveryService->destinationCoverageSummary($reports->destinationReferences());
        $automaticState = $deliveryService->automaticDeliveryState();
        $today = now(ComplianceAlertSettingsService::TIMEZONE)->toDateString();
        $todayRuns = ComplianceNotificationRun::query()->whereDate('run_date', $today)->latest('id')->get();
        $productionRuns = $todayRuns->whereIn('run_type', [
            ComplianceNotificationRun::TYPE_AUTOMATIC,
            ComplianceNotificationRun::TYPE_MANUAL,
        ]);
        $successfulProductionRuns = ComplianceNotificationRun::query()
            ->whereIn('run_type', [ComplianceNotificationRun::TYPE_AUTOMATIC, ComplianceNotificationRun::TYPE_MANUAL])
            ->where('status', ComplianceNotificationRun::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->get(['id', 'run_type', 'status', 'sent_at', 'payload']);
        $lastSentByLogicalKey = $deliveryService->lastSentByLogicalKey($successfulProductionRuns);
        $decorateGroups = fn (array $groups): array => collect($groups)
            ->map(fn (array $group): array => [...$group, 'last_sent' => $lastSentByLogicalKey[$group['readiness_key'] ?? ''] ?? null])
            ->values()
            ->all();
        $groups = $decorateGroups($groups);
        $runs = ComplianceNotificationRun::query()->with(['createdBy:id,name', 'reports'])->latest('id')->limit(100)->get();
        $pendingRecordsVerification = $reports->pendingRecordsVerification();
        $confirmationHistory = $reports->confirmationHistory();
        $lastRun = $todayRuns->first();
        $requestUser = request()->user()?->can('compliance-alerts.manage');
        $oldestDeadline = $overdue->min('deadline');
        $maximumDaysOverdue = $overdue->max('daysOverdue') ?? 0;
        $nextRun = CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE)->setTimeFromTimeString($effectiveSettings['send_time']);
        if ($nextRun->lessThanOrEqualTo(CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE))) {
            $nextRun = $nextRun->addDay()->setTimeFromTimeString($effectiveSettings['send_time']);
        }
        $activeOverdueSources = $overdue->groupBy('module')
            ->map(fn ($items, string $module): array => ['module' => $module, 'overdue_count' => $items->count(), 'report_not_submitted' => $items->where('complianceIssue', 'Report Not Yet Submitted')->count(), 'missing_mov' => $items->where('complianceIssue', 'MOV Not Yet Submitted')->count()])
            ->sortBy('module')->values();
        $scopePayload = function (string $family) use ($templates, $dueSoon, $dueToday, $overdue, $pendingMov, $deliveryService, $productionRuns, $decorateGroups): array {
            $filter = fn ($reports) => $reports->filter(fn (OverdueReport $report): bool => $templates->familyFor($report) === $family)->values();
            $scopeDueSoon = $filter($dueSoon); $scopeDueToday = $filter($dueToday); $scopeOverdue = $filter($overdue);
            $scopeCurrent = $scopeDueSoon->merge($scopeDueToday)->merge($scopeOverdue)->values();
            $scopeReadiness = $deliveryService->recipientReadiness($scopeCurrent);
            return [
                'groups' => $decorateGroups($deliveryService->memorandumGroups($scopeOverdue)),
                'readiness' => $scopeReadiness,
                'pending_mov_reports' => $filter($pendingMov)->map->toArray()->values(),
                'active_overdue_sources' => $scopeOverdue->groupBy('module')->map(fn ($items, string $module): array => ['module' => $module, 'overdue_count' => $items->count(), 'report_not_submitted' => $items->where('complianceIssue', 'Report Not Yet Submitted')->count(), 'missing_mov' => $items->where('complianceIssue', 'MOV Not Yet Submitted')->count()])->sortBy('module')->values(),
                'summary' => [
                    'due_soon' => $scopeDueSoon->count(), 'due_today' => $scopeDueToday->count(), 'overdue_reports' => $scopeOverdue->count(),
                    'unmapped_destinations' => $scopeReadiness->where('status', 'unmapped')->count(), 'affected_groups' => $scopeReadiness->count(),
                    'sent_today' => $productionRuns->filter(fn (ComplianceNotificationRun $run): bool => data_get($run->payload, 'presentation.family') === $family)->where('status', ComplianceNotificationRun::STATUS_SENT)->count(),
                ],
            ];
        };
        $complianceScopes = [ComplianceAlertTemplateResolver::FAMILY_PROTECTED_AREA => $scopePayload(ComplianceAlertTemplateResolver::FAMILY_PROTECTED_AREA), ComplianceAlertTemplateResolver::FAMILY_ENGP => $scopePayload(ComplianceAlertTemplateResolver::FAMILY_ENGP)];

        return Inertia::render('ComplianceAlerts/Index', [
            'view' => 'operational',
            'groups' => $groups,
            'deliveryPlan' => [
                'deliveries' => $plan['deliveries']->map(fn (array $deliveryItem) => [
                    'recipient' => $deliveryItem['recipient']->toArray(),
                    'report_count' => $deliveryItem['reports']->count(),
                    'groups' => $deliveryService->memorandumGroups($deliveryItem['reports']),
                ])->values(),
                'unmapped' => $deliveryService->memorandumGroups($plan['unmapped']),
            ],
            'manualDeliveryPlan' => [
                'deliveries' => $manualPlan['deliveries']->map(fn (array $deliveryItem) => [
                    'recipient' => $deliveryItem['recipient']->toArray(),
                    'report_count' => $deliveryItem['reports']->count(),
                ])->values(),
                'unmapped' => $deliveryService->memorandumGroups($manualPlan['unmapped']),
            ],
            'recipientReadiness' => $readiness,
            'complianceScopes' => $complianceScopes,
            'monitoredSources' => collect($reports->sourceDefinitions())
                ->map(fn (array $definition, string $sourceType) => ['source_type' => $sourceType, 'module' => $definition['module']])
                ->values(),
            'activeOverdueSources' => $activeOverdueSources,
            'summary' => [
                'overdue_reports' => $overdue->count(),
                'due_soon' => $dueSoon->count(),
                'due_today' => $dueToday->count(),
                'report_not_submitted' => $overdue->where('complianceIssue', 'Report Not Yet Submitted')->count(),
                'missing_mov' => $overdue->where('complianceIssue', 'MOV Not Yet Submitted')->count(),
                'pending_mov' => $pendingMov->count(),
                'affected_groups' => count($groups),
                'sent_today' => $productionRuns->where('status', ComplianceNotificationRun::STATUS_SENT)->count(),
                'failed_today' => $productionRuns->where('status', ComplianceNotificationRun::STATUS_FAILED)->count(),
                // Alert-candidate coverage remains separate from configuration coverage.
                'unmapped_recipients' => $manualPlan['unmapped']->count(),
                'unmapped_destinations' => $destinationCoverage['unmapped'],
                'ready_recipients' => $currentAlerts->count() - $manualPlan['unmapped']->count(),
                'active_recipient_mappings' => ComplianceAlertRecipient::query()->where('is_active', true)->count(),
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
            'dueSoonReports' => $dueSoon->map->toArray()->values(),
            'dueTodayReports' => $dueToday->map->toArray()->values(),
            'confirmationHistory' => $confirmationHistory,
            'recipients' => $requestUser ? ComplianceAlertRecipient::query()->with('protectedArea:id,name')->latest('id')->get()->map(fn (ComplianceAlertRecipient $recipient) => $this->recipientPayload($recipient)) : [],
            'protectedAreas' => $requestUser ? ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name']) : [],
            'settings' => $requestUser ? $effectiveSettings : null,
            'nonWorkingDays' => $requestUser ? NonWorkingDay::query()->latest('date')->latest('id')->get()->map(fn (NonWorkingDay $day) => $this->nonWorkingDayPayload($day))->values() : [],
            'safeMode' => ! config('compliance_alerts.enabled') || ! (bool) $effectiveSettings['alerts_enabled'],
            'automaticDeliveryState' => $automaticState,
        ]);
    }

    public function settings(OverdueReportService $reports, ComplianceAlertDeliveryService $deliveryService, ComplianceAlertSettingsService $settings, ComplianceAlertTemplateResolver $templates): Response
    {
        $savedSettings = $settings->effective();
        $effectiveSettings = [...$savedSettings, 'template_settings' => $templates->templateSettings($savedSettings)];
        $dueSoon = $reports->dueSoonReports((int) config('notifications.due_soon_days', 3));
        $overdue = $reports->overdueReports();
        $references = $reports->destinationReferences();
        $coverage = $deliveryService->destinationCoverageSummary($references);
        $destinationCards = $deliveryService->destinationCards($references, $dueSoon, $overdue);
        $settingsPayload = Arr::except($effectiveSettings, [
            'sender_display_name', 'to_label', 'attention_line', 'fallback_recipient_email', 'fallback_cc_emails',
            'recipients', 'cc_recipients', 'attention',
        ]);

        return Inertia::render('ComplianceAlerts/Index', [
            'view' => 'settings',
            'settings' => [...$settingsPayload, 'mail_from_name' => config('mail.from.name')],
            'monitoredSources' => collect($reports->sourceDefinitions())->map(fn (array $definition, string $sourceType): array => ['source_type' => $sourceType, 'module' => $definition['module']])->values(),
            'automaticDeliveryState' => $deliveryService->automaticDeliveryState(),
            'recipientReadiness' => $deliveryService->recipientReadiness($dueSoon->merge($overdue)->values()),
            'destinationCards' => $destinationCards,
            'mappingCoverage' => $coverage['coverage'],
            'mappingMetrics' => ['active_mappings' => $destinationCards->count(), 'mapped' => $coverage['mapped'], 'unmapped' => $coverage['unmapped'], 'total' => $coverage['total']],
        ]);
    }

    public function recipientMapping(OverdueReportService $reports, ComplianceAlertDeliveryService $delivery): Response
    {
        $destinationCoverage = $delivery->destinationCoverageSummary($reports->destinationReferences());
        return Inertia::render('ComplianceAlerts/Index', [
            'view' => 'recipients',
            'recipients' => ComplianceAlertRecipient::query()->with('protectedArea:id,name')->latest('id')->get()->map(fn (ComplianceAlertRecipient $recipient) => $this->recipientPayload($recipient)),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name']),
            'mappingCoverage' => $destinationCoverage['coverage'],
            'mappingMetrics' => ['active_mappings' => ComplianceAlertRecipient::query()->where('is_active', true)->count(), 'mapped' => $destinationCoverage['mapped'], 'unmapped' => $destinationCoverage['unmapped'], 'total' => $destinationCoverage['total']],
        ]);
    }

    public function businessCalendar(Request $request, CalendarMovEventService $calendarEvents): Response
    {
        $view = $request->string('view')->toString() === 'year' ? 'year' : 'month';
        $monthValue = $request->string('month')->toString();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue)
            ? CarbonImmutable::createFromFormat('!Y-m', $monthValue, BusinessCalendarService::TIMEZONE)
            : CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->startOfMonth();
        $yearValue = $request->string('year')->toString();
        $year = preg_match('/^\d{4}$/', $yearValue) ? (int) $yearValue : $month->year;
        if ($view === 'year') {
            $month = $month->setYear($year);
        }
        $modules = collect($calendarEvents->modules($request->user()));
        $module = $request->string('module')->toString();
        $module = $modules->contains('key', $module) ? $module : null;
        $protectedAreaId = $request->filled('protected_area_id') && ProtectedArea::query()->whereKey($request->integer('protected_area_id'))->exists()
            ? $request->integer('protected_area_id')
            : null;

        $nonWorkingDays = NonWorkingDay::query()
            ->whereBetween('date', [
                $view === 'year' ? CarbonImmutable::create($year, 1, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE)->toDateString() : $month->startOfMonth()->toDateString(),
                $view === 'year' ? CarbonImmutable::create($year, 12, 31, 0, 0, 0, BusinessCalendarService::TIMEZONE)->toDateString() : $month->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn (NonWorkingDay $day) => $this->nonWorkingDayPayload($day))->values();
        $yearSummary = $view === 'year'
            ? $calendarEvents->yearSummary($request->user(), CarbonImmutable::create($year, 1, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE), $module, $protectedAreaId)
            : null;
        if ($yearSummary !== null) {
            $yearSummary['overview']['non_working_days'] = $nonWorkingDays->count();
        }

        return Inertia::render('Calendar/Index', [
            'view' => $view,
            'year' => $year,
            'month' => $month->format('Y-m'),
            'filters' => ['module' => $module, 'protected_area_id' => $protectedAreaId],
            'modules' => $modules->values(),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->values(),
            'movEvents' => $view === 'month' ? $calendarEvents->events($request->user(), $month, $module, $protectedAreaId) : [],
            'yearSummary' => $yearSummary,
            'nonWorkingDays' => $nonWorkingDays,
        ]);
    }

    public function preview(Request $request, ComplianceAlertDeliveryService $deliveryService)
    {
        $wantsJson = $request->expectsJson() || $request->ajax();
        $data = $request->validate([
            'destination_key' => ['required', 'string', 'max:191'],
            'alert_type' => ['required', Rule::in([ComplianceNotificationRun::ALERT_DUE_SOON, ComplianceNotificationRun::ALERT_OVERDUE])],
        ]);
        $destinationReports = $deliveryService->reportsForDestination($data['destination_key'], $data['alert_type']);
        if ($destinationReports->isEmpty()) {
            $message = 'No qualifying reports for this alert type.';
            return $wantsJson ? response()->json(['message' => $message], 422) : redirect()->route('compliance-alerts.index')->withErrors(['preview' => $message]);
        }

        $memorandum = $deliveryService->memorandumForDestination($data['destination_key'], $data['alert_type']);
        if (! $memorandum) {
            $message = 'No active recipient mapping is available for this destination.';
            return $wantsJson ? response()->json(['message' => $message], 422) : redirect()->route('compliance-alerts.index')->withErrors(['preview' => $message]);
        }

        return $this->memorandumPreviewResponse($wantsJson, $memorandum);
    }

    public function send(Request $request, ComplianceAlertDeliveryService $delivery): RedirectResponse
    {
        $runs = $delivery->sendManualCurrentAlerts($request->user());
        app(AuditLogService::class)->record('compliance_alerts', 'Manual Compliance Alert Delivery', null, null, 'Compliance Alerts', 'Processed a manual production compliance alert delivery request.', ['failed' => $runs->where('status', ComplianceNotificationRun::STATUS_FAILED)->count()], $request->user()->id);

        if ($runs->contains('status', ComplianceNotificationRun::STATUS_FAILED)) {
            return back()->withErrors(['delivery' => 'One or more compliance alert deliveries failed. See notification history for status.']);
        }

        return back()->with('success', 'Compliance notification delivery completed.');
    }

    public function test(Request $request, ComplianceAlertDeliveryService $delivery): RedirectResponse
    {
        $data = $request->validate([
            'destination_key' => ['required', 'string', 'max:191'],
            'alert_type' => ['required', Rule::in([ComplianceNotificationRun::ALERT_DUE_SOON, ComplianceNotificationRun::ALERT_OVERDUE])],
        ]);
        $run = $delivery->sendTest($data['destination_key'], $data['alert_type'], $request->user());
        app(AuditLogService::class)->record('compliance_alerts', 'Compliance Alert Test Delivery', null, null, 'Compliance Alerts', 'Processed a mapped-destination compliance alert test delivery request.', ['alert_type' => $data['alert_type'], 'status' => $run->status], $request->user()->id);

        if ($run->status === ComplianceNotificationRun::STATUS_FAILED) {
            return back()->withErrors(['test' => $run->error_message ?: 'Test email delivery failed.']);
        }

        return back()->with('success', 'Compliance alert test delivery completed for the selected mapped destination.');
    }

    public function storeRecipient(Request $request): RedirectResponse
    {
        $data = $this->recipientData($request);
        $this->ensureNoActiveDuplicate($data);
        $this->persistRecipient(fn () => ComplianceAlertRecipient::query()->create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]));
        app(AuditLogService::class)->record('recipient_mapping', 'Recipient Mapping Created', ComplianceAlertRecipient::class, ComplianceAlertRecipient::query()->latest('id')->value('id'), 'Recipient Mapping', 'Created a recipient mapping.', ['scope' => $data['protected_area_id'] ? 'protected_area' : $data['target_office']]);

        return back()->with('success', 'Compliance alert recipient saved.');
    }

    public function updateRecipient(Request $request, ComplianceAlertRecipient $recipient): RedirectResponse
    {
        $data = $this->recipientData($request);
        $this->ensureNoActiveDuplicate($data, $recipient->id);
        $before = $recipient->only(array_keys($data));
        $this->persistRecipient(fn () => $recipient->update([...$data, 'updated_by' => $request->user()->id]));
        app(AuditLogService::class)->record('recipient_mapping', 'Recipient Mapping Updated', ComplianceAlertRecipient::class, $recipient->id, 'Recipient Mapping', 'Updated a recipient mapping.', ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Compliance alert recipient updated.');
    }

    public function toggleRecipient(Request $request, ComplianceAlertRecipient $recipient): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $scope = $this->recipientScopeData($recipient);
        if ($data['is_active']) {
            $this->ensureNoActiveDuplicate([
                ...$scope,
                'is_active' => true,
            ], $recipient->id);
        }
        $old = $recipient->is_active;
        $updates = ['is_active' => $data['is_active'], 'updated_by' => $request->user()->id];
        if (! $recipient->protected_area_id) {
            $updates['target_office'] = $scope['target_office'];
            $updates['target_office_key'] = $scope['target_office_key'];
        }
        $this->persistRecipient(fn () => $recipient->update($updates));
        app(AuditLogService::class)->record('recipient_mapping', $recipient->is_active ? 'Recipient Mapping Activated' : 'Recipient Mapping Deactivated', ComplianceAlertRecipient::class, $recipient->id, 'Recipient Mapping', 'Changed recipient mapping status.', ['old' => $old, 'new' => $recipient->is_active]);

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
        app(AuditLogService::class)->record('recipient_mapping', 'Recipient Mapping Deleted', ComplianceAlertRecipient::class, $recipient->id, 'Recipient Mapping', 'Deleted an unused recipient mapping.');

        return back()->with('success', 'Unused compliance alert recipient deleted.');
    }

    private function memorandumPreviewResponse(bool $json, array $memorandum)
    {
        $presentation = $memorandum['presentation'];
        $recipient = $memorandum['recipient'];
        $groups = $memorandum['groups'];
        $payload = [
            'subject' => $memorandum['subject'],
            'html' => $memorandum['html'],
            'template_type' => $presentation['template'] ?? null,
            'recipient' => [
                'name' => $recipient['name'] ?? $recipient['recipient_name'] ?? null,
                'email' => $recipient['email'] ?? $recipient['recipient_email'] ?? null,
                'destination' => $recipient['destination'] ?? null,
            ],
            'meta' => [
                'alert_type' => $memorandum['alert_type'],
                'group_count' => count($groups),
                'report_count' => collect($groups)->sum(fn (array $group): int => count($group['reports'] ?? [])),
            ],
        ];

        return $json
            ? response()->json($payload)
            : response($memorandum['html'])->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function updateSettings(Request $request, ComplianceAlertSettingsService $settings, ComplianceAlertDeliveryService $deliveryService, ComplianceAlertTemplateResolver $templates, ComplianceRichTextSanitizer $richText): RedirectResponse
    {
        $current = $settings->effective();
        $data = $request->validate([
            'alerts_enabled' => ['required', 'boolean'], 'automatic_send_enabled' => ['required', 'boolean'],
            'send_time' => ['required', 'date_format:H:i'], 'timezone' => ['required', Rule::in([ComplianceAlertSettingsService::TIMEZONE])],
            'email_subject' => ['required', 'string', 'max:255'], 'from_line' => ['required', 'string', 'max:255'],
            'memorandum_subject' => ['required', 'string', 'max:255'], 'introductory_text' => ['required', 'string', 'max:5000'],
            'compliance_warning_text' => ['required', 'string', 'max:5000'], 'strict_compliance_text' => ['required', 'string', 'max:5000'],
            'signatory_name' => ['required', 'string', 'max:255'], 'signatory_position' => ['required', 'string', 'max:255'],
            'office_name' => ['required', 'string', 'max:255'], 'office_address' => ['required', 'string', 'max:255'],
            'focal_person_name' => ['nullable', 'string', 'max:255'], 'focal_person_position' => ['nullable', 'string', 'max:255'],
            'focal_person_contact' => ['nullable', 'string', 'max:2000'], 'do_not_reply_text' => ['required', 'string', 'max:1000'],
            'system_generated_footer_text' => ['required', 'string', 'max:2000'],
            'template_settings' => ['nullable', 'array'],
            'template_settings.*' => ['array'],
            'template_settings.*.*' => ['nullable', 'string', 'max:5000'],
            'current_password' => ['nullable', 'current_password:web'],
        ]);
        $automaticChanged = (bool) $data['automatic_send_enabled'] !== (bool) $current['automatic_send_enabled'];
        if ($automaticChanged) {
            abort_unless($request->user()?->hasRole('CDS Admin'), 403);
        }
        if ($automaticChanged && ! $request->filled('current_password')) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is required when changing automatic email delivery.']);
        }
        if ($data['automatic_send_enabled'] && ! $deliveryService->automaticDeliveryState()['environment_gate']) {
            throw ValidationException::withMessages(['automatic_send_enabled' => 'Automatic delivery cannot be enabled because the server delivery gate is disabled.']);
        }
        if ($data['automatic_send_enabled'] && ! $deliveryService->automaticDeliveryState()['mail_configured']) {
            throw ValidationException::withMessages(['automatic_send_enabled' => 'Automatic delivery cannot be enabled because the mail configuration is unavailable or invalid.']);
        }
        unset($data['current_password']);
        foreach (['introductory_text', 'compliance_warning_text', 'strict_compliance_text', 'do_not_reply_text', 'system_generated_footer_text'] as $field) {
            $data[$field] = $richText->sanitize($data[$field]);
        }
        $data['email_subject'] = $richText->plainText($data['email_subject']);
        $data['memorandum_subject'] = $richText->plainText($data['memorandum_subject']);
        $data['template_settings'] = $templates->templateSettings([...$current, 'template_settings' => $data['template_settings'] ?? []]);
        $settings->update($data);
        app(AuditLogService::class)->record('compliance_alerts', 'Compliance Alert Settings Updated', ComplianceAlertSetting::class, $settings->record()->id, 'Compliance Alerts', 'Updated compliance alert settings.', ['fields' => array_keys($data)]);
        if ($automaticChanged) {
            app(AuditLogService::class)->record(
                'compliance_alerts',
                $data['automatic_send_enabled'] ? 'Automatic Compliance Alert Delivery Enabled' : 'Automatic Compliance Alert Delivery Disabled',
                ComplianceAlertSetting::class,
                $settings->record()->id,
                'Compliance Alerts',
                'Changed the automatic compliance alert email delivery setting.',
                ['previous' => (bool) $current['automatic_send_enabled'], 'new' => (bool) $data['automatic_send_enabled']],
                $request->user()->id,
            );
        }

        return back()->with('success', 'Compliance alert settings updated.');
    }

    public function storeNonWorkingDay(Request $request): RedirectResponse
    {
        $data = $this->nonWorkingDayData($request);
        try {
            NonWorkingDay::query()->create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        } catch (QueryException $exception) {
            $this->throwNonWorkingDayDuplicate($exception);
        }

        return back()->with('success', 'Non-working day saved.');
    }

    public function updateNonWorkingDay(Request $request, NonWorkingDay $nonWorkingDay): RedirectResponse
    {
        $data = $this->nonWorkingDayData($request);
        try {
            $nonWorkingDay->update([...$data, 'updated_by' => $request->user()->id]);
        } catch (QueryException $exception) {
            $this->throwNonWorkingDayDuplicate($exception);
        }

        return back()->with('success', 'Non-working day updated.');
    }

    public function destroyNonWorkingDay(NonWorkingDay $nonWorkingDay): RedirectResponse
    {
        $nonWorkingDay->delete();

        return back()->with('success', 'Non-working day deleted.');
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

    /** @return array{protected_area_id:?int,target_office:?string,target_office_key:?string} */
    private function recipientScopeData(ComplianceAlertRecipient $recipient): array
    {
        if ($recipient->protected_area_id) {
            return [
                'protected_area_id' => $recipient->protected_area_id,
                'target_office' => $recipient->target_office,
                'target_office_key' => $recipient->target_office_key,
            ];
        }

        $office = $this->normalizeTargetOffice($recipient->target_office);
        if (! $office['key']) {
            throw ValidationException::withMessages([
                'target_office' => 'This office mapping has no valid target office and cannot be activated.',
            ]);
        }

        return [
            'protected_area_id' => null,
            'target_office' => $office['label'],
            'target_office_key' => $office['key'],
        ];
    }

    /** @return array{key:?string,label:?string} */
    private function normalizeTargetOffice(?string $office): array
    {
        return app(\App\Services\Compliance\TargetOfficeNormalizer::class)->normalize(
            filled($office) ? trim($office) : null,
        );
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
        if ($data['target_office']) {
            $office = $this->normalizeTargetOffice($data['target_office']);
            $data['target_office'] = $office['label'];
            $data['target_office_key'] = $office['key'];
        } else {
            $data['target_office_key'] = null;
        }
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

    /** @return array<string, mixed> */
    private function nonWorkingDayData(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
                NonWorkingDay::TYPE_LOCAL_HOLIDAY,
                NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY,
                NonWorkingDay::TYPE_OFFICE_DECLARED_NON_WORKING_DAY,
                NonWorkingDay::TYPE_OTHER,
            ])],
            'scope' => ['required', Rule::in([NonWorkingDay::SCOPE_NATIONAL, NonWorkingDay::SCOPE_DAVAO_ORIENTAL, NonWorkingDay::SCOPE_OFFICE])],
            'location' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['date'] = CarbonImmutable::parse($data['date'], 'Asia/Manila')->toDateString();
        $data['name'] = trim($data['name']);
        $data['location'] = $data['scope'] === NonWorkingDay::SCOPE_OFFICE ? trim((string) ($data['location'] ?? '')) : '';
        if ($data['scope'] === NonWorkingDay::SCOPE_OFFICE && $data['location'] === '') {
            throw ValidationException::withMessages(['location' => 'An office location is required for an office-declared non-working day.']);
        }

        return $data;
    }

    private function throwNonWorkingDayDuplicate(QueryException $exception): never
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'non_working_days_date_scope_location_unique')
            || str_contains($message, 'unique constraint failed: non_working_days.date, non_working_days.scope, non_working_days.location')) {
            throw ValidationException::withMessages(['date' => 'A non-working-day entry already exists for this date and scope.']);
        }

        throw $exception;
    }

    /** @return array<string, mixed> */
    private function nonWorkingDayPayload(NonWorkingDay $day): array
    {
        return [
            'id' => $day->id, 'date' => $day->date?->toDateString(), 'name' => $day->name, 'type' => $day->type,
            'scope' => $day->scope, 'location' => $day->location, 'reference' => $day->reference, 'remarks' => $day->remarks,
            'is_active' => $day->is_active, 'created_at' => $day->created_at?->toIso8601String(), 'updated_at' => $day->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $data */
    private function ensureNoActiveDuplicate(array $data, ?int $ignoreId = null): void
    {
        if (! ($data['is_active'] ?? false)) {
            return;
        }
        $isProtectedArea = filled($data['protected_area_id'] ?? null);
        if (! $isProtectedArea && blank($data['target_office_key'] ?? null)) {
            throw ValidationException::withMessages([
                'target_office' => 'A valid canonical target office is required for this recipient mapping.',
            ]);
        }
        $query = ComplianceAlertRecipient::query()->where('is_active', true)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));
        $duplicate = $isProtectedArea
            ? $query->where('protected_area_id', $data['protected_area_id'])->exists()
            : $query->whereNull('protected_area_id')->where('target_office_key', $data['target_office_key'])->exists();
        if ($duplicate) {
            $field = $isProtectedArea ? 'protected_area_id' : 'target_office';
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
            'target_office' => $recipient->target_office, 'target_office_key' => $recipient->target_office_key, 'scope' => $recipient->protected_area_id ? 'protected_area' : 'target_office', 'recipient_name' => $recipient->recipient_name, 'attention_line' => $recipient->attention_line, 'recipient_email' => $recipient->recipient_email,
            'cc_emails' => $recipient->cc_emails ?? [], 'is_active' => $recipient->is_active, 'notes' => $recipient->notes,
            'created_at' => $recipient->created_at?->toIso8601String(), 'updated_at' => $recipient->updated_at?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function runPayload(ComplianceNotificationRun $run): array
    {
        return ['id' => $run->id, 'run_date' => $run->run_date?->toDateString(), 'run_type' => $run->run_type,
            'status' => $run->status, 'idempotency_key' => $run->idempotency_key, 'report_count' => $run->report_count, 'recipients' => $run->recipients, 'cc_recipients' => $run->cc_recipients,
            'sent_at' => $run->sent_at?->toIso8601String(), 'error_message' => $run->error_message, 'created_by' => $run->createdBy?->name,
            'payload' => $run->payload, 'scope' => data_get($run->payload, 'presentation.family'), 'reports' => $run->reports->map(fn ($report) => $report->snapshot)->filter()->values()];
    }
}
