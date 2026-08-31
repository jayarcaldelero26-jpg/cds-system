<?php

use App\Mail\OverdueComplianceMemorandum;
use App\Models\BmsReportSubmission;
use App\Models\ComplianceAlertRecipient;
use App\Models\ComplianceAlertSetting;
use App\Models\ComplianceDeliveryClaim;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ComplianceNotificationRun;
use App\Models\ProtectedArea;
use App\Models\ReportComplianceConfirmation;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Compliance\ComplianceAlertTemplateResolver;
use App\Services\Compliance\ComplianceConfirmationService;
use App\Services\Compliance\OverdueReportService;
use App\Services\BusinessCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Manila'));
    config()->set('compliance_alerts.enabled', false);
    config()->set('compliance_alerts.recipients', ['alerts@example.test']);
    config()->set('compliance_alerts.cc_recipients', []);
    Storage::fake('public');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function complianceUser(): User
{
    return User::factory()->create(['is_active' => true]);
}

function complianceArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
}

function bmsForDeadline(ProtectedArea $area, User $user, string $deadline, array $overrides = []): BmsReportSubmission
{
    $report = BmsReportSubmission::create([...[ 
        'protected_area_id' => $area->id, 'target_office' => 'PAMO Pujada Bay', 'activity_name' => 'Monitoring on the Established BMS site',
        'document_type' => 'Final Report', 'semester' => '1st Semester',
        'date_accomplished' => app(BusinessCalendarService::class)->addWorkingDays($deadline, -15)->toDateString(),
        'created_by' => $user->id, 'updated_by' => $user->id,
    ], ...$overrides]);
    if (! array_key_exists('mov_file_path', $overrides)) {
        $path = "bms-report-movs/test-{$report->id}.pdf";
        Storage::disk('public')->put($path, 'test MOV');
        $report->update(['mov_file_name' => 'test.pdf', 'mov_file_path' => $path]);
    }

    return $report->fresh();
}

function engpForDeadline(User $user, string $deadline, array $overrides = []): EngpReportSubmission
{
    return EngpReportSubmission::create([...[
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'ENGP Site Visit Report', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => 'SEP', 'period_label' => 'September',
        'deadline_submission' => $deadline, 'created_by' => $user->id, 'updated_by' => $user->id,
    ], ...$overrides]);
}

function complianceManager(User $user): User
{
    $role = Role::findOrCreate('Compliance Manager', 'web');
    $role->syncPermissions([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('compliance-alerts.manage', 'web'),
    ]);
    $user->assignRole($role);

    return $user;
}

function enabledComplianceSettings(array $overrides = []): ComplianceAlertSetting
{
    return ComplianceAlertSetting::create([...[
        'alerts_enabled' => true,
        'automatic_send_enabled' => true,
        'send_time' => '08:00',
        'timezone' => 'Asia/Manila',
    ], ...$overrides]);
}

test('CDS Admin can change automatic delivery only with the current password and the change is audited', function () {
    config()->set('compliance_alerts.enabled', true);
    $admin = User::factory()->create(['password' => 'secret-password']);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    ComplianceAlertSetting::create(['alerts_enabled' => true, 'automatic_send_enabled' => false, 'send_time' => '08:00', 'timezone' => 'Asia/Manila']);
    $payload = app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective();
    $payload['automatic_send_enabled'] = true;
    $payload['current_password'] = 'wrong-password';

    $wrongPasswordResponse = $this->actingAs($admin)->put(route('compliance-alerts.settings.update'), $payload);
    expect($wrongPasswordResponse->status())->toBe(302)
        ->and($wrongPasswordResponse->baseResponse->getSession()->get('errors'))->not->toBeNull();
    expect(ComplianceAlertSetting::query()->first()->automatic_send_enabled)->toBeFalse();

    $payload['current_password'] = 'secret-password';
    $successResponse = $this->actingAs($admin)->put(route('compliance-alerts.settings.update'), $payload);
    expect($successResponse->status())->toBe(302);
    expect(ComplianceAlertSetting::query()->first()->automatic_send_enabled)->toBeTrue();

    $audit = \App\Models\AuditLog::query()->where('action', 'Automatic Compliance Alert Delivery Enabled')->latest('id')->firstOrFail();
    expect($audit->metadata)->toMatchArray(['previous' => false, 'new' => true])
        ->and($audit->metadata)->not->toHaveKey('password')
        ->and($audit->metadata)->not->toHaveKey('current_password');

    $manager = complianceManager(User::factory()->create(['password' => 'manager-password']));
    $payload['automatic_send_enabled'] = false;
    $payload['current_password'] = 'manager-password';
    $nonAdminResponse = $this->actingAs($manager)->put(route('compliance-alerts.settings.update'), $payload);
    expect($nonAdminResponse->status())->toBe(403);
});

/** @return array<class-string, \Illuminate\Database\Eloquent\Model> */
function recordsForEveryComplianceSource(ProtectedArea $area, User $user, string $deadline): array
{
    $standardAStart = app(BusinessCalendarService::class)->addWorkingDays($deadline, -15)->toDateString();
    $standardBStart = app(BusinessCalendarService::class)->addWorkingDays($deadline, -7)->toDateString();
    $periodKey = substr($deadline, 0, 7);
    $periodLabel = CarbonImmutable::parse($deadline)->format('F Y');
    $common = ['protected_area_id' => $area->id, 'target_office' => 'Baganga', 'activity_name' => 'Compliance activity', 'document_type' => 'Final Report', 'created_by' => $user->id, 'updated_by' => $user->id];

    $records = [
        ConservationReportSubmission::class => ConservationReportSubmission::create([...$common, 'workflow_key' => 'regular_pamb', 'reporting_period' => 'Quarter 1', 'date_accomplished' => $standardBStart]),
        EngpReportSubmission::class => EngpReportSubmission::create(['workflow_key' => 'cbep', 'office' => 'CENRO Baganga', 'section_name' => 'NGP', 'activity_name' => 'Community-Based Employment Program (CBEP)', 'document_type' => 'Monthly Report', 'reporting_year' => 2026, 'period_key' => $periodKey, 'period_label' => $periodLabel, 'deadline_submission' => $deadline, 'created_by' => $user->id, 'updated_by' => $user->id]),
        BmsReportSubmission::class => BmsReportSubmission::create([...$common, 'semester' => '1st Semester', 'date_accomplished' => $standardAStart]),
        \App\Models\BamsReportSubmission::class => \App\Models\BamsReportSubmission::create([...$common, 'semester' => '1st Semester', 'date_accomplished' => $standardAStart]),
        \App\Models\ImeaReportSubmission::class => \App\Models\ImeaReportSubmission::create([...$common, 'semester' => '1st Semester', 'date_accomplished' => $standardAStart]),
        \App\Models\ImeaFacilityMaintenanceReport::class => \App\Models\ImeaFacilityMaintenanceReport::create([...$common, 'quarter' => 'Quarter 1', 'date_accomplished' => $standardBStart]),
        \App\Models\Aws::class => \App\Models\Aws::create([...$common, 'station_name' => 'Baganga AWS', 'location' => 'Baganga', 'status' => 'Active', 'date_accomplished' => $standardBStart]),
        \App\Models\ManagementPlan::class => \App\Models\ManagementPlan::create([...$common, 'plan_type' => 'Protected Area Management Plan', 'status' => 'Pending', 'date_accomplished' => $standardBStart]),
        \App\Models\IpafManagementReport::class => \App\Models\IpafManagementReport::create([...$common, 'date_accomplished' => $standardBStart]),
        \App\Models\IpafRevenueCollection::class => \App\Models\IpafRevenueCollection::create([...$common, 'activity_name' => 'Revenue Collection', 'reporting_month' => 7, 'reporting_year' => 2026, 'total_collected' => '1000.00', 'deadline_submission' => $deadline]),
    ];
    foreach ($records as $sourceType => $record) {
        $path = 'compliance-test-movs/'.str_replace('\\', '-', strtolower(class_basename($sourceType)))."-{$record->id}.pdf";
        Storage::disk('public')->put($path, 'test MOV');
        if ($record instanceof TechnicalReport) {
            $record->update(['attachment' => $path, 'attachment_original_name' => 'test.pdf']);
        } elseif ($record instanceof \App\Models\Aws) {
            $record->update(['report_file_path' => $path, 'report_file_name' => 'test.pdf']);
        } elseif ($record instanceof \App\Models\ManagementPlan) {
            $record->update(['attachments' => [['path' => $path, 'original_name' => 'test.pdf']]]);
        } else {
            $record->update(['mov_file_path' => $path, 'mov_file_name' => 'test.pdf']);
        }
        $records[$sourceType] = $record->fresh();
    }

    return $records;
}

test('future and today deadlines are not overdue while yesterday is one calendar day overdue', function () {
    $user = complianceUser(); $area = complianceArea($user);
    bmsForDeadline($area, $user, '2026-08-25');
    bmsForDeadline($area, $user, '2026-09-01');
    $yesterday = bmsForDeadline($area, $user, '2026-08-24');

    $overdue = app(OverdueReportService::class)->overdueReports();

    expect($overdue)->toHaveCount(1)
        ->and($overdue->first()->sourceId)->toBe($yesterday->id)
        ->and($overdue->first()->daysOverdue)->toBe(1);
});

test('overdue days use calendar days rather than tracker working-day formulas', function () {
    $user = complianceUser(); $area = complianceArea($user);
    $report = bmsForDeadline($area, $user, '2026-08-20');

    $normalized = app(OverdueReportService::class)->overdueReports()->first();

    expect($report->deadline_submission)->toBe('2026-08-20')
        ->and($normalized->daysOverdue)->toBe(5);
});

test('multiple tracker models normalize into the same overdue DTO', function () {
    $user = complianceUser(); $area = complianceArea($user);
    $bms = bmsForDeadline($area, $user, '2026-08-24');
    $technical = TechnicalReport::create([
        'protected_area_id' => $area->id, 'report_type' => 'Technical Assessment', 'activity_name' => 'Technical Assessment Activity',
        'target_office' => 'PAMO Pujada Bay', 'date_accomplished' => app(BusinessCalendarService::class)->addWorkingDays('2026-08-24', -7)->toDateString(),
        'status' => 'Pending', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    $reports = app(OverdueReportService::class)->overdueReports();

    expect($reports->pluck('sourceId')->all())->toContain($bms->id)
        ->and($reports->pluck('module')->all())->not->toContain('Technical Reports');
});

test('authoritative PENRO receipt closes the active alert immediately and sends the report to Records verification', function () {
    $user = complianceUser(); $area = complianceArea($user);
    $report = bmsForDeadline($area, $user, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    $service = app(OverdueReportService::class);

    expect($service->overdueReports())->toBeEmpty()
        ->and($service->pendingRecordsVerification())->toHaveCount(1)
        ->and($service->pendingRecordsVerification()->first()['source_id'])->toBe($report->id)
        ->and($service->pendingRecordsVerification()->first()['submission_date'])->toBe('2026-08-25')
        ->and($service->pendingRecordsVerification()->first()['submission_status'])->toBe('Pending Submission by CENRO');

    app(ComplianceConfirmationService::class)->confirm($report, $user, 'Received by Records');

    expect($service->overdueReports())->toBeEmpty()
        ->and($service->pendingRecordsVerification())->toBeEmpty()
        ->and($service->confirmationHistory())->toHaveCount(1);
});

test('confirmed reports leave active Overview but remain in Records Confirmation History', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    app(ComplianceConfirmationService::class)->confirm($report, $manager, 'Received and stamped by Records.');

    expect(app(OverdueReportService::class)->overdueReports())->toBeEmpty();

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups', [])
            ->has('confirmationHistory', 1)
            ->where('confirmationHistory.0.source_id', $report->id)
            ->where('confirmationHistory.0.protected_area_name', 'Pujada Bay Protected Landscape')
            ->where('confirmationHistory.0.target_office', 'PAMO Pujada Bay')
            ->where('confirmationHistory.0.confirmed_by', $manager->name)
            ->where('confirmationHistory.0.remarks', 'Received and stamped by Records.')
            ->where('confirmationHistory.0.status', 'confirmed'));
});

test('submitted but unconfirmed reports are visible in Pending Records Verification, not the active Overview', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.overdue_reports', 0)
            ->where('groups', [])
            ->has('pendingRecordsVerification', 1)
            ->where('pendingRecordsVerification.0.source_id', $report->id)
            ->where('pendingRecordsVerification.0.submission_date', '2026-08-25')
            ->where('pendingRecordsVerification.0.records_confirmed', false));
});

test('successful Records confirmation returns the success flash and refreshed pending/history datasets', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);

    $this->actingAs($manager)->post(route('compliance-alerts.confirm'), [
        'source_type' => BmsReportSubmission::class,
        'source_id' => $report->id,
        'remarks' => 'Verified by Records.',
    ])->assertRedirect()->assertSessionHas('success', 'Records confirmation saved. The report has moved to Records Confirmation History.');

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingRecordsVerification', [])
            ->has('confirmationHistory', 1)
            ->where('confirmationHistory.0.source_id', $report->id)
            ->where('confirmationHistory.0.remarks', 'Verified by Records.'));
});

test('confirmation history safely labels a missing source record', function () {
    $manager = complianceManager(complianceUser());
    $confirmation = \App\Models\ReportComplianceConfirmation::create([
        'source_type' => BmsReportSubmission::class, 'source_id' => 999999,
        'confirmed_at' => CarbonImmutable::now('Asia/Manila'), 'confirmed_by' => $manager->id, 'remarks' => 'Legacy receipt.',
    ]);

    $history = app(OverdueReportService::class)->confirmationHistory();

    expect($history)->toHaveCount(1)
        ->and($history->first()['id'])->toBe($confirmation->id)
        ->and($history->first()['activity'])->toBe('Source record no longer exists')
        ->and($history->first()['status'])->toBe('confirmed');
});

test('automatic runs deduplicate a sent memorandum for the same recipient and local date', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-20');
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendAutomatic();
    $delivery->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    expect(ComplianceNotificationRun::query()->where('is_manual', false)->where('status', 'sent')->count())->toBe(1);
});

test('recipient mapping is per-candidate while production Send Now remains guarded during the preview phase', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $mappedArea = complianceArea($manager);
    $mhrws = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $manager->id, 'updated_by' => $manager->id]);
    bmsForDeadline($mappedArea, $manager, '2026-08-24');
    bmsForDeadline($mhrws, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $mappedArea->id, 'recipient_email' => 'mapped@example.test', 'is_active' => true]);
    enabledComplianceSettings();

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();
    expect(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'skipped')->where('error_message', 'Recipient mapping is missing for this Protected Area / office group.')->count())->toBe(1);

    $this->actingAs($manager)->post(route('compliance-alerts.send'))->assertRedirect()->assertSessionHasErrors(['delivery' => 'Production compliance delivery is disabled during the memorandum preview phase. Use Preview Notification or Send Test Email.']);
    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    expect(ComplianceNotificationRun::query()->where('run_type', 'manual')->count())->toBe(0);
});

test('Compliance Alerts No Recipient Mapping card counts current candidates and has no help marker', function () {
    $manager = complianceManager(complianceUser());
    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page->where('summary.unmapped_recipients', 0));
    $mapped = complianceArea($manager);
    $unmapped = ProtectedArea::create(['name' => 'Unmapped Protected Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $manager->id, 'updated_by' => $manager->id]);
    bmsForDeadline($mapped, $manager, '2026-08-24');
    bmsForDeadline($unmapped, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $mapped->id, 'recipient_email' => 'mapped-card@example.test', 'is_active' => true]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.overdue_reports', 2)
        ->where('summary.unmapped_recipients', 1));
    $jsx = file_get_contents(resource_path('js/Pages/ComplianceAlerts/Index.jsx'));
    expect($jsx)->toContain('Card label="No Recipient Mapping" value={activeScope.summary?.unmapped_destinations ?? 0} tone="red" />')
        ->not->toContain('No Recipient Mapping" value={activeScope.summary?.unmapped_destinations ?? 0} tone="red" help=');
});


test('destination coverage remains distinct and separate from current alert coverage', function () {
    $manager = complianceManager(complianceUser());
    engpForDeadline($manager, '2026-09-30', ['period_key' => 'MAPPED-1', 'office' => 'CENRO Baganga']);
    engpForDeadline($manager, '2026-09-30', ['period_key' => 'MAPPED-2', 'office' => 'CENRO Cateel']);
    engpForDeadline($manager, '2026-09-30', ['period_key' => 'UNMAPPED-1', 'office' => 'CENRO Manay']);
    engpForDeadline($manager, '2026-09-30', ['period_key' => 'UNMAPPED-2', 'office' => 'CENRO Caraga']);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'recipient_email' => 'baganga@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Cateel', 'recipient_email' => 'cateel@example.test', 'is_active' => true]);

    $delivery = app(ComplianceAlertDeliveryService::class);
    expect($delivery->currentAlertReports())->toBeEmpty()
        ->and($delivery->recipientReadiness($delivery->currentAlertReports()))->toBeEmpty();

    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.unmapped_recipients', 0)
        ->where('summary.unmapped_destinations', 2));
    $this->actingAs($manager)->get(route('compliance-alert-recipients.index'))->assertInertia(fn (Assert $page) => $page
        ->where('mappingMetrics.mapped', 2)
        ->where('mappingMetrics.unmapped', 2)
        ->where('mappingMetrics.total', 4));

    engpForDeadline($manager, '2026-09-30', ['period_key' => 'UNMAPPED-1-DUPLICATE', 'office' => 'CENRO Manay']);
    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.unmapped_destinations', 2));

    ComplianceAlertRecipient::create(['target_office' => 'CENRO Manay', 'recipient_email' => 'manay@example.test', 'is_active' => true]);
    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.unmapped_destinations', 1));

    ComplianceAlertRecipient::create(['target_office' => 'CENRO Caraga', 'recipient_email' => 'caraga@example.test', 'is_active' => true]);
    $this->actingAs($manager)->get(route('compliance-alerts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.unmapped_destinations', 0));
});
test('due-soon uses three calendar days across weekends and excludes completed or no-deadline records', function () {
    $user = complianceUser();
    $tuesday = engpForDeadline($user, '2026-09-08', ['period_key' => 'SEP-08-TUE']);
    $wednesday = engpForDeadline($user, '2026-09-09', ['period_key' => 'SEP-09-WED']);
    $monday = engpForDeadline($user, '2026-09-07', ['period_key' => 'SEP-07-MON']);
    $completed = engpForDeadline($user, '2026-09-08', ['period_key' => 'SEP-08-COMPLETE', 'date_received_penro' => '2026-09-05']);
    $withoutDeadline = BmsReportSubmission::create([
        'protected_area_id' => complianceArea($user)->id, 'target_office' => 'PAMO Pujada Bay',
        'activity_name' => 'No deadline configured', 'document_type' => 'Final Report', 'semester' => '1st Semester',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $service = app(OverdueReportService::class);

    $saturday = $service->dueSoonReports(3, CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Manila'));
    $sunday = $service->dueSoonReports(3, CarbonImmutable::parse('2026-09-06 08:00:00', 'Asia/Manila'));
    $friday = $service->dueSoonReports(3, CarbonImmutable::parse('2026-09-04 08:00:00', 'Asia/Manila'));

    expect($saturday->pluck('sourceId'))->toContain($tuesday->id)
        ->and($saturday->pluck('sourceId'))->not->toContain($completed->id)
        ->and($sunday->pluck('sourceId'))->toContain($wednesday->id)
        ->and($friday->pluck('sourceId'))->toContain($monday->id)
        ->and($friday->contains(fn ($report) => $report->sourceType === BmsReportSubmission::class && $report->sourceId === $withoutDeadline->id))->toBeFalse();
});

test('automatic due-soon delivery sends once when Saturday is three calendar days before a Tuesday deadline', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Manila'));
    $user = complianceUser();
    engpForDeadline($user, '2026-09-08');
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'recipient_email' => 'weekend@example.test', 'is_active' => true]);
    enabledComplianceSettings();
    $delivery = app(ComplianceAlertDeliveryService::class);

    $first = $delivery->sendAutomatic();
    $second = $delivery->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    expect($first->where('alert_type', ComplianceNotificationRun::ALERT_DUE_SOON)->where('status', ComplianceNotificationRun::STATUS_SENT))->toHaveCount(1)
        ->and($second->where('alert_type', ComplianceNotificationRun::ALERT_DUE_SOON)->where('status', ComplianceNotificationRun::STATUS_SKIPPED))->toHaveCount(1)
        ->and(ComplianceDeliveryClaim::query()->where('status', ComplianceDeliveryClaim::STATUS_SENT)->count())->toBe(1);
});

test('production Send Now remains guarded during the preview phase', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 08:00:00', 'Asia/Manila'));
    $manager = complianceManager(complianceUser());
    engpForDeadline($manager, '2026-09-07', ['period_key' => 'SEP-07-DUE-SOON']);
    engpForDeadline($manager, '2026-09-04', ['period_key' => 'SEP-04-DUE-TODAY']);
    engpForDeadline($manager, '2026-09-03', ['period_key' => 'SEP-03-OVERDUE']);
    engpForDeadline($manager, '2026-09-20', ['period_key' => 'SEP-20-NOT-DUE']);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'recipient_email' => 'manual-current@example.test', 'is_active' => true]);
    enabledComplianceSettings();

    $this->actingAs($manager)->post(route('compliance-alerts.send'))->assertRedirect()->assertSessionHasErrors(['delivery' => 'Production compliance delivery is disabled during the memorandum preview phase. Use Preview Notification or Send Test Email.']);

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->where('run_type', ComplianceNotificationRun::TYPE_MANUAL)->count())->toBe(0);
});

test('manual and test delivery remain available on weekends', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true); config()->set('compliance_alerts.test_email_enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 08:00:00', 'Asia/Manila'));
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);
    enabledComplianceSettings(['test_recipient_email' => 'test@example.test']);
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $user);
    $delivery->sendTest(app(OverdueReportService::class)->overdueReports(), $user);

    Mail::assertSent(OverdueComplianceMemorandum::class, 2);
    expect(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'sent')->exists())->toBeTrue()
        ->and(ComplianceNotificationRun::query()->where('run_type', 'test')->where('status', 'sent')->exists())->toBeTrue();
});

test('one target office receives a single memorandum with its Protected Area sections', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser();
    $baganga = ProtectedArea::create(['name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $otherBagangaArea = ProtectedArea::create(['name' => 'Baganga Mangrove Reserve', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    bmsForDeadline($baganga, $user, '2026-08-24', ['target_office' => 'Baganga']);
    bmsForDeadline($otherBagangaArea, $user, '2026-08-24', ['target_office' => 'Baganga']);
    ComplianceAlertRecipient::query()->where('target_office', 'Baganga')->firstOrFail()->update(['recipient_email' => 'baganga@example.test']);
    enabledComplianceSettings();

    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());
    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    expect($plan['deliveries'])->toHaveCount(1)
        ->and($plan['deliveries']->first()['reports'])->toHaveCount(2);
    Mail::assertSent(OverdueComplianceMemorandum::class, fn (OverdueComplianceMemorandum $mail) => count($mail->groups) === 2);
});

test('office recipient presentation rules are data-driven for Baganga, Mati, and Hamiguitan', function () {
    $user = complianceUser();
    $baganga = complianceArea($user); $mati = ProtectedArea::create(['name' => 'Mati Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $hamiguitan = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    bmsForDeadline($baganga, $user, '2026-08-24', ['target_office' => 'Baganga']);
    bmsForDeadline($mati, $user, '2026-08-24', ['target_office' => 'Mati']);
    bmsForDeadline($hamiguitan, $user, '2026-08-24', ['target_office' => 'Hamiguitan']);
    ComplianceAlertRecipient::query()->where('target_office', 'Baganga')->firstOrFail()->update(['recipient_email' => 'baganga@example.test']);
    ComplianceAlertRecipient::query()->where('target_office', 'Mati')->firstOrFail()->update(['recipient_email' => 'mati@example.test']);
    ComplianceAlertRecipient::query()->where('target_office', 'Hamiguitan')->firstOrFail()->update(['recipient_email' => 'hamiguitan@example.test']);

    $deliveries = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports())['deliveries']->keyBy(fn (array $delivery) => $delivery['reports']->first()->targetOffice);

    expect($deliveries['Baganga']['recipient']->name)->toBe('The Deputy PASu of Baganga')
        ->and($deliveries['Baganga']['recipient']->attentionLine)->toBe('Chief, Conservation and Development Section')
        ->and($deliveries['Mati']['recipient']->name)->toBe('The Deputy PASu of Mati')
        ->and($deliveries['Mati']['recipient']->attentionLine)->toBe('Chief, Conservation and Development Section')
        ->and($deliveries['Hamiguitan']['recipient']->name)->toBe('The OIC, PASu of Hamiguitan')
        ->and($deliveries['Hamiguitan']['recipient']->attentionLine)->toBe('');
});

test('manual send is audited separately from an automatic run', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendAutomatic();
    $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $user);

    expect(ComplianceNotificationRun::query()->where('is_manual', false)->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('is_manual', true)->where('status', 'sent')->count())->toBe(1);
});

test('disabled alerts do not send external mail and log a skipped run', function () {
    Mail::fake();
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->value('status'))->toBe('skipped');
});

test('the mailable receives grouped overdue data', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail): bool {
        return count($mail->groups) === 1
            && $mail->groups[0]['protected_area_name'] === 'Pujada Bay Protected Landscape'
            && $mail->groups[0]['reports'][0]['days_overdue'] === 1;
    });
});

test('unauthorized users cannot confirm reports or manually send alerts', function () {
    $manager = complianceUser(); $area = complianceArea($manager); $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    $viewer = complianceUser();
    $role = Role::findOrCreate('Compliance Viewer', 'web');
    $viewPermission = Permission::findOrCreate('reports.view', 'web');
    $role->syncPermissions([$viewPermission]);
    $viewer->assignRole($role);

    $this->actingAs($viewer)->post(route('compliance-alerts.confirm'), ['source_type' => BmsReportSubmission::class, 'source_id' => $report->id])->assertForbidden();
    $this->actingAs($viewer)->post(route('compliance-alerts.send'))->assertForbidden();
});

test('existing Standard A and Standard B deadline calculations remain authoritative', function () {
    $user = complianceUser(); $area = complianceArea($user);
    $standardA = bmsForDeadline($area, $user, '2026-08-24');
    $standardB = TechnicalReport::create([
        'protected_area_id' => $area->id, 'report_type' => 'Technical Assessment', 'activity_name' => 'Assessment', 'target_office' => 'PAMO Pujada Bay',
        'date_accomplished' => '2026-08-14', 'status' => 'Pending', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    expect($standardA->deadline_submission)->toBe('2026-08-24')
        ->and($standardB->deadline_submission)->toBe('2026-08-26');
});

test('an exact Protected Area recipient mapping wins over office and fallback mappings', function () {
    $user = complianceUser(); $area = complianceArea($user); $report = bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);
    enabledComplianceSettings(['fallback_recipient_email' => 'fallback@example.test']);

    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());

    expect($plan['deliveries'])->toHaveCount(1)
        ->and($plan['deliveries']->first()['recipient']->email)->toBe('pa@example.test')
        ->and($report)->toBeInstanceOf(BmsReportSubmission::class);
});

test('new memorandum recipient addressing uses institutional designations only', function () {
    $user = complianceUser();
    $area = complianceArea($user);
    bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create([
        'protected_area_id' => $area->id,
        'target_office' => 'PAMO Pujada Bay',
        'recipient_name' => 'Jane Doe',
        'attention_line' => 'John Smith',
        'recipient_email' => 'institutional@example.test',
        'is_active' => true,
    ]);

    $recipient = app(ComplianceAlertDeliveryService::class)
        ->deliveryPlan(app(OverdueReportService::class)->overdueReports())['deliveries']
        ->first()['recipient'];

    expect($recipient->email)->toBe('institutional@example.test')
        ->and($recipient->name)->toBe('The OIC, PASu')
        ->and($recipient->attentionLine)->toBe('');
});

test('office mapping is used when a PA mapping is absent and missing mappings remain unmapped', function () {
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]);

    $officePlan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());
    expect($officePlan['deliveries']->first()['recipient']->email)->toBe('office@example.test');

    ComplianceAlertRecipient::query()->update(['is_active' => false]);
    $fallbackPlan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());

    expect($fallbackPlan['deliveries'])->toBeEmpty()
        ->and($fallbackPlan['unmapped'])->toHaveCount(1);
});

test('inactive mappings are ignored and unmapped automatic groups are safely skipped', function () {
    Mail::fake(); config()->set('compliance_alerts.recipients', []); config()->set('compliance_alerts.fallback_recipient_email', '');
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'inactive@example.test', 'is_active' => false]);
    enabledComplianceSettings(['fallback_recipient_email' => null]);

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->first()->status)->toBe('skipped')
        ->and(ComplianceNotificationRun::query()->first()->error_message)->toContain('Recipient mapping is missing');
});

test('a deactivated recipient is ignored and resolves again after reactivation', function () {
    config()->set('compliance_alerts.fallback_recipient_email', ''); config()->set('compliance_alerts.recipients', []);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    $mapping = ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => false]);
    $service = app(ComplianceAlertDeliveryService::class);

    expect($service->deliveryPlan(app(OverdueReportService::class)->overdueReports())['unmapped'])->toHaveCount(1);
    $mapping->update(['is_active' => true]);
    $service = app(ComplianceAlertDeliveryService::class);
    expect($service->deliveryPlan(app(OverdueReportService::class)->overdueReports())['deliveries']->first()['recipient']->email)->toBe('office@example.test');
});

test('used recipient mappings cannot be deleted and notification history remains unchanged after deactivation', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser()); $area = complianceArea($manager); bmsForDeadline($area, $manager, '2026-08-24');
    $mapping = ComplianceAlertRecipient::create(['target_office' => 'PAMO Pujada Bay', 'recipient_email' => 'office@example.test', 'is_active' => true]); enabledComplianceSettings();
    app(ComplianceAlertDeliveryService::class)->sendAutomatic();
    $run = ComplianceNotificationRun::query()->first();

    $this->actingAs($manager)->patch(route('compliance-alerts.recipients.status', $mapping), ['is_active' => false])->assertRedirect();
    $this->actingAs($manager)->delete(route('compliance-alerts.recipients.destroy', $mapping))->assertSessionHasErrors('recipient');

    expect($mapping->fresh()->is_active)->toBeFalse()
        ->and($run->fresh()->recipients)->toBe(['office@example.test'])
        ->and(ComplianceAlertRecipient::query()->whereKey($mapping->id)->exists())->toBeTrue();
});

test('unused recipient mappings can be deleted by an authorized manager', function () {
    $manager = complianceManager(complianceUser());
    $mapping = ComplianceAlertRecipient::create(['target_office' => 'Unused Office', 'recipient_email' => 'unused@example.test', 'is_active' => true]);

    $this->actingAs($manager)->delete(route('compliance-alerts.recipients.destroy', $mapping))->assertRedirect()->assertSessionHas('success');
    expect(ComplianceAlertRecipient::query()->whereKey($mapping->id)->exists())->toBeFalse();
});

test('view-only users cannot edit recipient lifecycle state', function () {
    $manager = complianceManager(complianceUser());
    $mapping = ComplianceAlertRecipient::create(['target_office' => 'Restricted Office', 'recipient_email' => 'restricted@example.test', 'is_active' => true]);
    $viewer = complianceUser(); $role = Role::findOrCreate('Compliance Read Only Lifecycle', 'web');
    $role->syncPermissions([Permission::findOrCreate('reports.view', 'web')]); $viewer->assignRole($role);

    $this->actingAs($viewer)->patch(route('compliance-alerts.recipients.status', $mapping), ['is_active' => false])->assertForbidden();
    expect($mapping->fresh()->is_active)->toBeTrue();
});

test('recipient validation rejects malformed addresses and duplicate active mappings', function () {
    $manager = complianceManager(complianceUser()); $area = complianceArea($manager);

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'protected_area_id' => $area->id, 'recipient_email' => 'not-an-email', 'is_active' => true,
    ])->assertSessionHasErrors('recipient_email');

    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'one@example.test', 'is_active' => true]);
    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'protected_area_id' => $area->id, 'recipient_email' => 'two@example.test', 'is_active' => true,
    ])->assertSessionHasErrors('protected_area_id');
});

test('only submitted reports can be Records-confirmed and confirmation records user time and remarks', function () {
    $user = complianceUser(); $area = complianceArea($user); $report = bmsForDeadline($area, $user, '2026-08-24');

    expect(fn () => app(ComplianceConfirmationService::class)->confirm($report, $user))->toThrow(\Illuminate\Validation\ValidationException::class);

    $report->update(['date_received_penro' => '2026-08-25']);
    $confirmation = app(ComplianceConfirmationService::class)->confirm($report->fresh(), $user, 'Stamped received.');

    expect($confirmation->confirmed_by)->toBe($user->id)
        ->and($confirmation->confirmed_at->toDateString())->toBe('2026-08-25')
        ->and($confirmation->remarks)->toBe('Stamped received.');
});

test('Records Confirmation history exposes operational module and location labels', function () {
    $user = complianceUser();
    $report = EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'ENGP Site Visit Report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q3', 'period_label' => 'Quarter 3',
        'deadline_submission' => '2026-08-20', 'date_received_penro' => '2026-08-21',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    app(ComplianceConfirmationService::class)->confirm($report, $user);

    $history = app(OverdueReportService::class)->confirmationHistory()->first();

    expect($history['module_name'])->toBe('Site Visit')
        ->and($history['location_label'])->toBe('CENRO Baganga')
        ->and($history['reporting_period'])->toBe('Quarter 3');
});

test('settings editing and Records unconfirmation require compliance alert management authority', function () {
    $manager = complianceUser(); $area = complianceArea($manager); $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    app(ComplianceConfirmationService::class)->confirm($report, $manager);
    $viewer = complianceUser(); $role = Role::findOrCreate('Compliance Read Only', 'web'); $role->syncPermissions([Permission::findOrCreate('reports.view', 'web')]); $viewer->assignRole($role);

    $this->actingAs($viewer)->put(route('compliance-alerts.settings.update'), [])->assertForbidden();
    $this->actingAs($viewer)->delete(route('compliance-alerts.unconfirm'), ['source_type' => BmsReportSubmission::class, 'source_id' => $report->id])->assertForbidden();
});

test('test email goes only to the configured test recipient and does not block automatic delivery', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true); config()->set('compliance_alerts.test_email_enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'real-pa@example.test', 'is_active' => true]);
    enabledComplianceSettings(['test_recipient_email' => 'test-only@example.test']);
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendTest(app(OverdueReportService::class)->overdueReports(), $user);
    $delivery->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, 2);
    expect(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'test')->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'test')->first()->recipients)->toBe(['test-only@example.test']);
});

test('manual delivery does not block automatic deduplication and safe mode blocks manual delivery', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);
    enabledComplianceSettings();
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $user);
    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'sent')->count())->toBe(1);

    config()->set('compliance_alerts.enabled', false);
    expect(fn () => $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('a failed automatic send is logged as failed and remains retryable', function () {
    config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]); enabledComplianceSettings();
    $mailManager = app('mail.manager');
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mail transport unavailable'));
    $delivery = app(ComplianceAlertDeliveryService::class);

    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->first()->status)->toBe('failed');

    app()->instance('mail.manager', $mailManager); Mail::swap($mailManager); Mail::fake();
    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'sent')->count())->toBe(1);
});

test('preview and history use the same memorandum data while snapshots remain stable', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser()); $area = complianceArea($manager); $report = bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_name' => 'Pujada Office', 'recipient_email' => 'pa@example.test', 'is_active' => true]); enabledComplianceSettings();
    app(ComplianceAlertDeliveryService::class)->sendAutomatic();
    $run = ComplianceNotificationRun::query()->with('reports')->first();
    $snapshotActivity = $run->reports->first()->snapshot['activity'];
    $report->update(['activity_name' => 'Changed after sending']);

    $this->actingAs($manager)->get(route('compliance-alerts.preview'))->assertOk()->assertSee('The OIC, PASu')->assertDontSee('Pujada Office')->assertSee('Changed after sending')->assertSee('MEMORANDUM');
    expect($run->fresh()->reports->first()->snapshot['activity'])->toBe($snapshotActivity);
});

test('test email is isolated from production recipients and production CC addresses', function () {
    Mail::fake(); config()->set('compliance_alerts.test_email_enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'production-pa@example.test', 'cc_emails' => ['production-cc@example.test'], 'is_active' => true]);
    enabledComplianceSettings(['test_recipient_email' => 'controlled-test@example.test', 'fallback_cc_emails' => ['fallback-cc@example.test']]);

    app(ComplianceAlertDeliveryService::class)->sendTest(app(OverdueReportService::class)->overdueReports(), $user);

    $run = ComplianceNotificationRun::query()->where('run_type', 'test')->first();
    expect($run->recipients)->toBe(['controlled-test@example.test'])
        ->and($run->cc_recipients)->toBe([])
        ->and($run->payload['recipient']['source'])->toBe('test')
        ->and($run->subject)->toBe("\u{26A0} PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports");
});

test('test email is blocked when no reports are overdue', function () {
    Mail::fake(); config()->set('compliance_alerts.test_email_enabled', true);
    $user = complianceUser(); enabledComplianceSettings(['test_recipient_email' => 'controlled-test@example.test']);

    expect(fn () => app(ComplianceAlertDeliveryService::class)->sendTest(collect(), $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->where('run_type', 'test')->count())->toBe(0);
});

test('automatic delivery stays blocked unless both the environment and database gates are enabled', function () {
    Mail::fake(); $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);
    $delivery = app(ComplianceAlertDeliveryService::class);

    config()->set('compliance_alerts.enabled', false); enabledComplianceSettings();
    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->latest('id')->first()->status)->toBe('skipped')
        ->and($delivery->automaticDeliveryState()['effective'])->toBeFalse();

    ComplianceAlertSetting::query()->delete(); config()->set('compliance_alerts.enabled', true);
    enabledComplianceSettings(['alerts_enabled' => false, 'automatic_send_enabled' => true]);
    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->latest('id')->first()->status)->toBe('skipped')
        ->and($delivery->automaticDeliveryState()['effective'])->toBeFalse();

    ComplianceAlertSetting::query()->delete(); enabledComplianceSettings();
    $delivery->sendAutomatic();
    expect(ComplianceNotificationRun::query()->latest('id')->first()->status)->toBe('sent')
        ->and($delivery->automaticDeliveryState()['effective'])->toBeTrue();
});

test('dry run command never invokes mail and reports mapped destinations', function () {
    Mail::fake(); config()->set('compliance_alerts.enabled', true);
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]); enabledComplianceSettings();

    $this->artisan('compliance:send-overdue-alerts', ['--dry-run' => true])
        ->expectsOutputToContain('Mode: dry run (Mail is never called).')
        ->expectsOutputToContain('READY')
        ->assertExitCode(0);

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->first()->run_type)->toBe('dry_run');
});

test('the compliance source registry covers every legitimate report submission tracker exactly once', function () {
    $definitions = app(OverdueReportService::class)->sourceDefinitions();

    expect(array_keys($definitions))->toBe([
        ConservationReportSubmission::class,
        EngpReportSubmission::class,
        BmsReportSubmission::class,
        \App\Models\BamsReportSubmission::class,
        \App\Models\ImeaReportSubmission::class,
        \App\Models\ImeaFacilityMaintenanceReport::class,
        \App\Models\Aws::class,
        \App\Models\ManagementPlan::class,
        \App\Models\IpafManagementReport::class,
        \App\Models\IpafRevenueCollection::class,
    ])
        ->and(count(array_keys($definitions)))->toBe(count(array_unique(array_keys($definitions))));

    foreach ($definitions as $definition) {
        expect($definition)->toHaveKeys(['module', 'submitted', 'activity', 'document']);
    }

    expect($definitions[ConservationReportSubmission::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[EngpReportSubmission::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[BmsReportSubmission::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\BamsReportSubmission::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\ImeaReportSubmission::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\ImeaFacilityMaintenanceReport::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\Aws::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\ManagementPlan::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\IpafManagementReport::class]['submitted'])->toBe('date_received_penro')
        ->and($definitions[\App\Models\IpafRevenueCollection::class]['submitted'])->toBe('date_received_penro');
});

test('submitted reports are excluded from preview, manual, and automatic memorandum delivery while Records is pending', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);
    enabledComplianceSettings();
    $reports = app(OverdueReportService::class)->overdueReports();

    expect($reports)->toBeEmpty()
        ->and(app(ComplianceAlertDeliveryService::class)->deliveryPlan($reports)['deliveries'])->toBeEmpty()
        ->and(app(OverdueReportService::class)->pendingRecordsVerification())->toHaveCount(1);

    $this->actingAs($manager)->get(route('compliance-alerts.preview'))
        ->assertRedirect(route('compliance-alerts.index'))
        ->assertSessionHasErrors(['preview' => 'No current overdue reports to preview.']);

    $delivery = app(ComplianceAlertDeliveryService::class);
    expect($delivery->sendManual($reports, $manager))->toBeEmpty()
        ->and($delivery->sendAutomatic())->toBeEmpty();
    Mail::assertNothingSent();
});

test('IPAF Revenue Collection with a PENRO receipt is excluded from alerts and remains pending only for Records verification', function () {
    $manager = complianceManager(complianceUser());
    $area = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    Storage::disk('public')->put('ipaf-revenue-movs/test-revenue.pdf', 'test MOV');
    $revenue = \App\Models\IpafRevenueCollection::create([
        'protected_area_id' => $area->id,
        'target_office' => 'Baganga',
        'activity_name' => 'Revenue Collection',
        'document_type' => 'Final Report',
        'reporting_month' => 7,
        'reporting_year' => 2026,
        'total_collected' => '1000.00',
        'deadline_submission' => '2026-07-20',
        'date_report_released_cenro' => '2026-07-18',
        'date_received_penro' => '2026-07-29',
        'mov_file_name' => 'test-revenue.pdf',
        'mov_file_path' => 'ipaf-revenue-movs/test-revenue.pdf',
        'created_by' => $manager->id,
        'updated_by' => $manager->id,
    ]);

    $service = app(OverdueReportService::class);
    $pending = $service->pendingRecordsVerification()->firstWhere('source_id', $revenue->id);
    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan($service->overdueReports());

    expect($service->overdueReports())->toBeEmpty()
        ->and($plan['deliveries'])->toBeEmpty()
        ->and($pending)->not->toBeNull()
        ->and($pending['module'])->toBe('IPAF Revenue Collection Report Submission Tracker')
        ->and($pending['deadline'])->toBe('2026-07-20')
        ->and($pending['submission_date'])->toBe('2026-07-29')
        ->and($pending['submission_status'])->toBe('Pending Regional Endorsement')
        ->and($pending['reporting_period'])->toBe('July 2026')
        ->and($revenue->timeliness)->toBe('Poor');

    app(ComplianceConfirmationService::class)->confirm($revenue, $manager, 'Revenue report received by Records.');

    expect($service->overdueReports())->toBeEmpty()
        ->and($service->pendingRecordsVerification()->firstWhere('source_id', $revenue->id))->toBeNull()
        ->and($service->confirmationHistory()->firstWhere('source_id', $revenue->id)['module'])->toBe('IPAF Revenue Collection Report Submission Tracker')
        ->and($revenue->fresh()->timeliness)->toBe('Poor');
});

test('every registered source follows receipt-based alert eligibility and independent Records verification', function () {
    $manager = complianceManager(complianceUser());
    $area = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'cenrobaganga@denr.gov.ph', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'recipient_email' => 'cenrobaganga@denr.gov.ph', 'is_active' => true]);
    $records = recordsForEveryComplianceSource($area, $manager, '2026-08-24');
    $service = app(OverdueReportService::class);
    $definitions = $service->sourceDefinitions();

    $overdue = $service->overdueReports();
    expect($overdue->map(fn ($report) => $report->sourceType)->sort()->values()->all())->toBe(collect(array_keys($definitions))->sort()->values()->all())
        ->and($overdue->every(fn ($report) => $report->submitted === false))->toBeTrue()
        ->and($overdue->map(fn ($report) => "{$report->sourceType}:{$report->sourceId}")->unique()->count())->toBe(count($definitions));

    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan($overdue);
    expect($plan['deliveries']->sum(fn (array $delivery) => $delivery['reports']->count()))->toBe(count($definitions))
        ->and($plan['deliveries']->every(fn (array $delivery) => $delivery['recipient']->email === 'cenrobaganga@denr.gov.ph'))->toBeTrue();

    foreach ($records as $sourceType => $record) {
        $record->update([$definitions[$sourceType]['submitted'] => '2026-08-25']);
    }
    $pending = $service->pendingRecordsVerification();
    expect($service->overdueReports())->toBeEmpty()
        ->and($pending->map(fn (array $report) => $report['source_type'])->sort()->values()->all())->toBe(collect(array_keys($definitions))->sort()->values()->all())
        ->and($pending->every(fn (array $report) => $report['submission_status'] === 'Pending Submission by CENRO' && $report['records_confirmed'] === false));

    foreach ($records as $record) {
        app(ComplianceConfirmationService::class)->confirm($record, $manager, 'Received by Records.');
    }
    expect($service->overdueReports())->toBeEmpty()
        ->and($service->pendingRecordsVerification())->toBeEmpty()
        ->and($service->confirmationHistory()->pluck('source_type')->sort()->values()->all())->toBe(collect(array_keys($definitions))->sort()->values()->all());

    $firstSource = reset($records);
    app(ComplianceConfirmationService::class)->unconfirm($firstSource, $manager, 'Confirmation was attached to the wrong source document.');
    expect($service->pendingRecordsVerification())->toHaveCount(1)
        ->and($service->pendingRecordsVerification()->first()['source_id'])->toBe($firstSource->id)
        ->and($service->overdueReports())->toBeEmpty();

    recordsForEveryComplianceSource($area, $manager, '2026-09-01');
    expect($service->overdueReports())->toBeEmpty();
});

test('recipient readiness classifies ready and unmapped groups without fallback delivery', function () {
    $user = complianceUser(); $area = complianceArea($user); bmsForDeadline($area, $user, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);
    $delivery = app(ComplianceAlertDeliveryService::class);

    expect($delivery->recipientReadiness(app(OverdueReportService::class)->overdueReports())->first()['status'])->toBe('ready');

    ComplianceAlertRecipient::query()->update(['is_active' => false]); enabledComplianceSettings(['fallback_recipient_email' => 'fallback@example.test']);
    $delivery = app(ComplianceAlertDeliveryService::class);
    expect($delivery->recipientReadiness(app(OverdueReportService::class)->overdueReports())->first()['status'])->toBe('unmapped');

    ComplianceAlertSetting::query()->delete(); config()->set('compliance_alerts.recipients', []); config()->set('compliance_alerts.fallback_recipient_email', '');
    expect($delivery->recipientReadiness(app(OverdueReportService::class)->overdueReports())->first()['status'])->toBe('unmapped');
});

test('boss default office mappings resolve exact recipients and presentation data', function () {
    $user = complianceUser();
    $baganga = ProtectedArea::create(['name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $hamiguitan = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $mati = ProtectedArea::create(['name' => 'Mati Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    bmsForDeadline($baganga, $user, '2026-08-20', ['target_office' => 'Baganga']);
    bmsForDeadline($hamiguitan, $user, '2026-08-20', ['target_office' => 'Hamiguitan']);
    bmsForDeadline($mati, $user, '2026-08-20', ['target_office' => 'Mati']);

    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());
    $byOffice = $plan['deliveries']->keyBy(fn (array $delivery) => $delivery['reports']->first()->targetOffice);

    expect($byOffice['Baganga']['recipient']->email)->toBe('cenrobaganga@denr.gov.ph')
        ->and($byOffice['Baganga']['recipient']->name)->toBe('The Deputy PASu of Baganga')
        ->and($byOffice['Baganga']['recipient']->attentionLine)->toBe('Chief, Conservation and Development Section')
        ->and($byOffice['Hamiguitan']['recipient']->email)->toBe('mthamiguitan@denr.gov.ph')
        ->and($byOffice['Hamiguitan']['recipient']->name)->toBe('The OIC, PASu of Hamiguitan')
        ->and($byOffice['Hamiguitan']['recipient']->attentionLine)->toBe('')
        ->and($byOffice['Mati']['recipient']->email)->toBe('cenromati@denr.gov.ph')
        ->and($byOffice['Mati']['recipient']->name)->toBe('The Deputy PASu of Mati')
        ->and($byOffice['Mati']['recipient']->attentionLine)->toBe('Chief, Conservation and Development Section');

    expect(app(ComplianceAlertDeliveryService::class)->recipientReadiness(app(OverdueReportService::class)->overdueReports())->pluck('status')->unique()->all())->toBe(['ready']);
});

test('boss defaults include exact CC and signatory settings', function () {
    $settings = app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective();

    expect($settings['email_subject'])->toBe("⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports")
        ->and($settings['fallback_cc_emails'])->toBe([
            'penromaticds@gmail.com', 'benemerito.RB@gmail.com', 'nely.maimad11@gmail.com',
            'hingpitelmarie@gmail.com', 'duayelmarie@gmail.com', 'edhingpit01@gmail.com',
        ])
        ->and($settings['signatory_name'])->toBe('PABLITO M. OFRECIA')
        ->and($settings['signatory_position'])->toBe('PENR Officer')
        ->and($settings['office_name'])->toBe('PENRO Mati, Davao Oriental')
        ->and($settings['office_address'])->toBe('PENRO Mati, Davao Oriental')
        ->and($settings['focal_person_name'])->toBe('Richelle A. Benemerito')
        ->and($settings['focal_person_position'])->toBe('EMS I')
        ->and($settings['focal_person_contact'])->toBe('Provincial Protected Area Focal Person of PENRO Mati');
});

test('reapplying boss defaults preserves customized mappings and settings', function () {
    $mapping = ComplianceAlertRecipient::query()->where('target_office', 'Baganga')->firstOrFail();
    $mapping->update(['recipient_email' => 'custom@example.test', 'recipient_name' => 'Custom Recipient']);
    $settings = ComplianceAlertSetting::create(['alerts_enabled' => false, 'automatic_send_enabled' => false, 'send_time' => '08:00', 'timezone' => 'Asia/Manila']);
    $settings->update(['signatory_name' => 'Custom Signatory', 'fallback_cc_emails' => ['custom-cc@example.test']]);

    app(\Database\Seeders\ComplianceAlertBossDefaultsSeeder::class)->run();

    expect($mapping->fresh()->recipient_email)->toBe('custom@example.test')
        ->and($mapping->fresh()->recipient_name)->toBe('Custom Recipient')
        ->and($settings->fresh()->signatory_name)->toBe('Custom Signatory')
        ->and($settings->fresh()->fallback_cc_emails)->toBe(['custom-cc@example.test'])
        ->and(ComplianceAlertRecipient::query()->where('target_office', 'Baganga')->count())->toBe(1);
});

test('delivery failure records a safe history message and does not leak transport details to the browser', function () {
    config()->set('compliance_alerts.enabled', true); config()->set('compliance_alerts.test_email_enabled', true);
    $manager = complianceManager(complianceUser()); $area = complianceArea($manager); bmsForDeadline($area, $manager, '2026-08-24');
    enabledComplianceSettings(['test_recipient_email' => 'controlled-test@example.test']);
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP password=super-secret-value'));

    $this->actingAs($manager)->post(route('compliance-alerts.send-test'))->assertSessionHasErrors([
        'test_email' => 'Test email delivery failed. No successful-send record was created. See notification history for status.',
    ]);

    $run = ComplianceNotificationRun::query()->where('run_type', 'test')->first();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toBe('Email delivery failed. See application logs for technical details.')
        ->and($run->error_message)->not->toContain('super-secret-value');
});

test('the test email config gate is exposed to Inertia and remains independent of production alerts', function () {
    $manager = complianceManager(complianceUser());
    config()->set('compliance_alerts.enabled', false);
    config()->set('compliance_alerts.test_email_enabled', false);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('testEmailEnabled', false));

    config()->set('compliance_alerts.test_email_enabled', true);
    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('testEmailEnabled', true)->where('safeMode', true));
});

test('known settings placeholders are corrected without changing official values', function () {
    $settings = ComplianceAlertSetting::create([
        'email_subject' => 'jayarcaldelero26@gmail.com',
        'to_label' => 'MHRWS',
        'attention_line' => 'Lechoncito',
        'from_line' => 'The PENR Officer',
        'memorandum_subject' => 'Submission of Reports of the Activities Already Conducted',
        'signatory_name' => 'PABLITO M. OFRECIA',
        'signatory_position' => 'PENR Officer',
        'office_name' => 'PENRO Mati, Davao Oriental',
        'office_address' => 'PENRO Mati, Davao Oriental',
        'focal_person_name' => 'Richelle A. Benemerito',
        'focal_person_position' => 'EMS I',
        'focal_person_contact' => 'Provincial Protected Area Focal Person of PENRO Mati',
    ]);

    app(\Database\Seeders\ComplianceAlertBossDefaultsSeeder::class)->run();

    expect($settings->fresh()->email_subject)->toBe("⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports")
        ->and($settings->fresh()->to_label)->toBe('')
        ->and($settings->fresh()->attention_line)->toBe('')
        ->and($settings->fresh()->from_line)->toBe('The PENR Officer')
        ->and($settings->fresh()->signatory_name)->toBe('PABLITO M. OFRECIA');
});

test('test and dry-run history do not count as production sends or failures', function () {
    $manager = complianceManager(complianceUser());
    ComplianceNotificationRun::create([
        'run_date' => now('Asia/Manila')->toDateString(), 'recipient_key' => 'test', 'run_type' => ComplianceNotificationRun::TYPE_TEST,
        'subject' => 'Test', 'status' => ComplianceNotificationRun::STATUS_SENT, 'report_count' => 1, 'recipients' => ['test@example.test'],
    ]);
    ComplianceNotificationRun::create([
        'run_date' => now('Asia/Manila')->toDateString(), 'recipient_key' => 'dry-run', 'run_type' => ComplianceNotificationRun::TYPE_DRY_RUN,
        'subject' => 'Dry run', 'status' => ComplianceNotificationRun::STATUS_FAILED, 'report_count' => 1, 'recipients' => ['dry-run@example.test'],
    ]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.sent_today', 0)
            ->where('summary.failed_today', 0)
            ->has('runs', 2));
});

test('production history counts toward sent and failed overview counters', function () {
    $manager = complianceManager(complianceUser());
    ComplianceNotificationRun::create([
        'run_date' => now('Asia/Manila')->toDateString(), 'recipient_key' => 'office', 'run_type' => ComplianceNotificationRun::TYPE_AUTOMATIC,
        'subject' => 'Production', 'status' => ComplianceNotificationRun::STATUS_SENT, 'report_count' => 1, 'recipients' => ['office@example.test'],
    ]);
    ComplianceNotificationRun::create([
        'run_date' => now('Asia/Manila')->toDateString(), 'recipient_key' => 'office', 'run_type' => ComplianceNotificationRun::TYPE_MANUAL,
        'subject' => 'Production retry', 'status' => ComplianceNotificationRun::STATUS_FAILED, 'report_count' => 1, 'recipients' => ['office@example.test'],
    ]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.sent_today', 1)
            ->where('summary.failed_today', 1));
});

test('production preview is unavailable when there are no current overdue reports', function () {
    $manager = complianceManager(complianceUser());

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.overdue_reports', 0)
            ->where('deliveryPlan.deliveries', []));

    Mail::fake();
    $this->actingAs($manager)->get(route('compliance-alerts.preview'))
        ->assertRedirect(route('compliance-alerts.index'))
        ->assertSessionHasErrors(['preview' => 'No current overdue reports to preview.']);
    Mail::assertNothingSent();
});

test('production preview is unavailable when overdue reports have no mapped recipient', function () {
    config()->set('compliance_alerts.recipients', []);
    config()->set('compliance_alerts.fallback_recipient_email', '');
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    enabledComplianceSettings(['fallback_recipient_email' => null]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.overdue_reports', 1)
            ->where('deliveryPlan.deliveries', []));

    Mail::fake();
    $this->actingAs($manager)->get(route('compliance-alerts.preview'))
        ->assertRedirect(route('compliance-alerts.index'))
        ->assertSessionHasErrors(['preview' => 'No mapped recipient is available for the current overdue reports.']);
    Mail::assertNothingSent();
});

test('live test preview returns the canonical JSON contract without sending mail', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    enabledComplianceSettings(['test_recipient_email' => 'controlled-test@example.test']);

    $response = $this->actingAs($manager)->get(route('compliance-alerts.preview', ['test' => 1]), [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
    $response->assertJsonStructure(['subject', 'html', 'template_type', 'recipient', 'meta'])
        ->assertJsonPath('subject', "\u{26A0} PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports")
        ->assertJsonPath('template_type', 'protected_area_overdue')
        ->assertJsonPath('recipient.email', 'controlled-test@example.test')
        ->assertJsonPath('meta.report_count', 1);

    $html = (string) $response->json('html');
    expect($html)->toContain('MEMORANDUM')->toContain($area->name)->toContain('Days Overdue');
    Mail::assertNothingSent();
});

test('template preview rejects an invalid variant with a JSON validation response', function () {
    $manager = complianceManager(complianceUser());

    $response = $this->actingAs($manager)->postJson(route('compliance-alerts.templates.preview'), ['template' => 'invalid_variant']);

    expect($response->status())->toBe(422);
    expect($response->json('errors.template'))->not->toBeEmpty();
});

test('production preview works with a current overdue report and resolvable recipient', function () {
    Mail::fake();
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa@example.test', 'is_active' => true]);

    $this->actingAs($manager)->get(route('compliance-alerts.preview'))
        ->assertOk()
        ->assertSee('MEMORANDUM');
    Mail::assertNothingSent();
});

test('preview reports no eligible overdue records when production preview has no reports', function () {
    $manager = complianceManager(complianceUser());
    enabledComplianceSettings(['test_recipient_email' => 'controlled-test@example.test']);

    Mail::fake();
    $this->actingAs($manager)->get(route('compliance-alerts.preview', ['test' => 1]))
        ->assertRedirect()
        ->assertSessionHasErrors(['preview' => 'No eligible overdue reports found for preview.']);
    Mail::assertNothingSent();
});

test('repeated identical manual delivery sends each destination only once', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create([
        'protected_area_id' => $area->id,
        'recipient_email' => 'manual-idempotent@example.test',
        'is_active' => true,
    ]);
    enabledComplianceSettings();
    $delivery = app(ComplianceAlertDeliveryService::class);

    $first = $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $manager);
    $second = $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $manager);

    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    expect($first->pluck('status')->all())->toBe([ComplianceNotificationRun::STATUS_SENT])
        ->and($second->pluck('status')->all())->toBe([ComplianceNotificationRun::STATUS_SKIPPED])
        ->and(ComplianceDeliveryClaim::query()->count())->toBe(1)
        ->and(ComplianceDeliveryClaim::query()->first()->status)->toBe(ComplianceDeliveryClaim::STATUS_SENT)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'skipped')->count())->toBe(1);
});

test('repeated production Send Now HTTP requests remain guarded during the preview phase', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'http-idempotent@example.test', 'is_active' => true]);
    enabledComplianceSettings();

    $this->actingAs($manager)->post(route('compliance-alerts.send'))->assertRedirect()->assertSessionHasErrors(['delivery' => 'Production compliance delivery is disabled during the memorandum preview phase. Use Preview Notification or Send Test Email.']);
    $this->actingAs($manager)->post(route('compliance-alerts.send'))
        ->assertRedirect()
        ->assertSessionHasErrors(['delivery' => 'Production compliance delivery is disabled during the memorandum preview phase. Use Preview Notification or Send Test Email.']);

    Mail::assertNothingSent();
    expect(ComplianceNotificationRun::query()->where('run_type', 'manual')->count())->toBe(0);
});

test('manual partial failure retries only the failed destination and preserves successful history', function () {
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $firstArea = complianceArea($manager);
    $secondArea = ProtectedArea::create([
        'name' => 'Retry Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Boston',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    bmsForDeadline($firstArea, $manager, '2026-08-24', ['target_office' => 'First Retry Office']);
    bmsForDeadline($secondArea, $manager, '2026-08-24', ['target_office' => 'Second Retry Office']);
    ComplianceAlertRecipient::create(['protected_area_id' => $firstArea->id, 'recipient_email' => 'success@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['protected_area_id' => $secondArea->id, 'recipient_email' => 'retry@example.test', 'is_active' => true]);
    enabledComplianceSettings();
    $mailManager = app('mail.manager');
    Mail::shouldReceive('to')->twice()->andReturnUsing(fn (string $email) => new class($email)
    {
        public function __construct(private readonly string $email) {}
        public function send(mixed $mailable): void
        {
            if ($this->email === 'retry@example.test') {
                throw new RuntimeException('Simulated destination failure');
            }
        }
    });

    $delivery = app(ComplianceAlertDeliveryService::class);
    $firstAttempt = $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $manager);
    expect($firstAttempt->where('status', 'sent'))->toHaveCount(1)
        ->and($firstAttempt->where('status', 'failed'))->toHaveCount(1);

    app()->instance('mail.manager', $mailManager);
    Mail::swap($mailManager);
    Mail::fake();
    $retry = $delivery->sendManual(app(OverdueReportService::class)->overdueReports(), $manager);

    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    expect($retry->where('status', 'sent'))->toHaveCount(1)
        ->and($retry->where('status', 'skipped'))->toHaveCount(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'sent')->count())->toBe(2)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'failed')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'manual')->where('status', 'skipped')->count())->toBe(1)
        ->and(ComplianceDeliveryClaim::query()->where('status', ComplianceDeliveryClaim::STATUS_SENT)->count())->toBe(2)
        ->and(ComplianceDeliveryClaim::query()->where('attempts', 2)->count())->toBe(1);
});

test('a competing automatic attempt cannot pass an in-progress atomic claim', function () {
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceUser();
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'atomic@example.test', 'is_active' => true]);
    enabledComplianceSettings();
    $delivery = app(ComplianceAlertDeliveryService::class);
    Mail::shouldReceive('to')->once()->with('atomic@example.test')->andReturn(new class($delivery)
    {
        public function __construct(private readonly ComplianceAlertDeliveryService $delivery) {}
        public function send(mixed $mailable): void
        {
            $this->delivery->sendAutomatic();
        }
    });

    $delivery->sendAutomatic();

    expect(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'sent')->count())->toBe(1)
        ->and(ComplianceNotificationRun::query()->where('run_type', 'automatic')->where('status', 'skipped')->count())->toBe(1)
        ->and(ComplianceDeliveryClaim::query()->count())->toBe(1)
        ->and(ComplianceDeliveryClaim::query()->first()->status)->toBe(ComplianceDeliveryClaim::STATUS_SENT);
});

test('Records confirmation and revocation are append-only immutable snapshot events', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    $service = app(ComplianceConfirmationService::class);

    $confirmation = $service->confirm($report, $manager, 'Original Records evidence.');
    expect(fn () => $service->confirm($report, $manager, 'Attempted overwrite.'))
        ->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(ReportComplianceConfirmation::query()->count())->toBe(1);

    $report->update(['activity_name' => 'Changed after confirmation', 'target_office' => 'Changed Office']);
    $area->update(['name' => 'Changed Protected Area']);
    $historyBeforeRevocation = app(OverdueReportService::class)->confirmationHistory()->first();
    expect($historyBeforeRevocation['activity'])->not->toBe('Changed after confirmation')
        ->and($historyBeforeRevocation['target_office'])->not->toBe('Changed Office')
        ->and($historyBeforeRevocation['protected_area_name'])->not->toBe('Changed Protected Area');

    $revocation = $service->unconfirm($report->fresh(), $manager, 'Confirmation was entered against the wrong evidence.');
    expect($revocation->event_type)->toBe(ReportComplianceConfirmation::EVENT_REVOKED)
        ->and($revocation->original_confirmation_id)->toBe($confirmation->id)
        ->and(ReportComplianceConfirmation::query()->count())->toBe(2)
        ->and($confirmation->fresh()->remarks)->toBe('Original Records evidence.')
        ->and(app(OverdueReportService::class)->pendingRecordsVerification()->firstWhere('source_id', $report->id))->not->toBeNull()
        ->and(app(OverdueReportService::class)->overdueReports())->toBeEmpty();

    $events = app(OverdueReportService::class)->confirmationHistory();
    expect($events->pluck('event_type')->all())->toBe(['revoked', 'confirmed'])
        ->and($events->first()['revocation_reason'])->toBe('Confirmation was entered against the wrong evidence.');

    $reconfirmation = $service->confirm($report->fresh(), $manager, 'New confirmation after documented revocation.');
    expect($reconfirmation->id)->not->toBe($confirmation->id)
        ->and(ReportComplianceConfirmation::query()->count())->toBe(3)
        ->and($confirmation->fresh()->remarks)->toBe('Original Records evidence.');
});

test('authorized Records revocation returns the item to pending while preserving both lifecycle events', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-24', ['date_received_penro' => '2026-08-25']);
    app(ComplianceConfirmationService::class)->confirm($report, $manager, 'Stamped.');

    $this->actingAs($manager)->delete(route('compliance-alerts.unconfirm'), [
        'source_type' => BmsReportSubmission::class,
        'source_id' => $report->id,
        'reason' => 'Stamped copy was matched to the wrong report.',
    ])->assertRedirect()->assertSessionHas('success');

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingRecordsVerification', 1)
            ->has('confirmationHistory', 2)
            ->where('confirmationHistory.0.event_type', 'revoked')
            ->where('confirmationHistory.0.revocation_reason', 'Stamped copy was matched to the wrong report.')
            ->where('summary.overdue_reports', 0));
});

test('database constraints enforce snapshot uniqueness active recipient scope and settings singleton', function () {
    $user = complianceUser();
    $run = ComplianceNotificationRun::create([
        'run_date' => '2026-08-25', 'recipient_key' => 'constraint', 'recipients' => ['constraint@example.test'],
        'subject' => 'Constraint', 'report_count' => 1, 'status' => 'sent', 'run_type' => 'manual', 'is_manual' => true,
    ]);
    $run->reports()->create(['source_type' => BmsReportSubmission::class, 'source_id' => 99, 'snapshot' => ['activity' => 'Original']]);
    expect(fn () => $run->reports()->create(['source_type' => BmsReportSubmission::class, 'source_id' => 99, 'snapshot' => ['activity' => 'Duplicate']]))
        ->toThrow(QueryException::class);

    $area = complianceArea($user);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'first-scope@example.test', 'is_active' => true]);
    expect(fn () => ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'duplicate-scope@example.test', 'is_active' => true]))
        ->toThrow(QueryException::class);

    enabledComplianceSettings();
    expect(fn () => ComplianceAlertSetting::create(['alerts_enabled' => false, 'automatic_send_enabled' => false, 'send_time' => '08:00', 'timezone' => 'Asia/Manila']))
        ->toThrow(QueryException::class);

    $indexes = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name');
    expect($indexes('compliance_notification_run_reports'))->toContain('compliance_notification_run_reports_unique')
        ->and($indexes('compliance_delivery_claims'))->toContain('compliance_delivery_claims_key_unique')
        ->and($indexes('compliance_alert_recipients'))->toContain('compliance_alert_recipients_active_scope_unique')
        ->and($indexes('compliance_alert_settings'))->toContain('compliance_alert_settings_singleton_unique');
});

test('office recipient creation stores the canonical target office key', function () {
    $manager = complianceManager(complianceUser());

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'CENRO Baganga',
        'recipient_email' => 'baganga-create@example.test',
        'is_active' => true,
    ])->assertRedirect();

    $mapping = ComplianceAlertRecipient::query()->where('recipient_email', 'baganga-create@example.test')->firstOrFail();
    expect($mapping->target_office)->toBe('CENRO Baganga')
        ->and($mapping->target_office_key)->toBe('cenro_baganga');
});

test('alternate office spelling stores the same canonical target office key', function () {
    $manager = complianceManager(complianceUser());

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'CENRO Baganga', 'recipient_email' => 'baganga-one@example.test', 'is_active' => false,
    ])->assertRedirect();
    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'Baganga CENRO', 'recipient_email' => 'baganga-two@example.test', 'is_active' => false,
    ])->assertRedirect();

    expect(ComplianceAlertRecipient::query()->whereIn('recipient_email', ['baganga-one@example.test', 'baganga-two@example.test'])->pluck('target_office_key')->all())
        ->toBe(['cenro_baganga', 'cenro_baganga']);
});

test('canonical-equivalent active office mappings return validation errors', function () {
    $manager = complianceManager(complianceUser());

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'CENRO Baganga', 'recipient_email' => 'baganga-active@example.test', 'is_active' => true,
    ])->assertRedirect();

    $response = $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'Baganga CENRO', 'recipient_email' => 'baganga-duplicate@example.test', 'is_active' => true,
    ]);

    $response->assertRedirect()->assertSessionHasErrors('target_office');
    expect(ComplianceAlertRecipient::query()->where('is_active', true)->where('target_office_key', 'cenro_baganga')->count())->toBe(1);
});

test('inactive office mappings reactivate with a canonical key and no 500', function () {
    $manager = complianceManager(complianceUser());
    $mapping = ComplianceAlertRecipient::create([
        'target_office' => 'Baganga CENRO', 'target_office_key' => null,
        'recipient_email' => 'baganga-reactivate@example.test', 'is_active' => false,
    ]);

    $this->actingAs($manager)
        ->patch(route('compliance-alerts.recipients.status', $mapping), ['is_active' => true])
        ->assertStatus(302)
        ->assertSessionDoesntHaveErrors();

    expect($mapping->fresh()->is_active)->toBeTrue()
        ->and($mapping->fresh()->target_office)->toBe('CENRO Baganga')
        ->and($mapping->fresh()->target_office_key)->toBe('cenro_baganga');
});

test('reactivation of a canonical-equivalent active office mapping returns validation errors', function () {
    $manager = complianceManager(complianceUser());
    ComplianceAlertRecipient::create([
        'target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga',
        'recipient_email' => 'baganga-existing@example.test', 'is_active' => true,
    ]);
    $mapping = ComplianceAlertRecipient::create([
        'target_office' => 'Baganga CENRO', 'target_office_key' => null,
        'recipient_email' => 'baganga-inactive@example.test', 'is_active' => false,
    ]);

    $this->actingAs($manager)
        ->patch(route('compliance-alerts.recipients.status', $mapping), ['is_active' => true])
        ->assertRedirect()
        ->assertSessionHasErrors('target_office');

    expect($mapping->fresh()->is_active)->toBeFalse();
});

test('office recipient edits recanonicalize the target office key', function () {
    $manager = complianceManager(complianceUser());
    $mapping = ComplianceAlertRecipient::create([
        'target_office' => 'CENRO Baganga', 'target_office_key' => null,
        'recipient_email' => 'baganga-edit@example.test', 'is_active' => false,
    ]);

    $this->actingAs($manager)
        ->put(route('compliance-alerts.recipients.update', $mapping), [
            'target_office' => 'Baganga CENRO',
            'recipient_email' => 'baganga-edit@example.test',
            'is_active' => false,
        ])
        ->assertRedirect();

    expect($mapping->fresh()->target_office)->toBe('CENRO Baganga')
        ->and($mapping->fresh()->target_office_key)->toBe('cenro_baganga');
});
test('office readiness uses the same canonical key as the resolver and frontend group', function () {
    $manager = complianceManager(complianceUser());
    engpForDeadline($manager, '2026-08-24', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create([
        'target_office' => 'Baganga CENRO', 'target_office_key' => 'cenro_baganga',
        'recipient_email' => 'office-readiness@example.test', 'is_active' => true,
    ]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.readiness_key', 'office:cenro_baganga')
            ->where('recipientReadiness.0.key', 'office:cenro_baganga')
            ->where('recipientReadiness.0.status', 'ready'));
});
test('Compliance Alerts readiness groups expose the resolver logical key used by the UI', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'readiness@example.test', 'is_active' => true]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.readiness_key', 'pa:'.$area->id)
            ->where('recipientReadiness.0.key', 'pa:'.$area->id)
            ->where('recipientReadiness.0.status', 'ready'));
});
test('recipient validation returns field errors and malformed status cannot deactivate a mapping', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'recipient_email' => 'valid@example.test', 'is_active' => true,
    ])->assertSessionHasErrors('target_office');
    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'Validation Office', 'recipient_email' => 'valid@example.test',
        'cc_emails' => 'valid@example.test,not-an-email', 'is_active' => true,
    ])->assertSessionHasErrors('cc_emails');

    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'one-validation@example.test', 'is_active' => true]);
    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'protected_area_id' => $area->id, 'recipient_email' => 'two-validation@example.test', 'is_active' => true,
    ])->assertSessionHasErrors('protected_area_id');

    $mapping = ComplianceAlertRecipient::create(['target_office' => 'Boolean Office', 'recipient_email' => 'boolean@example.test', 'is_active' => true]);
    $this->actingAs($manager)->patch(route('compliance-alerts.recipients.status', $mapping), [])->assertSessionHasErrors('is_active');
    $this->actingAs($manager)->patch(route('compliance-alerts.recipients.status', $mapping), ['is_active' => 'not-a-boolean'])->assertSessionHasErrors('is_active');
    expect($mapping->fresh()->is_active)->toBeTrue();
});

test('Compliance Alerts timezone is fixed to Asia Manila across settings and delivery business dates', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'timezone@example.test', 'is_active' => true]);
    $settings = enabledComplianceSettings(['timezone' => 'UTC']);

    expect(app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective()['timezone'])->toBe('Asia/Manila');
    app(ComplianceAlertDeliveryService::class)->sendAutomatic();
    expect(ComplianceDeliveryClaim::query()->first()->business_date->toDateString())->toBe('2026-08-25');

    $payload = app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective();
    $payload['timezone'] = 'UTC';
    $this->actingAs($manager)->put(route('compliance-alerts.settings.update'), $payload)->assertSessionHasErrors('timezone');
    expect($settings->fresh()->timezone)->toBe('Asia/Manila')
        ->and(app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective()['timezone'])->toBe('Asia/Manila');
});

test('Overview exposes only active overdue sources while Settings retains full workflow coverage', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $first = bmsForDeadline($area, $manager, '2026-08-24');
    $second = bmsForDeadline($area, $manager, '2026-08-23');

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activeOverdueSources', 1)
            ->where('activeOverdueSources.0.module', 'BMS Report Submission Tracker')
            ->where('activeOverdueSources.0.overdue_count', 2)
            ->has('monitoredSources', 10));

    $first->update(['date_received_penro' => '2026-08-25']);
    $second->update(['date_received_penro' => '2026-08-25']);
    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('activeOverdueSources', [])->has('monitoredSources', 10));
});

test('dormant fallback settings remain stored but never resolve a delivery', function () {
    $manager = complianceManager(complianceUser());
    enabledComplianceSettings([
        'fallback_recipient_email' => 'fallback@example.test',
        'fallback_cc_emails' => ['administrator-custom@example.test'],
    ]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.unmapped_recipients', 0));
    expect(ComplianceAlertSetting::query()->first()->fallback_cc_emails)->toBe(['administrator-custom@example.test']);
});

test('the default memorandum footer matches receipt closure and separates Records verification', function () {
    $footer = app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective()['system_generated_footer_text'];

    expect($footer)->toBe('This is a system-generated notification sent automatically by the Enhanced Digital Alert and Tracking System (eDATS). Notifications for a report will cease once the submission is recorded as compliant in eDATS.');
});

test('all monitored sources expose the universal MOV contract and distinguish submitted MOV not yet submitted', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $records = recordsForEveryComplianceSource($area, $manager, '2026-08-24');
    $service = app(OverdueReportService::class);
    $definitions = $service->sourceDefinitions();

    expect($definitions)->toHaveCount(10);
    foreach ($definitions as $sourceType => $definition) {
        expect($definition)->toHaveKeys(['mov', 'mov_label'])
            ->and($records[$sourceType])->not->toBeNull();
    }

    foreach ($records as $sourceType => $record) {
        $record->update([$definitions[$sourceType]['submitted'] => '2026-08-25']);
        if ($record instanceof \App\Models\ManagementPlan) {
            $record->update(['attachments' => null]);
        } elseif ($record instanceof \App\Models\Aws) {
            $record->update(['report_file_path' => null]);
        } else {
            $record->update(['mov_file_path' => null]);
        }
    }

    $overdue = $service->overdueReports();
    expect($overdue)->toHaveCount(9)
        ->and($overdue->every(fn ($report) => $report->sourceType !== EngpReportSubmission::class && $report->submitted && $report->complianceIssue === 'MOV Not Yet Submitted'))->toBeTrue()
        ->and($overdue->map(fn ($report) => "{$report->sourceType}:{$report->sourceId}")->unique())->toHaveCount(9)
        ->and($overdue->pluck('sourceType')->all())->not->toContain(EngpReportSubmission::class);
});

test('submitted MOV not yet submitted is pending before deadline, overdue after deadline, and unaffected by Records confirmation', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    $report = bmsForDeadline($area, $manager, '2026-08-27', [
        'date_received_penro' => '2026-08-26',
        'mov_file_path' => null,
        'mov_file_name' => null,
    ]);
    $service = app(OverdueReportService::class);

    expect($service->overdueReports())->toBeEmpty()
        ->and($service->pendingMovReports()->first()->complianceIssue)->toBe('MOV Not Yet Submitted');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-28 10:00:00', 'Asia/Manila'));
    expect($service->overdueReports())->toHaveCount(1)
        ->and($service->overdueReports()->first()->complianceIssue)->toBe('MOV Not Yet Submitted')
        ->and($service->overdueReports()->first()->submitted)->toBeTrue();

    app(ComplianceConfirmationService::class)->confirm($report, $manager, 'Received by Records.');
    expect($service->overdueReports())->toHaveCount(1)
        ->and($service->overdueReports()->first()->complianceIssue)->toBe('MOV Not Yet Submitted')
        ->and($service->confirmationHistory()->first()['source_id'])->toBe($report->id);
});

test('stale MOV database paths are normalized as MOV not yet submitted', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24', [
        'date_received_penro' => '2026-08-25',
        'mov_file_path' => 'bms-report-movs/missing-from-disk.pdf',
        'mov_file_name' => 'missing-from-disk.pdf',
    ]);

    $report = app(OverdueReportService::class)->overdueReports()->first();
    expect($report)->not->toBeNull()
        ->and($report->movRequired)->toBeTrue()
        ->and($report->movPresent)->toBeFalse()
        ->and($report->complianceIssue)->toBe('MOV Not Yet Submitted');
});

test('report workflows require an attachment at report data entry while routing dates stay separate', function () {
    $creator = complianceUser();
    $role = Role::findOrCreate('Universal MOV Creator', 'web');
    $permissions = ['bms.create', 'bams.create', 'imea.create', 'aws.create', 'management-plans.create', 'technical-reports.create'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $role->syncPermissions($permissions);
    $creator->assignRole($role);
    $area = complianceArea($creator);
    $type = \App\Models\ManagementPlanType::create(['name' => 'MOV Test Plan', 'slug' => 'mov-test-plan', 'created_by' => $creator->id, 'updated_by' => $creator->id]);
    $common = [
        'protected_area_id' => $area->id, 'target_office' => 'Baganga', 'activity_name' => 'MOV test activity',
        'document_type' => 'Final Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-08-01',
    ];
    $cases = [
        ['route' => 'bms.report-submissions.store', 'field' => 'mov', 'payload' => [...$common]],
        ['route' => 'bams.report-submissions.store', 'field' => 'mov', 'payload' => [...$common]],
        ['route' => 'imea.report-submissions.store', 'field' => 'mov', 'payload' => [...$common]],

        ['route' => 'aws.store', 'field' => 'report_file', 'payload' => [...$common, 'station_name' => 'Baganga AWS', 'location' => 'Baganga', 'report_period_type' => 'Monthly', 'start_date' => '2026-08-01', 'end_date' => '2026-08-01', 'status' => 'Active']],
        ['route' => 'management-plans.types.reports.store', 'field' => 'attachments', 'payload' => [...$common]],
        ['route' => 'imea.maintenance-reports.store', 'field' => 'mov', 'payload' => [...$common, 'quarter' => 'Quarter 1']],
        ['route' => 'ipaf.management.store', 'field' => 'mov', 'payload' => [...$common]],
        ['route' => 'ipaf.revenue.store', 'field' => 'mov', 'payload' => [...$common, 'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '1000.00', 'deadline_submission' => '2026-08-20']],
    ];

    foreach ($cases as $case) {
        $routeParameters = $case['route'] === 'management-plans.types.reports.store' ? [$type->slug] : [];
        $initialResponse = $this->actingAs($creator)->post(route($case['route'], $routeParameters), $case['payload']);
        $initialResponse->assertSessionHasErrors($case['field']);

        $file = UploadedFile::fake()->create('supporting-document.pdf', 10, 'application/pdf');
        $payload = [...$case['payload'], $case['field'] => $case['field'] === 'attachments' ? [$file] : $file];
        $response = $this->actingAs($creator)->post(route($case['route'], $routeParameters), $payload);
        expect($response->status())->toBeIn([302, 303]);
    }

    expect(BmsReportSubmission::query()->count())->toBe(1)
        ->and(\App\Models\BamsReportSubmission::query()->count())->toBe(1)
        ->and(\App\Models\ImeaReportSubmission::query()->count())->toBe(1)
        ->and(\App\Models\Aws::query()->count())->toBe(1)
        ->and(\App\Models\ManagementPlan::query()->count())->toBe(1)
        ->and(\App\Models\ImeaFacilityMaintenanceReport::query()->count())->toBe(1)
        ->and(\App\Models\IpafManagementReport::query()->count())->toBe(1)
        ->and(\App\Models\IpafRevenueCollection::query()->count())->toBe(1);
});

test('Protected Area and ENGP recipient mapping scopes resolve separately without requiring a Protected Area for ENGP', function () {
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-24');
    engpForDeadline($manager, '2026-08-24', ['office' => 'CENRO Baganga']);

    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'protected_area_id' => $area->id,
        'recipient_email' => 'pa-scope@example.test',
        'is_active' => true,
    ])->assertRedirect();
    $this->actingAs($manager)->post(route('compliance-alerts.recipients.store'), [
        'target_office' => 'Baganga CENRO',
        'recipient_email' => 'engp-scope@example.test',
        'is_active' => true,
    ])->assertRedirect();

    $officeMapping = ComplianceAlertRecipient::query()->where('recipient_email', 'engp-scope@example.test')->firstOrFail();
    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());
    $coverage = app(ComplianceAlertDeliveryService::class)->destinationCoverageSummary(app(OverdueReportService::class)->destinationReferences())['coverage'];

    expect($officeMapping->protected_area_id)->toBeNull()
        ->and($officeMapping->target_office_key)->toBe('cenro_baganga')
        ->and($plan['deliveries']->pluck('recipient.email')->all())->toContain('pa-scope@example.test', 'engp-scope@example.test')
        ->and($coverage->firstWhere('target_office', 'CENRO Baganga'))->toMatchArray(['scope' => 'target_office', 'type' => 'Implementing / Target Office'])
        ->and($coverage->firstWhere('protected_area_id', $area->id))->toMatchArray(['scope' => 'protected_area', 'type' => 'Protected Area']);
});

test('one canonical ENGP office mapping serves multiple office-scoped ENGP workflows', function () {
    $manager = complianceManager(complianceUser());
    engpForDeadline($manager, '2026-08-24', ['workflow_key' => 'site_visit', 'period_key' => 'MULTI-A', 'office' => 'CENRO Baganga']);
    engpForDeadline($manager, '2026-08-24', ['workflow_key' => 'cbep', 'period_key' => 'MULTI-B', 'office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'shared-engp@example.test', 'is_active' => true]);

    $plan = app(ComplianceAlertDeliveryService::class)->deliveryPlan(app(OverdueReportService::class)->overdueReports());

    expect($plan['deliveries'])->toHaveCount(1)
        ->and($plan['deliveries']->first()['reports'])->toHaveCount(2)
        ->and($plan['deliveries']->first()['recipient']->email)->toBe('shared-engp@example.test');
});

test('PA and ENGP due-soon memoranda use their own approved destination presentation and real candidate values', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-28 10:00:00', 'Asia/Manila'));
    $manager = complianceUser();
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-28');
    engpForDeadline($manager, '2026-08-31', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa-reminder@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'engp-reminder@example.test', 'is_active' => true]);
    enabledComplianceSettings();

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail) use ($area): bool {
        $html = $mail->render();
        return $mail->presentation['template'] === 'protected_area_due_soon'
            && $mail->envelope()->subject === 'REMINDER: Upcoming Deadline for Submission of Protected Area Management Report'
            && str_contains($html, 'Protected Area:</strong>')
            && str_contains($html, $area->name)
            && ! str_contains($html, 'Implementing Office:')
            && ! str_contains($html, 'Baganga MSFR');
    });
    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail): bool {
        $html = $mail->render();
        return $mail->presentation['template'] === 'engp_due_soon'
            && $mail->envelope()->subject === 'REMINDER: Upcoming Deadline for Submission of ENGP Report'
            && str_contains($html, 'Implementing Office:</strong> CENRO Baganga')
            && ! str_contains($html, 'Protected Area:</strong>')
            && ! str_contains($html, 'Baganga MSFR');
    });
});

test('PA and ENGP overdue memoranda use their own approved subject and memorandum wording', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Manila'));
    $manager = complianceUser();
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-20');
    engpForDeadline($manager, '2026-08-31', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa-overdue@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'engp-overdue@example.test', 'is_active' => true]);
    enabledComplianceSettings();

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail) use ($area): bool {
        $html = $mail->render();
        return $mail->presentation['template'] === 'protected_area_overdue'
            && $mail->envelope()->subject === "\u{26A0} PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports"
            && str_contains($html, 'This is to respectfully remind your office that the deadline for the submission of the Protected Area Management-related report has already lapsed. We reiterate the importance of submitting your report to the PENRO as soon as possible.')
            && str_contains($html, $area->name);
    });
    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail): bool {
        $html = $mail->render();
        return $mail->presentation['template'] === 'engp_overdue'
            && $mail->envelope()->subject === 'IMMEDIATE ACTION REQUIRED: Submission of Regular ENGP Monitoring and Accomplishment Reports'
            && str_contains($html, 'The deadline for submission of the Regular ENGP Monitoring and Accomplishment/Progress Reports has already lapsed.')
            && str_contains($html, 'Implementing Office: CENRO Baganga')
            && ! str_contains($html, 'Protected Area Management-related');
    });
});

test('Recipient Mapping UI keeps Protected Area and Development ENGP office configuration separate', function () {
    $jsx = file_get_contents(resource_path('js/Pages/ComplianceAlerts/Index.jsx'));

    expect($jsx)->toContain('Protected Area Recipient Mappings')
        ->toContain('Development / ENGP Office Recipient Mappings')
        ->toContain('No Protected Area is required.')
        ->toContain("recipientScope === 'protected_area'");
});
test('Compliance Alerts exposes PA and ENGP candidates in separate operational scopes without changing destination readiness', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Manila'));
    $manager = complianceManager(complianceUser());
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-28');
    engpForDeadline($manager, '2026-08-31', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'scope-pa@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'scope-engp@example.test', 'is_active' => true]);

    $this->actingAs($manager)->get(route('compliance-alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('complianceScopes.protected_area.summary.overdue_reports', 1)
            ->where('complianceScopes.engp.summary.overdue_reports', 1)
            ->where('complianceScopes.protected_area.groups.0.protected_area_name', $area->name)
            ->where('complianceScopes.engp.groups.0.target_office', 'CENRO Baganga')
            ->where('complianceScopes.protected_area.summary.unmapped_destinations', 0)
            ->where('complianceScopes.engp.summary.unmapped_destinations', 0)
            ->where('complianceScopes.protected_area.summary.affected_groups', 1)
            ->where('complianceScopes.engp.summary.affected_groups', 1));
});

test('template preview is admin-authorized, POST-only, and renders the submitted PA customization through the delivery Blade view', function () {
    $manager = complianceManager(complianceUser());
    $viewer = complianceUser();
    $draft = ['protected_area_due_soon' => ['subject' => 'Custom PA Preview Subject', 'introductory_text' => 'Custom PA preview introduction.']];

    $this->actingAs($viewer)->post(route('compliance-alerts.templates.preview'), ['template' => 'protected_area_due_soon'])
        ->assertForbidden();
    $this->actingAs($manager)->get(route('compliance-alerts.templates.preview'))
        ->assertStatus(405);
    $this->actingAs($manager)->post(route('compliance-alerts.templates.preview'), [
        'template' => 'protected_area_due_soon', 'template_settings' => $draft,
    ])->assertOk()
        ->assertJsonPath('subject', 'Custom PA Preview Subject')
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Custom PA preview introduction.') && str_contains($html, 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)'));
});

test('saved PA and ENGP template customizations persist and are isolated in actual delivery', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Manila'));
    $manager = complianceUser();
    $area = complianceArea($manager);
    bmsForDeadline($area, $manager, '2026-08-20');
    engpForDeadline($manager, '2026-08-31', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'persist-pa@example.test', 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'persist-engp@example.test', 'is_active' => true]);
    $settings = enabledComplianceSettings(['template_settings' => [
        'protected_area_overdue' => ['subject' => 'Custom PA Overdue Subject'],
        'engp_overdue' => ['subject' => 'Custom ENGP Overdue Subject'],
    ]]);

    expect($settings->fresh()->template_settings['protected_area_overdue']['subject'])->toBe('Custom PA Overdue Subject')
        ->and($settings->fresh()->template_settings['engp_overdue']['subject'])->toBe('Custom ENGP Overdue Subject');

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, fn (OverdueComplianceMemorandum $mail): bool => $mail->presentation['template'] === 'protected_area_overdue' && $mail->envelope()->subject === "⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports" && str_contains($mail->render(), $area->name));
    Mail::assertSent(OverdueComplianceMemorandum::class, fn (OverdueComplianceMemorandum $mail): bool => $mail->presentation['template'] === 'engp_overdue' && $mail->envelope()->subject === 'Custom ENGP Overdue Subject' && str_contains($mail->render(), 'Implementing Office: CENRO Baganga'));
});

test('template editor UI retains four independent PA and ENGP template contexts', function () {
    $jsx = file_get_contents(resource_path('js/Pages/ComplianceAlerts/Index.jsx'));

    expect($jsx)->toContain('Protected Area Templates')
        ->toContain('ENGP Templates')
        ->toContain('protected_area_due_soon')
        ->toContain('protected_area_overdue')
        ->toContain('engp_due_soon')
        ->toContain('engp_overdue')
        ->toContain('Preview Notification');
});
test('PA and ENGP compliance mailables use the configured Laravel From identity while recipient mappings stay independent', function () {
    Mail::fake();
    config()->set('compliance_alerts.enabled', true);
    config()->set('mail.from.address', 'configured-sender@example.test');
    config()->set('mail.from.name', 'Configured eDATS Sender');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Manila'));
    $user = complianceUser();
    $area = complianceArea($user);
    bmsForDeadline($area, $user, '2026-08-20');
    engpForDeadline($user, '2026-08-31', ['office' => 'CENRO Baganga']);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'pa-from@example.test', 'cc_emails' => ['pa-cc@example.test'], 'is_active' => true]);
    ComplianceAlertRecipient::create(['target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga', 'recipient_email' => 'engp-from@example.test', 'cc_emails' => ['engp-cc@example.test'], 'is_active' => true]);
    enabledComplianceSettings(['sender_display_name' => 'Legacy settings sender that must not override config']);

    app(ComplianceAlertDeliveryService::class)->sendAutomatic();

    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail): bool {
        $from = $mail->envelope()->from;
        return $mail->presentation['template'] === 'protected_area_overdue'
            && $from->address === 'configured-sender@example.test'
            && $from->name === 'Enhanced Digital Alert and Tracking System (eDATS)'
            && $mail->hasTo('pa-from@example.test')
            && $mail->hasCc('pa-cc@example.test');
    });
    Mail::assertSent(OverdueComplianceMemorandum::class, function (OverdueComplianceMemorandum $mail): bool {
        $from = $mail->envelope()->from;
        return $mail->presentation['template'] === 'engp_overdue'
            && $from->address === 'configured-sender@example.test'
            && $from->name === 'Enhanced Digital Alert and Tracking System (eDATS)'
            && $mail->hasTo('engp-from@example.test')
            && $mail->hasCc('engp-cc@example.test');
    });
});

test('all PA and ENGP template previews use safe data and never send mail', function () {
    Mail::fake();
    $manager = complianceManager(complianceUser());
    $cases = [
        'protected_area_due_soon' => ['Protected Area', 'Protected Area Management-related report', 'Days Remaining'],
        'protected_area_overdue' => ['PRIORITY ACTION REQUIRED', 'Overdue Submission of PA-related Reports', 'Protected Area'],
        'engp_due_soon' => ['Enhanced National Greening Program (ENGP)', 'Implementing Office', 'Days Remaining'],
        'engp_overdue' => ['IMMEDIATE ACTION REQUIRED', 'Submission of Regular ENGP Monitoring and Accomplishment Reports', 'Implementing Office'],
    ];

    foreach ($cases as $template => $needles) {
        $response = $this->actingAs($manager)->post(route('compliance-alerts.templates.preview'), ['template' => $template]);
        $response->assertOk();
        $html = (string) $response->json('html');
        foreach ($needles as $needle) {
            expect($html)->toContain($needle);
        }
    }

    Mail::assertNothingSent();
});

test('mail credentials are never exposed through compliance settings or retained as template content', function () {
    config()->set('mail.mailers.smtp.password', 'unit-only-secret');
    $manager = complianceManager(complianceUser());
    enabledComplianceSettings();

    $response = $this->actingAs($manager)->get(route('settings.compliance-alerts'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('settings')
        ->where('settings.mail_from_name', config('mail.from.name'))
        ->missing('settings.sender_display_name')
        ->missing('settings.mail_password')
        ->missing('settings.password'));
    expect($response->getContent())->not->toContain('unit-only-secret');

    $templates = app(ComplianceAlertTemplateResolver::class)->templateSettings([
        'template_settings' => ['protected_area_due_soon' => ['subject' => 'Safe subject', 'password' => 'must-not-persist']],
    ]);
    expect($templates['protected_area_due_soon'])->toHaveKey('subject', 'Safe subject')
        ->not->toHaveKey('password');
});