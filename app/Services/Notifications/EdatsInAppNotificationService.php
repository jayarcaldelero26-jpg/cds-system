<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Notifications\EdatsInAppNotification;
use App\Services\Compliance\OverdueReport;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
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
    public static function actionUrl(array $data, bool $canViewTracking): string
    {
        if (! $canViewTracking) {
            return route('dashboard');
        }

        $source = self::trackingSource((string) ($data['source_type'] ?? ''));
        $sourceId = filter_var($data['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $storedUrl = $data['url'] ?? null;
        $query = is_string($storedUrl) ? parse_url($storedUrl, PHP_URL_QUERY) : null;
        $queryParameters = [];
        if (is_string($query)) {
            parse_str($query, $queryParameters);
        }
        $view = $queryParameters['view'] ?? null;
        $view = is_string($view) && in_array($view, ['incoming', 'outgoing', 'history'], true)
            ? $view
            : null;
        $arrivalEventId = $view === 'incoming' ? self::arrivalEventId($data) : null;

        return $source !== null && $sourceId !== false
            ? route('submission-tracking.index', array_filter([
                'view' => $view,
                'source' => $source,
                'source_id' => $sourceId,
                'open_details' => 1,
                'arrival_event_id' => $arrivalEventId,
            ], static fn (mixed $value): bool => $value !== null))
            : route('submission-tracking.index');
    }

    /** @return array{source:string,source_id:int,arrival_event_id:?int}|null */
    public static function incomingActionTarget(array $data, bool $canViewTracking): ?array
    {
        if (($data['type'] ?? null) !== self::WORKFLOW || ! $canViewTracking) return null;

        $query = parse_url(self::actionUrl($data, true), PHP_URL_QUERY);
        $parameters = [];
        if (is_string($query)) parse_str($query, $parameters);
        $source = $parameters['source'] ?? null;
        $sourceId = filter_var($parameters['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (($parameters['view'] ?? null) !== 'incoming'
            || ! is_string($source)
            || $source === ''
            || $sourceId === false) {
            return null;
        }

        return [
            'source' => $source,
            'source_id' => (int) $sourceId,
            'arrival_event_id' => self::arrivalEventId($data),
        ];
    }

    /** Return the event identity for an Incoming workflow handoff, including legacy notices. */
    public static function arrivalEventId(array $data): ?int
    {
        foreach (['arrival_event_id', 'routing_event_id'] as $key) {
            $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value !== false) return (int) $value;
        }

        $parts = explode(':', (string) ($data['dedup_key'] ?? ''));
        if (count($parts) < 5 || $parts[0] !== 'workflow' || $parts[4] === 'received') return null;
        $value = filter_var($parts[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
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
        ], $report);
    }

    /** @param array<string, mixed> $payload */
    /** @param array<string,mixed> $action */
    /** @return list<int> Eligible users whose current tracking view can change. */
    public function notifyGenericTransition(Model $record, string $source, DocumentRoutingEvent $event, array $action): array
    {
        $eventKey = (string) $event->event_key;
        $label = $this->submissionLabel($record);
        $sourceId = (int) $record->getKey();
        $arrivalOffice = $action['to_office'] ?? $event->to_office ?? $record->getAttribute('target_office') ?? $record->getAttribute('office');
        $base = [
            'type' => self::WORKFLOW,
            'category' => 'submission_updates',
            'severity' => $eventKey === 'returned_for_correction' ? 'warning' : 'info',
            'source_type' => $source,
            'source_id' => $sourceId,
            'source_label' => $label,
            'report_id' => $sourceId,
            'protected_area' => $record->getAttribute('protected_area_id'),
            'office' => $arrivalOffice,
            'from_office' => $event->from_office,
            'to_office' => $event->to_office ?: $arrivalOffice,
            'routing_event_id' => (int) $event->getKey(),
            'url' => route('submission-tracking.index', ['view' => 'incoming', 'source' => $source, 'source_id' => $sourceId]),
        ];
        $category = $event->to_stage === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
            ? OrganizationalAccessService::PAMO
            : $this->categoryForLabel($action['to_office'] ?? $event->to_office);
        $targetOffice = $category === OrganizationalAccessService::PAMO
            ? $event->to_office
            : (in_array($category, [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)
                ? ($record->getAttribute('target_office') ?: $record->getAttribute('office') ?: $event->to_office)
                : null);

        $previous = DocumentRoutingEvent::query()->with('recordedBy')
            ->where('source_type', $source)->where('source_id', $sourceId)
            ->where('id', '<', $event->getKey())->latest('id')->first();
        $destinationUsers = $category
            ? $this->workflowRecipients($category, $targetOffice, $record->getAttribute('protected_area_id'))
            : collect();
        // A correction sent back to a PAMO belongs to the account that
        // originated the submitted report when that account is still an
        // eligible member of the assigned Protected Area. Fall back to the
        // scoped role audience for legacy records without an eligible owner.
        if ($eventKey === 'returned_for_correction' && $category === OrganizationalAccessService::PAMO) {
            $owner = $destinationUsers->first(fn (User $user): bool => (int) $user->getKey() === (int) $record->getAttribute('created_by'));
            if ($owner) $destinationUsers = collect([$owner]);
        }
        $actorId = (int) $event->recorded_by;
        $ordinaryDestinationUsers = $destinationUsers
            ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
            ->values();
        $superAdmins = $this->transitionTouchesCenroOrPenro($event->from_office, $event->to_office)
            ? $this->activeSuperAdmins()
            : collect();
        $destinationAudience = $ordinaryDestinationUsers->merge($superAdmins)->unique(fn (User $user): int => (int) $user->getKey())->values();
        $affected = collect([$event->recordedBy, $previous?->recordedBy])
            ->filter()
            ->merge($destinationAudience)
            ->merge($superAdmins)
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->unique()->values()->all();

        if ($eventKey === 'received') {
            if ($previous?->recordedBy) {
                $receiptAudience = collect([$previous->recordedBy])
                    ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
                    ->merge($superAdmins)
                    ->unique(fn (User $user): int => (int) $user->getKey())->values();
                $this->deliverTo($receiptAudience, array_merge($base, ['title' => 'Submission Received', 'message' => $previous->to_office.' received the '.$label.' you released.', 'url' => route('submission-tracking.index', ['view' => 'outgoing', 'source' => $source, 'source_id' => $sourceId]), 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':received']));
            }

            return $affected;
        }

        if (! $category || ! in_array($eventKey, ['forwarded', 'endorsed', 'recommended', 'approved', 'released', 'returned_for_correction'], true)) {
            return $affected;
        }

        $title = $eventKey === 'returned_for_correction' ? 'Correction Required' : ($eventKey === 'approved' ? 'Submission Approved' : ($eventKey === 'recommended' ? 'Submission Recommended' : ($eventKey === 'released' ? 'Submission Released' : ($eventKey === 'forwarded' || $eventKey === 'endorsed' ? 'Submission Forwarded' : 'Incoming Submission'))));
        $arrival = [
            'arrival_event_id' => (int) $event->getKey(),
            'arrival_category' => $category,
            'arrival_office' => $arrivalOffice,
        ];
        $this->deliverTo($destinationAudience, array_merge($base, $arrival, ['title' => $title, 'message' => $eventKey === 'returned_for_correction' ? 'The '.$label.' was returned for correction by '.$event->from_office.'.' : 'A '.$label.' submission from '.$event->from_office.' is awaiting your action.', 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':'.$category]));

        return $affected;
    }

    /** @return list<int> Eligible users whose current tracking view can change. */
    public function notifyPambTransition(ConservationReportSubmission $report, PambRoutingEvent $event, string $stageKey): array
    {
        $source = 'conservation';
        $sourceId = (int) $report->getKey();
        $label = $this->submissionLabel($report);
        $stageOffice = $this->pambOfficeForStage($stageKey);
        $base = [
            'type' => self::WORKFLOW,
            'category' => 'submission_updates',
            'severity' => str_contains($stageKey, 'correction') ? 'warning' : 'info',
            'source_type' => $source,
            'source_id' => $sourceId,
            'source_label' => $label,
            'report_id' => $sourceId,
            'protected_area' => $report->getAttribute('protected_area_id'),
            'office' => $stageOffice,
            'from_office' => null,
            'to_office' => $stageOffice,
            'routing_event_id' => (int) $event->getKey(),
            'url' => route('submission-tracking.index', ['view' => 'incoming', 'source' => $source, 'source_id' => $sourceId]),
        ];
        $received = in_array($stageKey, ['received_by_penro', 'received_by_tsd', 'received_by_cds', 'received_by_cds_chief', 'received_by_penro_final', 'received_by_records_final'], true);
        $previous = PambRoutingEvent::query()->with('recordedBy')
            ->where('conservation_report_submission_id', $sourceId)
            ->where('id', '<', $event->getKey())->latest('id')->first();
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
        $destinationUsers = $category
            ? $this->workflowRecipients($category, $destination, $report->getAttribute('protected_area_id'))
            : collect();
        if (str_contains($stageKey, 'correction') && $category === OrganizationalAccessService::PAMO) {
            $owner = $destinationUsers->first(fn (User $user): bool => (int) $user->getKey() === (int) $report->getAttribute('created_by'));
            if ($owner) $destinationUsers = collect([$owner]);
        }
        $actorId = (int) $event->recorded_by;
        $ordinaryDestinationUsers = $destinationUsers
            ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
            ->values();
        $superAdmins = $this->transitionTouchesCenroOrPenro($stageOffice, $destination)
            ? $this->activeSuperAdmins()
            : collect();
        $destinationAudience = $ordinaryDestinationUsers->merge($superAdmins)->unique(fn (User $user): int => (int) $user->getKey())->values();
        $affected = collect([$event->recordedBy, $previous?->recordedBy])
            ->filter()
            ->merge($destinationAudience)
            ->merge($superAdmins)
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->unique()->values()->all();

        if ($received) {
            if ($previous?->recordedBy) {
                $receiptAudience = collect([$previous->recordedBy])
                    ->reject(fn (User $user): bool => (int) $user->getKey() === $actorId)
                    ->merge($superAdmins)
                    ->unique(fn (User $user): int => (int) $user->getKey())->values();
                $this->deliverTo($receiptAudience, array_merge($base, ['title' => 'Submission Received', 'message' => $this->labelForStage($stageKey).' received the '.$label.' you sent.', 'url' => route('submission-tracking.index', ['view' => 'outgoing', 'source' => $source, 'source_id' => $sourceId]), 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':received']));
            }
            return $affected;
        }

        if (! $category) return $affected;
        $title = str_contains($stageKey, 'correction') ? 'Correction Required' : (str_contains($stageKey, 'approved') ? 'Submission Approved' : (str_contains($stageKey, 'recommended') ? 'Submission Recommended' : 'Incoming Submission'));
        $arrival = [
            'arrival_event_id' => (int) $event->getKey(),
            'arrival_category' => $category,
            'arrival_office' => $destination,
        ];
        $this->deliverTo($destinationAudience, array_merge($base, $arrival, ['title' => $title, 'message' => str_contains($stageKey, 'correction') ? 'The '.$label.' was returned for correction by Office of the PENRO.' : 'A '.$label.' submission is awaiting your action.', 'dedup_key' => 'workflow:'.$source.':'.$sourceId.':'.$event->getKey().':'.$category]));

        return $affected;
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
        if (str_contains($value, 'CENRO') && str_contains($value, 'RECORDS')) return OrganizationalAccessService::CENRO_RECORDS;
        if (str_contains($value, 'CENRO') && str_contains($value, 'CDS CHIEF')) return OrganizationalAccessService::CENRO_CHIEF;
        if (str_contains($value, 'CENRO') && str_contains($value, 'CDS FOCAL')) return OrganizationalAccessService::CENRO_FOCAL;
        if (str_contains($value, 'PENRO RECORDS')) return OrganizationalAccessService::PENRO_RECORDS;
        if (str_contains($value, 'OFFICE') && str_contains($value, 'PENRO')) return OrganizationalAccessService::OFFICE_PENRO;
        if (str_contains($value, 'TSD')) return OrganizationalAccessService::PENRO_TSD_CHIEF;
        if (str_contains($value, 'PENRO ORIGIN')) return OrganizationalAccessService::PENRO_FOCAL;
        if (str_contains($value, 'CDS FOCAL')) return OrganizationalAccessService::PENRO_FOCAL;
        if (str_contains($value, 'CDS CHIEF')) return OrganizationalAccessService::PENRO_CHIEF;
        return null;
    }

    private function transitionTouchesCenroOrPenro(?string $fromOffice, ?string $toOffice): bool
    {
        $categories = [
            OrganizationalAccessService::CENRO_RECORDS,
            OrganizationalAccessService::CENRO_CHIEF,
            OrganizationalAccessService::CENRO_FOCAL,
            OrganizationalAccessService::PENRO_RECORDS,
            OrganizationalAccessService::OFFICE_PENRO,
            OrganizationalAccessService::PENRO_TSD_CHIEF,
            OrganizationalAccessService::PENRO_FOCAL,
            OrganizationalAccessService::PENRO_CHIEF,
        ];

        return in_array($this->categoryForLabel($fromOffice), $categories, true)
            || in_array($this->categoryForLabel($toOffice), $categories, true);
    }

    /** @return Collection<int, User> */
    private function activeSuperAdmins(): Collection
    {
        return User::query()->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'Super Admin')->where('guard_name', 'web'))
            ->get();
    }

    private function pambOfficeForStage(string $stageKey): ?string
    {
        return match ($stageKey) {
            'forwarded_records_to_penro', 'received_by_penro', 'forwarded_cds_to_penro', 'received_by_penro_final' => 'Office of the PENRO',
            'forwarded_penro_to_tsd', 'received_by_tsd' => 'PENRO TSD Chief',
            'forwarded_tsd_to_cds', 'received_by_cds' => 'PENRO CDS Focal Person',
            'forwarded_cds_focal_to_chief', 'received_by_cds_chief' => 'PENRO CDS Chief',
            'forwarded_penro_to_records', 'received_by_records_final', 'penro_final_approved_for_regional' => 'PENRO Records',
            'penro_final_returned_for_correction' => 'PENRO CDS Focal Person',
            default => null,
        };
    }
    /** @return Collection<int,User> */
    private function workflowRecipients(string $category, ?string $office, mixed $protectedAreaId): Collection
    {
        $canonicalOffice = app(OrganizationalAccessService::class)->normalizeOffice($office);
        $organization = app(OrganizationalAccessService::class);
        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($category, $canonicalOffice, $protectedAreaId, $organization): bool {
            if ($organization->effectiveCategory($user) !== $category) return false;
            if (in_array($category, [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)) return $organization->normalizeOffice($user->office_designated) === $canonicalOffice;
            if ($category === OrganizationalAccessService::PAMO) {
                return (int) $user->protected_area_id === (int) $protectedAreaId
                    && ($canonicalOffice === null || $organization->normalizeOffice($user->office_designated) === $canonicalOffice);
            }
            if (in_array($category, [OrganizationalAccessService::PENRO_RECORDS, OrganizationalAccessService::OFFICE_PENRO, OrganizationalAccessService::PENRO_TSD_CHIEF, OrganizationalAccessService::PENRO_FOCAL, OrganizationalAccessService::PENRO_CHIEF], true)) return true;
            return $canonicalOffice === null || $organization->normalizeOffice($user->office_designated) === $canonicalOffice;
        })->values();
    }

    /** @param Collection<int,User> $users @param array<string,mixed> $payload */
    private function deliverTo(Collection $users, array $payload): array
    {
        $delivered = [];
        foreach ($users as $user) {
            $exists = $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['dedup_key'] ?? null) === $payload['dedup_key']);
            if (! $exists) $user->notify(new EdatsInAppNotification($payload));
            $delivered[] = (int) $user->getKey();
        }

        return array_values(array_unique($delivered));
    }
    private function deliver(array $payload, OverdueReport $report): void
    {
        foreach ($this->recipients($report) as $user) {
            $exists = $user->notifications()->get()->contains(fn ($notification): bool => ($notification->data['dedup_key'] ?? null) === $payload['dedup_key']);
            if (! $exists) {
                $user->notify(new EdatsInAppNotification([
                    ...$payload,
                    'url' => self::actionUrl($payload, $user->can('submission-tracking.view')),
                ]));
            }
        }
    }

    private static function trackingSource(string $sourceType): ?string
    {
        return match ($sourceType) {
            \App\Models\ConservationReportSubmission::class, 'conservation' => 'conservation',
            \App\Models\EngpReportSubmission::class, 'engp' => 'engp',
            \App\Models\BmsReportSubmission::class, 'bms' => 'bms',
            \App\Models\BamsReportSubmission::class, 'bams' => 'bams',
            \App\Models\ImeaReportSubmission::class, 'imea' => 'imea',
            \App\Models\ImeaFacilityMaintenanceReport::class, 'imea-maintenance' => 'imea-maintenance',
            \App\Models\Aws::class, 'aws' => 'aws',
            \App\Models\IpafManagementReport::class, 'ipaf-management' => 'ipaf-management',
            \App\Models\IpafRevenueCollection::class, 'revenue' => 'revenue',
            \App\Models\ManagementPlan::class, 'management-plans' => 'management-plans',
            default => null,
        };
    }

    /** @return Collection<int, User> */
    private function recipients(OverdueReport $report): Collection
    {
        $organization = app(OrganizationalAccessService::class);

        return User::query()->where('is_active', true)->get()->filter(function (User $user) use ($report, $organization): bool {
            if ($user->section === 'MES' || $user->hasRole('no_role')) return false;
            $canMonitor = $user->hasAnyRole(['Super Admin', 'CDS Admin', 'Admin', 'Staff', 'staff']) || $user->can('reports.view');
            if (! $canMonitor) return false;

            if ($report->sourceType === EngpReportSubmission::class) {
                return $organization->canUseDevelopmentOffice($user, $report->targetOffice);
            }

            if (! $organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return false;

            $source = new ConservationReportSubmission([
                'workflow_key' => $report->workflowKey,
                'protected_area_id' => $report->protectedAreaId,
                'target_office' => $report->targetOffice,
            ]);

            return app(PambSubmissionAccessService::class)->canView($user, $source);
        })->values();
    }
}
