<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\EdatsInAppNotification;
use App\Services\Compliance\OverdueReport;
use App\Services\Compliance\OverdueReportService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Thin delivery layer over authoritative alert and tracking state. */
final class EdatsInAppNotificationService
{
    public const OVERDUE = 'overdue';
    public const DUE_SOON = 'due_soon';
    public const DUE_TODAY = 'due_today';
    public const CENRO_RELEASED = 'cenro_released';
    public const PENRO_RECEIVED = 'penro_received';
    public const FOR_REGIONAL_ENDORSEMENT = 'for_regional_endorsement';
    public const REGION_ENDORSED = 'region_endorsed';

    public function __construct(private readonly OverdueReportService $alerts) {}

    public function syncDeadlineNotifications(?CarbonImmutable $today = null): void
    {
       $today ??= CarbonImmutable::now('Asia/Manila')->startOfDay();
       $this->alerts->overdueReports($today)->each(fn (OverdueReport $report) => $this->deliverReport($report, self::OVERDUE));
        $this->alerts->dueTodayReports($today)->each(fn (OverdueReport $report) => $this->deliverReport($report, self::DUE_TODAY));
       $this->alerts->dueSoonReports((int) config('notifications.due_soon_days', 3), $today)->each(fn (OverdueReport $report) => $this->deliverReport($report, self::DUE_SOON));
    }

    /** @param array<string, mixed> $report */
    public function submissionTransition(array $report, string $stage, string $date): void
    {
        $source = (string) ($report['source'] ?? 'report');
        $sourceId = (int) ($report['source_id'] ?? 0);
        $context = $this->context($report);
        $dateLabel = CarbonImmutable::parse($date, 'Asia/Manila')->format('M j, Y');

        if ($stage === SubmissionTrackingService::CENRO_RELEASE) {
            $this->deliver($context + [
                'type' => self::CENRO_RELEASED, 'category' => 'submission_updates', 'severity' => 'info',
                'title' => 'Report Released by CENRO',
                'message' => "{$context['source_label']} — {$context['location']} was released by CENRO on {$dateLabel}.",
                'dedup_key' => "cenro_released:{$source}:{$sourceId}:{$date}",
            ]);
            return;
        }

        if ($stage === SubmissionTrackingService::PENRO_RECEIPT) {
            $this->deliver($context + [
                'type' => self::PENRO_RECEIVED, 'category' => 'submission_updates', 'severity' => 'success',
                'title' => 'Report Received by PENRO',
                'message' => "{$context['source_label']} — {$context['location']} was received by PENRO on {$dateLabel}.",
                'dedup_key' => "penro_received:{$source}:{$sourceId}:{$date}",
            ]);

            if (($report['supports_regional_endorsement'] ?? false) === true) {
                $this->deliver($context + [
                    'type' => self::FOR_REGIONAL_ENDORSEMENT, 'category' => 'submission_updates', 'severity' => 'warning',
                    'title' => 'For Regional Endorsement',
                    'message' => "{$context['source_label']} — {$context['location']} is awaiting Regional Office endorsement.",
                    'dedup_key' => "for_regional_endorsement:{$source}:{$sourceId}:{$date}",
                ]);
            }
            return;
        }

        if ($stage === SubmissionTrackingService::REGIONAL_ENDORSEMENT && ($report['supports_regional_endorsement'] ?? false) === true) {
            $this->deliver($context + [
                'type' => self::REGION_ENDORSED, 'category' => 'submission_updates', 'severity' => 'success',
                'title' => 'Report Endorsed to Regional Office',
                'message' => "{$context['source_label']} — {$context['location']} was endorsed on {$dateLabel}.",
                'dedup_key' => "region_endorsed:{$source}:{$sourceId}:{$date}",
            ]);
        }
    }

    private function deliverReport(OverdueReport $report, string $type): void
    {
        $deadline = CarbonImmutable::parse($report->deadline, 'Asia/Manila')->format('M j, Y');
        $context = [
            'source_type' => $report->sourceType,
            'source_id' => $report->sourceId,
            'source_label' => $report->module,
            'location' => $report->protectedAreaName !== 'Protected Area not specified' ? $report->protectedAreaName : $report->targetOffice,
            'office' => $report->targetOffice,
            'protected_area' => $report->protectedAreaName,
            'url' => route('compliance-alerts.index'),
        ];
        $this->deliver($context + [
            'type' => $type,
            'category' => match ($type) {
                self::OVERDUE => 'overdue',
                self::DUE_TODAY => 'due_today',
                default => 'due_soon',
            },
            'severity' => $type === self::OVERDUE ? 'danger' : 'warning',
            'title' => match ($type) {
                self::OVERDUE => 'Overdue Report',
                self::DUE_TODAY => 'Report Due Today',
                default => 'Report Due Soon',
            },
            'message' => $type === self::OVERDUE
                ? "{$report->module} — {$context['location']}. Deadline was {$deadline}."
                : "{$report->module} — {$context['location']}. Due {$deadline}.",
            'deadline' => $report->deadline,
            'dedup_key' => "{$type}:{$report->sourceType}:{$report->sourceId}:{$report->deadline}",
        ]);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function context(array $report): array
    {
        $location = trim((string) ($report['protected_area'] ?? '')) ?: trim((string) ($report['target_office'] ?? '')) ?: 'Unassigned office';
        return [
            'source_type' => (string) ($report['source'] ?? 'report'),
            'source_id' => (int) ($report['source_id'] ?? 0),
            'source_label' => (string) ($report['module'] ?? 'Report'),
            'location' => $location,
            'office' => $report['target_office'] ?? null,
            'protected_area' => $report['protected_area'] ?? null,
            'url' => route('submission-tracking.index'),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload): void
    {
        foreach ($this->recipients($payload['office'] ?? null) as $user) {
            $exists = $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['dedup_key'] ?? null) === $payload['dedup_key']);
            if (! $exists) {
                $user->notify(new EdatsInAppNotification($payload));
            }
        }
    }

    /** @return Collection<int, User> */
    private function recipients(?string $office): Collection
    {
        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($office): bool {
            if ($user->section === 'MES' || $user->hasRole('no_role')) return false;
            $canMonitor = $user->hasAnyRole(['Super Admin', 'CDS Admin', 'Admin', 'Staff', 'staff']) || $user->can('reports.view');
            if (! $canMonitor) return false;
            return ! ($user->hasRole('ENGP Encoder') && filled($user->office_designated) && $office !== null && $user->office_designated !== $office);
        })->values();
    }
}
