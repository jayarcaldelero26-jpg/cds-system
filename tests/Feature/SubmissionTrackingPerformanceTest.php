<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    $this->user->assignRole(Role::findOrCreate('Super Admin', 'web'));
});

test('cross-source snapshot paginates bounded candidate queries with exact metadata', function (): void {
    foreach (range(1, 30) as $index) {
        ConservationReportSubmission::create([
            'workflow_key' => 'homestay',
            'activity_name' => 'Performance conservation '.$index,
            'date_accomplished' => sprintf('2026-08-%02d', (($index - 1) % 28) + 1),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    $workflows = app(EngpReportWorkflowRegistry::class);
    foreach (range(0, 29) as $index) {
        $workflow = $workflows->defaultKeys()[intdiv($index, 12)];
        $periods = $workflows->periods($workflow, 2026);
        $period = $periods[$index % count($periods)];
        EngpReportSubmission::create([
            'workflow_key' => $workflow,
            'office' => 'CENRO Baganga',
            'section_name' => 'ENGP',
            'activity_name' => 'Performance ENGP '.$index,
            'document_type' => 'Report',
            'reporting_year' => 2026,
            'period_key' => $period['key'],
            'period_label' => $period['label'],
            'deadline_submission' => $period['deadline'] ?? '2026-12-20',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $pageOne = app(SubmissionTrackingService::class)->snapshot([], 1, 20);
    $pageTwo = app(SubmissionTrackingService::class)->snapshot([], 2, 20);

    expect($pageOne['records'])->toHaveCount(20)
        ->and($pageOne['pagination'])->toMatchArray([
            'current_page' => 1,
            'per_page' => 20,
            'total' => 60,
            'last_page' => 3,
            'from' => 1,
            'to' => 20,
        ])
        ->and($pageOne['pagination']['has_more'])->toBeTrue()
        ->and($pageTwo['records'])->toHaveCount(20)
        ->and(collect($pageOne['records'])->map(fn (array $row): string => $row['source'].':'.$row['source_id'])->intersect(collect($pageTwo['records'])->map(fn (array $row): string => $row['source'].':'.$row['source_id'])))->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'conservation_report_submissions') && str_contains($sql, 'limit'))->isNotEmpty())->toBeTrue()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'engp_report_submissions') && str_contains($sql, 'limit'))->isNotEmpty())->toBeTrue();
});

test('database filters are applied before cross-source normalization', function (): void {
    foreach (range(1, 6) as $index) {
        ConservationReportSubmission::create([
            'workflow_key' => 'homestay',
            'target_office' => $index <= 3 ? 'CENRO Baganga' : 'CENRO Mati',
            'activity_name' => 'Filter conservation '.$index,
            'date_accomplished' => '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    $snapshot = app(SubmissionTrackingService::class)->snapshot([
        'target_office' => 'CENRO Baganga',
        'reporting_year' => 2026,
        'module' => 'Homestay',
    ], 1, 25);

    expect($snapshot['records'])->toHaveCount(3)
        ->and($snapshot['pagination']['total'])->toBe(3)
        ->and($snapshot['records']->every(fn (array $row): bool => $row['target_office'] === 'CENRO Baganga'))->toBeTrue();

    $statusSnapshot = app(SubmissionTrackingService::class)->snapshot([
        'target_office' => 'CENRO Baganga',
        'status' => RoutingStatusPresenter::PENDING_CENRO,
    ], 1, 25);

    expect($statusSnapshot['pagination']['total'])->toBe(3)
        ->and($statusSnapshot['records']->every(fn (array $row): bool => $row['submission_status'] === RoutingStatusPresenter::PENDING_CENRO))->toBeTrue();
});

test('filter options clear inherited source ordering before distinct extraction', function (): void {
    ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Distinct option test',
        'date_accomplished' => '2026-08-01',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $period = app(EngpReportWorkflowRegistry::class)->periods('cbep', 2026)[0];
    EngpReportSubmission::create([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Baganga',
        'section_name' => 'ENGP',
        'activity_name' => 'Distinct option ENGP test',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => $period['key'],
        'period_label' => $period['label'],
        'deadline_submission' => $period['deadline'] ?? '2026-12-20',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $options = app(SubmissionTrackingService::class)->filterOptions();
    $distinctQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'distinct'));

    expect($options['targetOffices'])->toContain('CENRO Baganga')
        ->and($options['periods'])->toContain($period['label'])
        ->and($options['years'])->toContain(2026)
        ->and($options['statuses'])->toContain(RoutingStatusPresenter::COMPLETED)
        ->and($distinctQueries->isNotEmpty())->toBeTrue()
        ->and($distinctQueries->every(fn (string $sql): bool => ! str_contains($sql, 'order by date_accomplished') && ! str_contains($sql, 'order by created_at')))->toBeTrue();
});
