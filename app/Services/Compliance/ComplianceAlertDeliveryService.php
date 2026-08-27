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
        private readonly ComplianceRecipientResolver $recipients,
        private readonly ComplianceAlertSettingsService $settings,
    ) {}

    /** @return Collection<int, ComplianceNotificationRun> */
    public function sendAutomatic(bool $dryRun = false): Collection
    {
        return $this->dispatchAutomatic($this->reports->overdueReports(), $dryRun);
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    public function sendManual(Collection $reports, User $user): Collection
    {
        $plan = $this->deliveryPlan($reports);
        if ($plan['unmapped']->isNotEmpty()) {
            throw ValidationException::withMessages(['recipients' => 'Recipient mapping is missing for one or more overdue Protected Area / office groups. Add a recipient mapping before sending.']);
        }
        if (! $this->manualDeliveryEnabled()) {
            throw ValidationException::withMessages(['delivery' => 'External delivery is disabled by safe mode. Use Preview Memorandum until alerts are explicitly enabled.']);
        }

        return $this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_MANUAL, $user);
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    public function sendTest(Collection $reports, User $user): Collection
    {
        $settings = $this->settings->effective();
        $testRecipient = trim((string) ($settings['test_recipient_email'] ?? ''));
        if (! $this->settings->testEmailEnabled()) {
            throw ValidationException::withMessages(['test_email' => 'Test email delivery is disabled. Enable COMPLIANCE_TEST_EMAIL_ENABLED only for a controlled test recipient.']);
        }
        if (! filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['test_recipient_email' => 'Configure a valid test recipient email before sending a test memorandum.']);
        }

        $recipient = new ResolvedComplianceRecipient('test:'.hash('sha256', strtolower($testRecipient)), $testRecipient, [], 'Test Recipient', 'test');
        return $this->dispatchDeliveries(collect([['recipient' => $recipient, 'reports' => $this->testMemorandumData($reports)['reports']]]), ComplianceNotificationRun::TYPE_TEST, $user);
    }

    /** @param Collection<int, OverdueReport> $reports @return array{deliveries: Collection<int, array<string,mixed>>, unmapped: Collection<int, OverdueReport>} */
    public function deliveryPlan(Collection $reports): array
    {
        return $this->recipients->plans($reports);
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, array<string,mixed>> */
    public function recipientReadiness(Collection $reports): Collection
    {
        return $this->recipients->readiness($reports);
    }

    /** @param Collection<int, OverdueReport> $reports @return array{reports: Collection<int, OverdueReport>,using_fixture: bool} */
    public function testMemorandumData(Collection $reports): array
    {
        if ($reports->isNotEmpty()) {
            return ['reports' => $reports, 'using_fixture' => false];
        }

        $today = CarbonImmutable::now($this->settings->effective()['timezone'])->toDateString();

        return ['reports' => collect([new OverdueReport(
            sourceType: 'compliance-test-fixture', sourceId: 0, module: 'Compliance Alerts', protectedAreaId: null,
            protectedAreaName: 'Controlled Test Fixture', targetOffice: 'Internal Test Mailbox',
            activity: 'Controlled memorandum layout test (no current overdue reports)', documentType: 'Test Fixture',
            deadline: $today, submitted: false, recordsConfirmed: false, daysOverdue: 0, isTestFixture: true,
        )]), 'using_fixture' => true];
    }

    /** @return array{environment_gate: bool,operational_setting: bool,effective: bool} */
    public function automaticDeliveryState(): array
    {
        $settings = $this->settings->effective();
        $environment = (bool) config('compliance_alerts.enabled');
        $operational = (bool) $settings['alerts_enabled'] && (bool) $settings['automatic_send_enabled'];

        return ['environment_gate' => $environment, 'operational_setting' => $operational, 'effective' => $environment && $operational];
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, ComplianceNotificationRun> */
    private function dispatchAutomatic(Collection $reports, bool $dryRun): Collection
    {
        if ($reports->isEmpty()) {
            return collect();
        }

        $plan = $this->deliveryPlan($reports);
        $runs = collect();
        foreach ($this->groupUnmapped($plan['unmapped']) as $group) {
            $runs->push($this->recordUnmapped($group, $dryRun));
        }

        if ($dryRun) {
            return $runs->merge($this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_DRY_RUN, null));
        }

        $settings = $this->settings->effective();
        if (CarbonImmutable::now($settings['timezone'])->isWeekend()) {
            return $runs->merge($this->recordDisabled(
                $plan['deliveries'],
                'Automatic compliance alerts are scheduled Monday through Friday only.',
            ));
        }

        if (! $this->automaticDeliveryEnabled()) {
            return $runs->merge($this->recordDisabled($plan['deliveries']));
        }

        return $runs->merge($this->dispatchDeliveries($plan['deliveries'], ComplianceNotificationRun::TYPE_AUTOMATIC, null));
    }

    /** @param Collection<int, array{recipient: ResolvedComplianceRecipient,reports: Collection<int,OverdueReport>}> $deliveries @return Collection<int, ComplianceNotificationRun> */
    private function dispatchDeliveries(Collection $deliveries, string $type, ?User $user): Collection
    {
        return $deliveries->map(function (array $delivery) use ($type, $user): ?ComplianceNotificationRun {
            $recipient = $delivery['recipient'];
            $reports = $delivery['reports'];
            if ($reports->isEmpty()) {
                return null;
            }

            $settings = $this->settings->effective();
            $today = CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE)->toDateString();
            $groups = $this->memorandumGroups($reports);
            $idempotencyKey = null;
            if (in_array($type, [ComplianceNotificationRun::TYPE_MANUAL, ComplianceNotificationRun::TYPE_AUTOMATIC], true)) {
                ['key' => $idempotencyKey, 'fingerprint' => $fingerprint] = $this->deliveryIdentity(
                    $today,
                    $type,
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
                        $idempotencyKey,
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
                    idempotencyKey: $idempotencyKey,
                );
            } catch (\Throwable $exception) {
                if ($idempotencyKey !== null) {
                    $this->completeDeliveryClaim($idempotencyKey, ComplianceDeliveryClaim::STATUS_FAILED);
                }
                throw $exception;
            }

            if ($type === ComplianceNotificationRun::TYPE_DRY_RUN) {
                $run->update(['status' => ComplianceNotificationRun::STATUS_SKIPPED, 'error_message' => 'Dry run: no email was sent.']);
                return $run;
            }

            try {
                $pending = Mail::to($recipient->email);
                if ($recipient->ccEmails !== []) {
                    $pending->cc($recipient->ccEmails);
                }
                $prefix = $type === ComplianceNotificationRun::TYPE_TEST ? '[TEST] ' : '';
                $pending->send(new OverdueComplianceMemorandum($groups, $settings, $recipient->toArray(), $prefix));
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
    private function recordDisabled(Collection $deliveries, string $reason = 'Automatic delivery is disabled by safe mode or operational settings.'): Collection
    {
        return $deliveries->map(function (array $delivery) use ($reason): ComplianceNotificationRun {
            $settings = $this->settings->effective();
            return $this->createRun(
                CarbonImmutable::now($settings['timezone'])->toDateString(), $delivery['recipient'], $delivery['reports'],
                $this->memorandumGroups($delivery['reports']), $settings, ComplianceNotificationRun::TYPE_AUTOMATIC, null,
                ComplianceNotificationRun::STATUS_SKIPPED, $reason,
            );
        });
    }

    /** @param Collection<int, OverdueReport> $reports */
    private function recordUnmapped(Collection $reports, bool $dryRun): ComplianceNotificationRun
    {
        $settings = $this->settings->effective();
        $key = 'unmapped:'.hash('sha256', $reports->map(fn (OverdueReport $report) => $report->sourceType.':'.$report->sourceId)->join('|'));
        $recipient = new ResolvedComplianceRecipient($key, '', [], null, 'unmapped');

        return $this->createRun(
            CarbonImmutable::now($settings['timezone'])->toDateString(), $recipient, $reports, $this->memorandumGroups($reports),
            $settings, $dryRun ? ComplianceNotificationRun::TYPE_DRY_RUN : ComplianceNotificationRun::TYPE_AUTOMATIC, null,
            ComplianceNotificationRun::STATUS_SKIPPED, 'Recipient mapping is missing for this Protected Area / office group.',
        );
    }

    /** @param Collection<int, OverdueReport> $reports @param array<int, array<string,mixed>> $groups @param array<string,mixed> $settings */
    private function createRun(string $today, ResolvedComplianceRecipient $recipient, Collection $reports, array $groups, array $settings, string $type, ?User $user, string $status = ComplianceNotificationRun::STATUS_PENDING, ?string $error = null, ?string $idempotencyKey = null): ComplianceNotificationRun
    {
        $run = ComplianceNotificationRun::create([
            'run_date' => $today,
            'recipient_key' => $recipient->key,
            'idempotency_key' => $idempotencyKey,
            'recipients' => $recipient->email === '' ? [] : [$recipient->email],
            'cc_recipients' => $recipient->ccEmails,
            'subject' => ($type === ComplianceNotificationRun::TYPE_TEST ? '[TEST] ' : '').$settings['email_subject'],
            'report_count' => $reports->count(),
            'status' => $status,
            'is_manual' => in_array($type, [ComplianceNotificationRun::TYPE_MANUAL, ComplianceNotificationRun::TYPE_TEST], true),
            'run_type' => $type,
            'error_message' => $error,
            'payload' => ['groups' => $groups, 'recipient' => $recipient->toArray(), 'settings' => $this->memorandumSettingsSnapshot($settings)],
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
        return (bool) config('compliance_alerts.enabled') && (bool) $this->settings->effective()['alerts_enabled'];
    }

    /** @param Collection<int, OverdueReport> $reports @return array<int, Collection<int, OverdueReport>> */
    private function groupUnmapped(Collection $reports): array
    {
        return $reports->groupBy(fn (OverdueReport $report) => $report->targetOffice.'|'.$report->protectedAreaName)->values()->all();
    }

    /** @param Collection<int, OverdueReport> $reports @return array<int, array<string,mixed>> */
    public function memorandumGroups(Collection $reports): array
    {
        return $reports->groupBy(fn (OverdueReport $report) => "{$report->targetOffice}|{$report->protectedAreaName}")
            ->map(function (Collection $items): array {
                $first = $items->first();
                return [
                    'target_office' => $first->targetOffice,
                    'protected_area_name' => $first->protectedAreaName,
                    'reports' => $items->sortBy('deadline')->map(fn (OverdueReport $report) => $report->toArray())->values()->all(),
                ];
            })->values()->all();
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    private function memorandumSettingsSnapshot(array $settings): array
    {
        return array_intersect_key($settings, array_flip([
            'email_subject', 'sender_display_name', 'to_label', 'attention_line', 'from_line', 'memorandum_subject', 'introductory_text', 'compliance_warning_text',
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
    private function deliveryIdentity(string $businessDate, string $type, ResolvedComplianceRecipient $recipient, Collection $reports, array $settings): array
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

        $keyParts = [$type, $businessDate, $recipient->key];
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
