<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\Archive\CompletedReportArchiveHook;
use App\Services\Notifications\EdatsInAppNotificationService;
use Throwable;

/** Runs mandatory lifecycle side effects for a recorded routing event. */
final class RoutingTransitionLifecycle
{
    public function __construct(private readonly CompletedReportArchiveHook $archiveHook, private readonly SubmissionTrackingService $tracking) {}

    public function afterTransition(DocumentRoutingEvent|PambRoutingEvent $event, ?User $actor): array
    {
        if ($event instanceof DocumentRoutingEvent) {
            $result = $this->archiveHook->afterRoutingEvent($event, $actor);
            $source = $this->tracking->source((string) $event->source_type);
            if (($result['status'] ?? null) === 'archived' && $source) {
                $record = $source['model']::query()->findOrFail($event->source_id);
                try {
                    app(EdatsInAppNotificationService::class)->notifyGenericTransition($record, (string) $event->source_type, $event, [
                        'key' => data_get($event->metadata, 'action_key'),
                        'event_key' => $event->event_key,
                        'to_office' => $event->to_office,
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            return $result;
        }

        return $this->archiveHook->afterPambTransition(
            'conservation',
            (int) $event->conservation_report_submission_id,
            (string) $event->stage_key,
            $actor,
        );
    }
}
