<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\User;
use App\Services\Archive\CompletedReportArchiveHook;

/** Runs mandatory lifecycle side effects for a recorded routing event. */
final class RoutingTransitionLifecycle
{
    public function __construct(private readonly CompletedReportArchiveHook $archiveHook) {}

    public function afterTransition(DocumentRoutingEvent|PambRoutingEvent $event, ?User $actor): array
    {
        if ($event instanceof DocumentRoutingEvent) {
            return $this->archiveHook->afterRoutingEvent($event, $actor);
        }

        return $this->archiveHook->afterPambTransition(
            'conservation',
            (int) $event->conservation_report_submission_id,
            (string) $event->stage_key,
            $actor,
        );
    }
}
