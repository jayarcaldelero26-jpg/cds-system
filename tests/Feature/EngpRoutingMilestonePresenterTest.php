<?php

use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\EngpRoutingMilestonePresenter;

test('ENGP CENRO release uses the canonical CENRO Records handoff date after later Regional release', function () {
    $milestone = EngpRoutingMilestonePresenter::cenroRelease(collect([
        [
            'id' => 86,
            'event_key' => 'forwarded',
            'from_stage' => DocumentRoutingProfileRegistry::CENRO_RECORDS,
            'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
            'occurred_at' => '2026-10-20 11:59:46',
        ],
        [
            'id' => 100,
            'event_key' => 'released',
            'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
            'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
            'occurred_at' => '2026-10-20 12:09:03',
        ],
    ]));

    expect($milestone)->toBe(['date' => '2026-10-20', 'applicable' => true, 'state' => 'recorded']);
});

test('ENGP release presenter distinguishes an awaiting step, a bypassed step, and an unrecorded completed step', function () {
    $awaiting = EngpRoutingMilestonePresenter::cenroRelease(collect([
        ['id' => 1, 'event_key' => 'received', 'to_stage' => DocumentRoutingProfileRegistry::CENRO_RECORDS],
    ]));
    $direct = EngpRoutingMilestonePresenter::cenroRelease(collect([
        ['id' => 2, 'event_key' => 'forwarded', 'from_stage' => DocumentRoutingProfileRegistry::PENRO_ORIGIN,
            'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, 'metadata' => ['route_profile' => 'direct']],
    ]));
    $completedWithoutEvidence = EngpRoutingMilestonePresenter::cenroRelease(collect([
        ['id' => 3, 'event_key' => 'released', 'to_stage' => DocumentRoutingProfileRegistry::RELEASED_REGIONAL],
    ]));

    expect($awaiting)->toBe(['date' => null, 'applicable' => true, 'state' => 'awaiting_cenro_release'])
        ->and($direct)->toBe(['date' => null, 'applicable' => false, 'state' => 'not_applicable'])
        ->and($completedWithoutEvidence)->toBe(['date' => null, 'applicable' => true, 'state' => 'not_recorded']);
});
