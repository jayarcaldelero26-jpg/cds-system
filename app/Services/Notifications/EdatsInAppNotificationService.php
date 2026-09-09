<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\EdatsInAppNotification;
use App\Services\Compliance\OverdueReport;
use App\Services\Compliance\OverdueReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Thin delivery layer over authoritative alert and tracking state. */
final class EdatsInAppNotificationService
{
    public const OVERDUE = 'overdue';
    public const DUE_SOON = 'due_soon';

    public function __construct(private readonly OverdueReportService $alerts) {}

    /** @param array<string, mixed> $data */
    public static function isBellAlert(array $data): bool
    {
        return in_array($data['type'] ?? null, [self::DUE_SOON, self::OVERDUE], true);
    }

    /**
     * Bell alerts are operational reminders. Resolve them to the authorized
     * tracking workspace instead of persisting a privileged admin page URL.
     */
    public static function actionUrl(array $data, User $user): string
    {
        if (! $user->can('reports.view')) {
            return route('dashboard');
        }

        $source = self::trackingSource((string) ($data['source_type'] ?? ''));
        $sourceId = filter_var($data['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $source !== null && $sourceId !== false
            ? route('submission-tracking.index', ['focus_source' => $source, 'focus_id' => $sourceId])
            : route('submission-tracking.index');
    }

    public function syncDeadlineNotifications(?CarbonImmutable $today = null): void
    {
       $today ??= CarbonImmutable::now('Asia/Manila')->startOfDay();
       $this->alerts->overdueReports($today)->each(fn (OverdueReport $report) => $this->deliverReport($report, self::OVERDUE));
       $this->alerts->dueSoonReports((int) config('notifications.due_soon_days', 3), $today)->each(fn (OverdueReport $report) => $this->deliverReport($report, self::DUE_SOON));
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
        ];
        $this->deliver($context + [
            'type' => $type,
            'category' => $type === self::OVERDUE ? 'overdue' : 'due_soon',
            'severity' => $type === self::OVERDUE ? 'danger' : 'warning',
            'title' => $type === self::OVERDUE ? 'Overdue Report' : '3-Day Reminder',
            'message' => $type === self::OVERDUE
                ? "{$report->module} for {$context['location']} is overdue."
                : "{$report->module} for {$context['location']} is due on {$deadline}.",
            'deadline' => $report->deadline,
            'dedup_key' => "{$type}:{$report->sourceType}:{$report->sourceId}:{$report->deadline}",
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload): void
    {
        foreach ($this->recipients($payload['office'] ?? null) as $user) {
            $exists = $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['dedup_key'] ?? null) === $payload['dedup_key']);
            if (! $exists) {
                $user->notify(new EdatsInAppNotification([...$payload, 'url' => self::actionUrl($payload, $user)]));
            }
        }
    }

    private static function trackingSource(string $sourceType): ?string
    {
        return match ($sourceType) {
            \App\Models\ConservationReportSubmission::class => 'conservation',
            \App\Models\EngpReportSubmission::class => 'engp',
            \App\Models\BmsReportSubmission::class => 'bms',
            \App\Models\BamsReportSubmission::class => 'bams',
            \App\Models\ImeaReportSubmission::class => 'imea',
            \App\Models\ImeaFacilityMaintenanceReport::class => 'imea-maintenance',
            \App\Models\Aws::class => 'aws',
            \App\Models\IpafManagementReport::class => 'ipaf-management',
            \App\Models\IpafRevenueCollection::class => 'revenue',
            \App\Models\ManagementPlan::class => 'management-plans',
            default => null,
        };
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
