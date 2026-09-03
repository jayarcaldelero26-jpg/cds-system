<?php

namespace App\Services\Compliance;

use App\Mail\OverdueComplianceMemorandum;
use App\Models\ComplianceDeliveryClaim;
use App\Models\ComplianceNotificationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ComplianceAlertDeliveryService
{
    public function __construct(
        private readonly OverdueReportService $reports,
        private readonly RecipientMappingResolver $recipients,
        private readonly ComplianceAlertSettingsService $settings,
        private readonly ComplianceAlertTemplateResolver $templates,
    ) {}

    /** @return Collection<int, ComplianceNotificationRun> */
    public function sendAutomatic(): Collection
    {
        return collect($this->currentAlertBuckets())
            ->reduce(
                fn (Collection $runs, Collection $reports, string $alertType): Collection => $runs->merge($this->dispatchAutomatic($reports, $alertType)),
                collect(),
            );
    }

    /** @return array<string, Collection<int, OverdueReport>> */
    public function currentAlertBuckets(): array
    {
        return [
            ComplianceNotificationRun::ALERT_DUE_SOON => $this->reports->dueSoonReports((int) config('notifications.due_soon_days', 3)),
            ComplianceNotificationRun::ALERT_DUE_TODAY => $this->reports->dueTodayReports(),
            ComplianceNotificationRun::ALERT_OVERDUE => $this->reports->overdueReports(),
        ];
    }

    /** @return Collection<int, OverdueReport> */
    public function currentAlertReports(): Collection
    {
        return collect($this->currentAlertBuckets())->flatten(1)->values();
    }

    /** @return Collection<int, OverdueReport> */
    public function alertReportsFor(string $alertType): Collection
    {
        return $this->currentAlertBuckets()[$alertType] ?? collect();
    }

    /** @return Collection<int, OverdueReport> */
    public function reportsForDestination(string $destinationKey, string $alertType): Collection
    {
        return $this->alertReportsFor($alertType)
            ->filter(fn (OverdueReport $report): bool => $this->recipients->logicalKey($report) === $destinationKey)
            ->values();
    }

    /** @param Collection<int,array<string,mixed>> $references @param Collection<int,OverdueReport> $dueSoon @param Collection<int,OverdueReport> $overdue */
    public function destinationCards(Collection $references, Collection $dueSoon, Collection $overdue): Collection
    {
        return $this->recipients->destinationCards($references, $dueSoon, $overdue);
    }

    /**
     * Build the exact memorandum payload consumed by preview, manual, and
     * automatic delivery for one logical destination.
     *
     * @return array{groups:array<int,array<string,mixed>>,settings:array<string,mixed>,recipient:array<string,mixed>,alert_type:string,presentation:array<string,mixed>,subject:string,html:string}|null
     */
    public function memorandumForDestination(string $destinationKey, string $alertType): ?array
    {
        if (! in_array($alertType, [ComplianceNotificationRun::ALERT_DUE_SOON, ComplianceNotificationRun::ALERT_OVERDUE], true)) {
            return null;
        }

        $plan = $this->deliveryPlan($this->alertReportsFor($alertType), $alertType);
        $selected = $plan['deliveries']->first(fn (array $delivery): bool => $delivery['reports']->isNotEmpty()
            && $this->recipients->logicalKey($delivery['reports']->first()) === $destinationKey);

        return $selected
            ? $this->buildMemorandum($selected['reports'], $selected['recipient'], $alertType)
            : null;
    }

    /** @return Collection<int, ComplianceNotificationRun> */
    public function sendManualCurrentAlerts(User $user): Collection
    {
        if (! $this->manualDeliveryEnabled()) {
            throw ValidationException::withMessages(['delivery' => 'Production delivery is unavailable because alerts, the server delivery gate, or mail configuration is not ready.']);
        }

        $plans = collect($this->currentAlertBuckets())->mapWithKeys(fn (Collection $reports, string $alertType) => [$alertType => $this->deliveryPlan($reports, $alertType)]);

        return $plans->reduce(
            fn (Collection $runs, array $plan, string $alertType): Collection => $runs
                ->merge($this->recordManualUnmapped($plan['unmapped'], $user, $alertType))
                ->merge($this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_MANUAL, $user, $alertType)),
            collect(),
        );
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    public function sendManual(Collection $reports, User $user): Collection
    {
        $plan = $this->deliveryPlan($reports, ComplianceNotificationRun::ALERT_OVERDUE);
        if ($plan['unmapped']->isNotEmpty()) {
            throw ValidationException::withMessages(['recipients' => 'Recipient mapping is missing for one or more overdue Protected Area / office groups. Add a recipient mapping before sending.']);
        }
        if (! $this->manualDeliveryEnabled()) {
            throw ValidationException::withMessages(['delivery' => 'Production delivery is unavailable because alerts, the server delivery gate, or mail configuration is not ready.']);
        }

        return $this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_MANUAL, $user, ComplianceNotificationRun::ALERT_OVERDUE);
    }

    /** @param Collection<int, OverdueReport> $reports @return array{deliveries: Collection<int, array<string,mixed>>, unmapped: Collection<int, OverdueReport>} */
    public function deliveryPlan(Collection $reports, ?string $alertType = null): array
    {
        return $this->recipients->plans($reports, $alertType);
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, array<string,mixed>> */
    public function recipientReadiness(Collection $reports, ?string $alertType = null): Collection
    {
        return $this->recipients->readiness($reports, $alertType);
    }

    /**
     * Return the latest successful production delivery timestamp for each
     * logical destination represented in notification history.
     *
     * The run payload stores the same readiness key used by the current
     * delivery plan, so the compliance table can join history to its rows
     * without resolving recipients through a second, unrelated path.
     *
     * @param Collection<int, ComplianceNotificationRun> $runs
     * @return array<string, string>
     */
    public function lastSentByLogicalKey(Collection $runs): array
    {
        $lastSent = [];

        foreach ($runs
            ->filter(fn (ComplianceNotificationRun $run): bool => in_array($run->run_type, [
                ComplianceNotificationRun::TYPE_AUTOMATIC,
                ComplianceNotificationRun::TYPE_MANUAL,
            ], true) && $run->status === ComplianceNotificationRun::STATUS_SENT && $run->sent_at !== null)
            ->sortByDesc(fn (ComplianceNotificationRun $run): int => $run->sent_at?->getTimestamp() ?? 0) as $run) {
            $sentAt = $run->sent_at?->toIso8601String();
            if ($sentAt === null) {
                continue;
            }

            foreach (data_get($run->payload, 'groups', []) as $group) {
                $key = trim((string) ($group['readiness_key'] ?? ''));
                if ($key !== '' && ! array_key_exists($key, $lastSent)) {
                    $lastSent[$key] = $sentAt;
                }
            }
        }

        return $lastSent;
    }

    /** @param Collection<int,array<string,mixed>> $references */
    public function destinationCoverage(Collection $references): Collection { return $this->recipients->coverage($references); }

    /**
     * The authoritative coverage of distinct destinations referenced by active report workflows.
     *
     * @param Collection<int,array<string,mixed>> $references
     * @return array{coverage: Collection<int,array<string,mixed>>, mapped: int, unmapped: int, total: int}
     */
    public function destinationCoverageSummary(Collection $references): array
    {
        $coverage = $this->destinationCoverage($references);

        return [
            'coverage' => $coverage,
            'mapped' => $coverage->where('status', 'mapped')->count(),
            'unmapped' => $coverage->where('status', 'unmapped')->count(),
            'total' => $coverage->count(),
        ];
    }

    /** @return array{environment_gate: bool,operational_setting: bool,mail_configured: bool,effective: bool} */
    public function automaticDeliveryState(): array
    {
        $settings = $this->settings->effective();
        $environment = (bool) config('compliance_alerts.enabled');
        $operational = (bool) $settings['alerts_enabled'] && (bool) $settings['automatic_send_enabled'];
        $mailer = strtolower(trim((string) config('mail.default')));
        $from = (string) config('mail.from.address');
        $mailConfigured = match ($mailer) {
            'smtp' => filter_var($from, FILTER_VALIDATE_EMAIL) !== false
                && filled(config('mail.mailers.smtp.host'))
                && filter_var(config('mail.mailers.smtp.port'), FILTER_VALIDATE_INT) !== false
                && filled(config('mail.mailers.smtp.username'))
                && filled(config('mail.mailers.smtp.password')),
            'log' => ! app()->environment('production'),
            default => $mailer !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false,
        };

        return ['environment_gate' => $environment, 'operational_setting' => $operational, 'mail_configured' => $mailConfigured, 'effective' => $environment && $operational && $mailConfigured];
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    private function dispatchAutomatic(Collection $reports, string $alertType): Collection
    {
        if ($reports->isEmpty()) {
            return collect();
        }

        $plan = $this->deliveryPlan($reports, $alertType);
        $runs = collect();
        foreach ($this->groupUnmapped($plan['unmapped']) as $group) {
            $runs->push($this->recordUnmapped($group, $alertType));
        }

        if (! $this->automaticDeliveryEnabled()) {
            return $runs->merge($this->recordDisabled($plan['deliveries'], $alertType));
        }

        return $runs->merge($this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_AUTOMATIC, null, $alertType));
    }

    /** @param Collection<int, array{recipient: ResolvedComplianceRecipient,reports: Collection<int,OverdueReport>}> $deliveries @return Collection<int, ComplianceNotificationRun> */
    private function dispatchDeliveries(Collection $deliveries, string $type, ?User $user, string $alertType): Collection
    {
        return $deliveries->map(function (array $delivery) use ($type, $user, $alertType): ?ComplianceNotificationRun {
            $recipient = $delivery['recipient'];
            $reports = $delivery['reports'];
            if ($reports->isEmpty()) {
                return null;
            }

            $settings = $this->settings->effective();
            $today = CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE)->toDateString();
            $memorandum = $this->buildMemorandum($reports, $recipient, $alertType, $settings);
            $groups = $memorandum['groups'];
            $idempotencyKey = null;
            if (in_array($type, [ComplianceNotificationRun::TYPE_MANUAL, ComplianceNotificationRun::TYPE_AUTOMATIC], true)) {
                ['key' => $idempotencyKey, 'fingerprint' => $fingerprint] = $this->deliveryIdentity(
                    $today,
                    $type, $alertType,
                    $recipient,
                    $reports,
                    $settings,
                );
                $claim = $this->acquireDeliveryClaim($idempotencyKey, $fingerprint, $today, $type, $recipient->key);
                if (! $claim['acquired']) {
                    $reason = $claim['status'] === ComplianceDeliveryClaim::STATUS_SENT
                        ? 'Already sent: the successful destination delivery claim prevents a duplicate memorandum.'
                        : 'Skipped: another worker currently owns this destination delivery claim.';

                    return $this->createRun(
                        $today,
                        $recipient,
                        $reports,
                        $groups,
                        $settings,
                        $type,
                        $user,
                        ComplianceNotificationRun::STATUS_SKIPPED,
                        $reason,
                         $idempotencyKey, $alertType,
                     );
                }
            }

            try {
                $run = $this->createRun(
                    $today,
                    $recipient,
                    $reports,
                    $groups,
                    $settings,
                    $type,
                    $user,
                    idempotencyKey: $idempotencyKey, alertType: $alertType, memorandum: $memorandum,
                );
            } catch (\Throwable $exception) {
                if ($idempotencyKey !== null) {
                    $this->completeDeliveryClaim($idempotencyKey, ComplianceDeliveryClaim::STATUS_FAILED);
                }
                throw $exception;
            }

            try {
                $pending = Mail::to($recipient->email);
                if ($recipient->ccEmails !== []) {
                    $pending->cc($recipient->ccEmails);
                }
                $pending->send(new OverdueComplianceMemorandum($memorandum['groups'], $memorandum['settings'], $recipient->toArray(), '', $alertType, $memorandum['presentation']));
            } catch (\Throwable $exception) {
                Log::error('Compliance email delivery failed.', [
                    'exception' => $exception::class,
                    'message' => $this->redactTransportMessage($exception->getMessage()),
                ]);
                $run->update(['status' => ComplianceNotificationRun::STATUS_FAILED, 'error_message' => 'Email delivery failed. See application logs for technical details.']);
                if ($idempotencyKey !== null) {
                    $this->completeDeliveryClaim($idempotencyKey, ComplianceDeliveryClaim::STATUS_FAILED, $run);
                }

                return $run;
            }

            // Once the transport reports success, lock the claim as sent before updating presentation history.
            // If a later database write fails, the processing/sent claim remains non-retryable rather than risking a duplicate email.
            if ($idempotencyKey !== null) {
                $this->completeDeliveryClaim($idempotencyKey, ComplianceDeliveryClaim::STATUS_SENT, $run);
            }
            $run->update(['status' => ComplianceNotificationRun::STATUS_SENT, 'sent_at' => CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE), 'error_message' => null]);

            return $run;
        })->filter()->values();
    }

    /** @param Collection<int, array{recipient: ResolvedComplianceRecipient,reports: Collection<int,OverdueReport>}> $deliveries @return Collection<int, ComplianceNotificationRun> */
    private function recordDisabled(Collection $deliveries, string $alertType, string $reason = 'Automatic delivery is disabled by safe mode or operational settings.'): Collection
    {
        return $deliveries->map(function (array $delivery) use ($reason, $alertType): ComplianceNotificationRun {
            $settings = $this->settings->effective();
            return $this->createRun(
                CarbonImmutable::now($settings['timezone'])->toDateString(), $delivery['recipient'], $delivery['reports'],
                $this->memorandumGroups($delivery['reports']), $settings, ComplianceNotificationRun::TYPE_AUTOMATIC, null,
                ComplianceNotificationRun::STATUS_SKIPPED, $reason, alertType: $alertType,
            );
        });
    }

    /** @param Collection<int, OverdueReport> $reports */
    private function recordUnmapped(Collection $reports, string $alertType): ComplianceNotificationRun
    {
        $settings = $this->settings->effective();
        $key = 'unmapped:'.hash('sha256', $reports->map(fn (OverdueReport $report) => $report->sourceType.':'.$report->sourceId)->join('|'));
        $recipient = new ResolvedComplianceRecipient($key, '', [], null, 'unmapped');

        return $this->createRun(
            CarbonImmutable::now($settings['timezone'])->toDateString(), $recipient, $reports, $this->memorandumGroups($reports),
            $settings, ComplianceNotificationRun::TYPE_AUTOMATIC, null,
            ComplianceNotificationRun::STATUS_SKIPPED, 'Recipient mapping is missing for this Protected Area / office group.', alertType: $alertType,
        );
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    private function recordManualUnmapped(Collection $reports, User $user, string $alertType): Collection
    {
        return collect($this->groupUnmapped($reports))->map(function (Collection $group) use ($user, $alertType): ComplianceNotificationRun {
            $settings = $this->settings->effective();
            $recipient = new ResolvedComplianceRecipient('unmapped:'.hash('sha256', $group->map(fn ($r) => $r->sourceType.':'.$r->sourceId)->join('|')), '', [], null, 'unmapped');
            return $this->createRun(CarbonImmutable::now($settings['timezone'])->toDateString(), $recipient, $group, $this->memorandumGroups($group), $settings, ComplianceNotificationRun::TYPE_MANUAL, $user, ComplianceNotificationRun::STATUS_SKIPPED, 'No Recipient Mapping', alertType: $alertType);
        });
    }

    /** @param Collection<int, OverdueReport> $reports @param array<int, array<string,mixed>> $groups @param array<string,mixed> $settings */
    private function createRun(string $today, ResolvedComplianceRecipient $recipient, Collection $reports, array $groups, array $settings, string $type, ?User $user, string $status = ComplianceNotificationRun::STATUS_PENDING, ?string $error = null, ?string $idempotencyKey = null, ?string $alertType = null, ?array $memorandum = null): ComplianceNotificationRun
    {
        $memorandum ??= $this->buildMemorandum($reports, $recipient, $alertType, $settings);
        $groups = $memorandum['groups'];
        $settings = $memorandum['settings'];
        $presentation = $memorandum['presentation'];
        $first = $reports->first();
        $subject = ($presentation['subject'] ?: match ($alertType) {
            ComplianceNotificationRun::ALERT_DUE_SOON => 'eDATS Reminder: '.($first?->module ?? 'Report').' Due on '.CarbonImmutable::parse($first?->deadline ?? $today)->format('F j, Y'),
            ComplianceNotificationRun::ALERT_DUE_TODAY => 'eDATS Due Today: '.($first?->module ?? 'Report').' — '.CarbonImmutable::parse($first?->deadline ?? $today)->format('F j, Y'),
            default => $settings['email_subject'],
        });
        $run = ComplianceNotificationRun::create([
            'run_date' => $today,
            'recipient_key' => $recipient->key,
            'idempotency_key' => $idempotencyKey,
            'alert_type' => $alertType,
            'recipients' => $recipient->email === '' ? [] : [$recipient->email],
            'cc_recipients' => $recipient->ccEmails,
            'subject' => $subject,
            'report_count' => $reports->count(),
            'status' => $status,
            'is_manual' => $type === ComplianceNotificationRun::TYPE_MANUAL,
            'run_type' => $type,
            'error_message' => $error,
            'payload' => ['alert_type' => $alertType, 'groups' => $groups, 'recipient' => $recipient->toArray(), 'presentation' => $presentation, 'settings' => $this->memorandumSettingsSnapshot($settings)],
            'created_by' => $user?->id,
        ]);
        $run->reports()->createMany($reports->map(fn (OverdueReport $report) => [
            'source_type' => $report->sourceType,
            'source_id' => $report->sourceId,
            'snapshot' => $report->toArray(),
        ])->all());

        return $run;
    }

    private function automaticDeliveryEnabled(): bool
    {
        return $this->automaticDeliveryState()['effective'];
    }

    private function manualDeliveryEnabled(): bool
    {
        $state = $this->automaticDeliveryState();

        return $state['environment_gate'] && (bool) $this->settings->effective()['alerts_enabled'] && $state['mail_configured'];
    }

    /** @param Collection<int, OverdueReport> $reports @return array<int, Collection<int, OverdueReport>> */
    private function groupUnmapped(Collection $reports): array
    {
        return $reports->groupBy(fn (OverdueReport $report) => $this->recipients->logicalKey($report))->values()->all();
    }

    /** @param Collection<int, OverdueReport> $reports @return array<int, array<string,mixed>> */
    public function memorandumGroups(Collection $reports): array
    {
        return $reports->groupBy(fn (OverdueReport $report) => $this->recipients->logicalKey($report))
            ->map(function (Collection $items): array {
                $first = $items->first();
                return [
                    'target_office' => $first->targetOffice,
                    'protected_area_name' => $first->protectedAreaName,
                    'readiness_key' => $this->recipients->logicalKey($first),
                    'reports' => $items->sortBy('deadline')->map(fn (OverdueReport $report) => $report->toArray())->values()->all(),
                ];
             })->values()->all();
    }

    /** @return array{groups:array<int,array<string,mixed>>,settings:array<string,mixed>,recipient:array<string,mixed>,alert_type:string,presentation:array<string,mixed>,subject:string,html:string} */
    private function buildMemorandum(Collection $reports, ResolvedComplianceRecipient $recipient, ?string $alertType, ?array $settings = null): array
    {
        if ($reports->isEmpty() || $alertType === null) {
            throw new \InvalidArgumentException('A memorandum requires eligible reports and an alert type.');
        }

        $settings ??= $this->settings->effective();
        $settings = [...$settings, 'template_settings' => $this->templates->templateSettings($settings)];
        $presentation = $this->templates->presentationFor($reports, $alertType, $settings);
        $groups = $this->memorandumGroups($reports);
        $html = view('emails.compliance.overdue-memorandum', [
            'groups' => $groups,
            'settings' => $settings,
            'recipient' => $recipient->toArray(),
            'alertType' => $alertType,
            'presentation' => $presentation,
        ])->render();

        return [
            'groups' => $groups,
            'settings' => $settings,
            'recipient' => $recipient->toArray(),
            'alert_type' => $alertType,
            'presentation' => $presentation,
            'subject' => (string) ($presentation['subject'] ?? $settings['email_subject'] ?? 'Compliance Alert'),
            'html' => $html,
            'reports' => $reports,
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function memorandumSettingsSnapshot(array $settings): array
    {
        return array_intersect_key($settings, array_flip([
            'email_subject', 'sender_display_name', 'from_line', 'memorandum_subject', 'introductory_text', 'compliance_warning_text',
            'strict_compliance_text', 'signatory_name', 'signatory_position', 'office_name', 'office_address',
            'focal_person_name', 'focal_person_position', 'focal_person_contact', 'do_not_reply_text', 'system_generated_footer_text',
        ]));
    }

    private function redactTransportMessage(string $message): string
    {
        $message = preg_replace('#(://[^:/\s]+:)[^@\s]+@#', '$1[redacted]@', $message) ?? $message;

        return preg_replace('/\b(password|pass|secret|token|api[_-]?key)\s*=\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;
    }

    /** @return array{key:string,fingerprint:string} */
    private function deliveryIdentity(string $businessDate, string $type, string $alertType, ResolvedComplianceRecipient $recipient, Collection $reports, array $settings): array
    {
        $reportSnapshots = $reports
            ->map(fn (OverdueReport $report): array => $report->toArray())
            ->sortBy(fn (array $report): string => $report['source_type'].':'.str_pad((string) $report['source_id'], 20, '0', STR_PAD_LEFT))
            ->values()
            ->all();
        $snapshot = $this->canonicalise([
            'recipient' => $recipient->toArray(),
            'reports' => $reportSnapshots,
            'settings' => $this->memorandumSettingsSnapshot($settings),
        ]);
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $keyParts = [$type, $alertType, $businessDate, $recipient->key, $reports->map(fn (OverdueReport $report): string => $report->sourceType.':'.$report->sourceId.':'.$report->deadline)->sort()->join('|')];
        if ($type === ComplianceNotificationRun::TYPE_MANUAL) {
            $keyParts[] = $fingerprint;
        }

        return [
            'fingerprint' => $fingerprint,
            'key' => hash('sha256', implode('|', $keyParts)),
        ];
    }

    /** @return array{acquired:bool,status:string} */
    private function acquireDeliveryClaim(string $key, string $fingerprint, string $businessDate, string $type, string $recipientKey): array
    {
        $now = CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE);
        $inserted = ComplianceDeliveryClaim::query()->insertOrIgnore([
            'idempotency_key' => $key,
            'run_type' => $type,
            'business_date' => $businessDate,
            'recipient_key' => $recipientKey,
            'delivery_fingerprint' => $fingerprint,
            'status' => ComplianceDeliveryClaim::STATUS_PROCESSING,
            'attempts' => 1,
            'acquired_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return ['acquired' => true, 'status' => ComplianceDeliveryClaim::STATUS_PROCESSING];
        }

        $reacquired = ComplianceDeliveryClaim::query()
            ->where('idempotency_key', $key)
            ->where('status', ComplianceDeliveryClaim::STATUS_FAILED)
            ->update([
                'status' => ComplianceDeliveryClaim::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
                'acquired_at' => $now,
                'completed_at' => null,
                'updated_at' => $now,
            ]);

        if ($reacquired === 1) {
            return ['acquired' => true, 'status' => ComplianceDeliveryClaim::STATUS_PROCESSING];
        }

        $status = (string) ComplianceDeliveryClaim::query()
            ->where('idempotency_key', $key)
            ->value('status');

        return ['acquired' => false, 'status' => $status ?: ComplianceDeliveryClaim::STATUS_PROCESSING];
    }

    private function completeDeliveryClaim(string $key, string $status, ?ComplianceNotificationRun $run = null): void
    {
        ComplianceDeliveryClaim::query()
            ->where('idempotency_key', $key)
            ->where('status', ComplianceDeliveryClaim::STATUS_PROCESSING)
            ->update([
                'status' => $status,
                'completed_at' => CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE),
                'last_run_id' => $run?->id,
                'updated_at' => CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE),
            ]);
    }

    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalise($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalise($item), $value);
    }
}
