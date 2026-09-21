<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Notifications\EdatsInAppNotification;
use App\Services\Compliance\OverdueReport;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

/** Thin delivery layer over authoritative alert and tracking state. */
final class EdatsInAppNotificationService
{
    public const OVERDUE = 'overdue';
    public const DUE_SOON = 'due_soon';
    public const WORKFLOW = 'workflow';

    public function __construct(private readonly OverdueReportService $alerts) {}

    /** @param array<string, mixed> $data */
    public static function isBellAlert(array $data): bool
    {
        return in_array($data['type'] ?? null, [self::DUE_SOON, self::OVERDUE, self::WORKFLOW], true);
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
    /** @param array<string,mixed> $action */
    public function notifyGenericTransition(Model $record, string $source, DocumentRoutingEvent $event, array $action): void
    {
        $eventKey = (string) $event->event_key;
        if (! in_array($eventKey, ['forwarded', 'endorsed', 'recommended', 'approved', 'released', 'received', 'returned_for_correction'], true)) return;

        $label = $this->submissionLabel($record);
        $sourceId = (int) $record->getKey();
        $base = ['type' => self::WORKFLOW, 'category' => 'submission_updates', 'severity' => $eventKey === 'returned_for_correction' ? 'warning' : 'info', 'source_type' => $source, 'source_id' => $sourceId, 'source_label' => $label, 'protected_area' => $record->getAttribute('protected_area_id'), 'url' => route('submission-tracking.index', ['view' => 'incoming', 'source' => $source, 'source_id' => $sourceId])];

        if ($eventKey === 'received') {
            $previous = DocumentRoutingEvent::query()->with('recordedBy')->where('source_type', $source)->where('source_id', $sourceId)->where('id', '<', $event->getKey())->latest('id')->first();
            if ($previous?->recordedBy) {
                $this->deliverTo(collect([$previous->recordedBy]), array_merge($base, ['title' => 'Submission Received', 'message' => $previous->to_office.' received the '.$label.' you released.', 'url' => route('submission-tracking.index', ['view' => 'outgoing', 'source' => $source, 'source_id' => $sourceId]), 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':received']));
            }
            return;
        }

        $category = $this->categoryForLabel($action['to_office'] ?? $event->to_office);
        if (! $category) return;
        $targetOffice = in_array($category, [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)
            ? ($record->getAttribute('target_office') ?: $record->getAttribute('office') ?: $event->to_office)
            : null;
        $title = $eventKey === 'returned_for_correction' ? 'Correction Required' : ($eventKey === 'approved' ? 'Submission Approved' : ($eventKey === 'recommended' ? 'Submission Recommended' : ($eventKey === 'released' ? 'Submission Released' : ($eventKey === 'forwarded' || $eventKey === 'endorsed' ? 'Submission Forwarded' : 'Incoming Submission'))));
        $this->deliverTo($this->workflowRecipients($category, $targetOffice, $record->getAttribute('protected_area_id')), array_merge($base, ['title' => $title, 'message' => $eventKey === 'returned_for_correction' ? 'The '.$label.' was returned for correction by '.$event->from_office.'.' : 'A '.$label.' submission from '.$event->from_office.' is awaiting your action.', 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':'.$category]));
    }

    public function notifyPambTransition(ConservationReportSubmission $report, PambRoutingEvent $event, string $stageKey): void
    {
        $source = 'conservation';
        $sourceId = (int) $report->getKey();
        $label = $this->submissionLabel($report);
        $base = ['type' => self::WORKFLOW, 'category' => 'submission_updates', 'severity' => str_contains($stageKey, 'correction') ? 'warning' : 'info', 'source_type' => $source, 'source_id' => $sourceId, 'source_label' => $label, 'protected_area' => $report->getAttribute('protected_area_id'), 'url' => route('submission-tracking.index', ['view' => 'incoming', 'source' => $source, 'source_id' => $sourceId])];
        $received = in_array($stageKey, ['received_by_penro', 'received_by_tsd', 'received_by_cds', 'received_by_cds_chief', 'received_by_penro_final', 'received_by_records_final'], true);
        if ($received) {
            $previous = PambRoutingEvent::query()->with('recordedBy')->where('conservation_report_submission_id', $sourceId)->where('id', '<', $event->getKey())->latest('id')->first();
            if ($previous?->recordedBy) $this->deliverTo(collect([$previous->recordedBy]), array_merge($base, ['title' => 'Submission Received', 'message' => $this->labelForStage($stageKey).' received the '.$label.' you sent.', 'url' => route('submission-tracking.index', ['view' => 'outgoing', 'source' => $source, 'source_id' => $sourceId]), 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':received']));
            return;
        }
        $destination = match ($stageKey) {
            'forwarded_records_to_penro' => 'Office of the PENRO',
            'forwarded_penro_to_tsd' => 'PENRO TSD Chief',
            'forwarded_tsd_to_cds' => 'PENRO CDS Focal Person',
            'forwarded_cds_focal_to_chief' => 'PENRO CDS Chief',
            'forwarded_cds_to_penro' => 'Office of the PENRO',
            'forwarded_penro_to_records' => 'PENRO Records',
            'penro_final_returned_for_correction' => 'PENRO CDS Focal Person',
            'penro_final_approved_for_regional' => 'PENRO Records',
            default => null,
        };
        $category = $this->categoryForLabel($destination);
        if (! $category) return;
        $title = str_contains($stageKey, 'correction') ? 'Correction Required' : (str_contains($stageKey, 'approved') ? 'Submission Approved' : (str_contains($stageKey, 'recommended') ? 'Submission Recommended' : 'Incoming Submission'));
        $this->deliverTo($this->workflowRecipients($category, $destination, $report->getAttribute('protected_area_id')), array_merge($base, ['title' => $title, 'message' => str_contains($stageKey, 'correction') ? 'The '.$label.' was returned for correction by Office of the PENRO.' : 'A '.$label.' submission is awaiting your action.', 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':'.$category]));
    }

    private function submissionLabel(Model $record): string
    {
        return (string) ($record->getAttribute('activity_name') ?: $record->getAttribute('document_type') ?: $record->getAttribute('workflow_key') ?: 'Submission');
    }

    private function labelForStage(string $stageKey): string
    {
        return match ($this->categoryForLabel($stageKey) ?: $stageKey) {
            OrganizationalAccessService::PENRO_RECORDS => 'PENRO Records',
            OrganizationalAccessService::OFFICE_PENRO => 'Office of the PENRO',
            OrganizationalAccessService::PENRO_TSD_CHIEF => 'PENRO TSD Chief',
            OrganizationalAccessService::PENRO_FOCAL => 'PENRO CDS Focal',
            OrganizationalAccessService::PENRO_CHIEF => 'PENRO CDS Chief',
            default => 'The receiving office',
        };
    }

    private function categoryForLabel(?string $label): ?string
    {
        $value = strtoupper((string) $label);
        if (str_contains($value, 'CENRO RECORDS')) return OrganizationalAccessService::CENRO_RECORDS;
        if (str_contains($value, 'CENRO CDS CHIEF')) return OrganizationalAccessService::CENRO_CHIEF;
        if (str_contains($value, 'CENRO CDS FOCAL')) return OrganizationalAccessService::CENRO_FOCAL;
        if (str_contains($value, 'PENRO RECORDS')) return OrganizationalAccessService::PENRO_RECORDS;
        if (str_contains($value, 'OFFICE') && str_contains($value, 'PENRO')) return OrganizationalAccessService::OFFICE_PENRO;
        if (str_contains($value, 'TSD')) return OrganizationalAccessService::PENRO_TSD_CHIEF;
        if (str_contains($value, 'CDS FOCAL')) return OrganizationalAccessService::PENRO_FOCAL;
        if (str_contains($value, 'CDS CHIEF')) return OrganizationalAccessService::PENRO_CHIEF;
        return null;
    }
    /** @return Collection<int,User> */
    private function workflowRecipients(string $category, ?string $office, mixed $protectedAreaId): Collection
    {
        $canonicalOffice = app(OrganizationalAccessService::class)->normalizeOffice($office);
        $organization = app(OrganizationalAccessService::class);
        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($category, $canonicalOffice, $protectedAreaId, $organization): bool {
            if ($organization->effectiveCategory($user) !== $category) return false;
            if (in_array($category, [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)) return $organization->normalizeOffice($user->office_designated) === $canonicalOffice;
            if ($category === OrganizationalAccessService::PAMO) return (int) $user->protected_area_id === (int) $protectedAreaId;
            if (in_array($category, [OrganizationalAccessService::PENRO_RECORDS, OrganizationalAccessService::OFFICE_PENRO, OrganizationalAccessService::PENRO_TSD_CHIEF, OrganizationalAccessService::PENRO_FOCAL, OrganizationalAccessService::PENRO_CHIEF], true)) return true;
            return $canonicalOffice === null || $organization->normalizeOffice($user->office_designated) === $canonicalOffice;
        })->values();
    }

    /** @param Collection<int,User> $users @param array<string,mixed> $payload */
    private function deliverTo(Collection $users, array $payload): void
    {
        foreach ($users as $user) {
            $exists = $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['dedup_key'] ?? null) === $payload['dedup_key']);
            if (! $exists) $user->notify(new EdatsInAppNotification($payload));
        }
    }
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
