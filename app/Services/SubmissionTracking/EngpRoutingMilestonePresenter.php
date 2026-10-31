<?php

namespace App\Services\SubmissionTracking;

use App\Support\DatePresentationNormalizer;
use Illuminate\Support\Collection;

/** Projects the CENRO release milestone from the canonical routing event stream. */
final class EngpRoutingMilestonePresenter
{
    /** @return array{date:?string,applicable:bool,state:'recorded'|'awaiting_cenro_release'|'not_applicable'|'not_recorded'} */
    public static function cenroRelease(Collection $events): array
    {
        $ordered = $events->sortBy(fn ($event): string =>
            (string) data_get($event, 'occurred_at', '').':'.str_pad((string) data_get($event, 'id', 0), 12, '0', STR_PAD_LEFT)
        )->values();

        $release = $ordered->filter(fn ($event): bool =>
            data_get($event, 'event_key') === 'forwarded'
            && data_get($event, 'from_stage') === DocumentRoutingProfileRegistry::CENRO_RECORDS
            && data_get($event, 'to_stage') === DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS
        )->map(fn ($event): ?string => DatePresentationNormalizer::toDateString(data_get($event, 'occurred_at')))
            ->filter()
            ->last();

        if ($release !== null) return ['date' => $release, 'applicable' => true, 'state' => 'recorded'];

        $directRoute = $ordered->contains(fn ($event): bool =>
            data_get($event, 'metadata.route_profile') === 'direct'
            || data_get($event, 'from_stage') === DocumentRoutingProfileRegistry::PENRO_ORIGIN
                && data_get($event, 'to_stage') === DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS
        );
        if ($directRoute) return ['date' => null, 'applicable' => false, 'state' => 'not_applicable'];

        $currentStage = data_get($ordered->last(), 'to_stage');
        if ($currentStage === DocumentRoutingProfileRegistry::CENRO_RECORDS) {
            return ['date' => null, 'applicable' => true, 'state' => 'awaiting_cenro_release'];
        }

        return ['date' => null, 'applicable' => true, 'state' => 'not_recorded'];
    }
}
