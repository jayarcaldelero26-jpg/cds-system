<?php

use App\Models\BmsRecord;
use App\Models\BamsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\Aws;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

test('scoped users with effective BMS authorization can download protected BMS attachments', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['section' => 'CDS']);
    $office = OrganizationalOffice::query()->where('name', 'CENRO Baganga')->firstOrFail();
    $area = ProtectedArea::create([
        'name' => 'P1-B Attachment Scope PA',
        'short_name' => 'P1BPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $office->id,
        'assignment_type' => 'supervising',
    ]);
    $record = BmsRecord::create([
        'protected_area_id' => $area->id,
        'monitoring_date' => '2026-09-01',
        'taxonomic_group' => 'Birds',
        'species_scientific_name' => 'P1B.Attachmentus',
        'attachment' => 'bms-attachments/p1b.pdf',
    ]);
    Storage::disk('local')->put($record->attachment, '%PDF-1.4 P1-B');

    $scopedWithoutPermission = User::factory()->create([
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => 'conservation',
        'office_designated' => 'CENRO Baganga',
    ]);

    expect($scopedWithoutPermission->can('bms.view'))->toBeTrue()
        ->and($scopedWithoutPermission->getAllPermissions()->contains(fn ($permission): bool => $permission->name === 'bms.view'))->toBeFalse();

    $response = $this->actingAs($scopedWithoutPermission)->get(route('attachments.show', [
        'source' => 'bms-data',
        'record' => $record->id,
        'attachment' => 'attachment',
    ]));

    expect($response->getStatusCode())->toBe(200);
});

test('the protected attachment route enforces each registry source ability before object scope', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['section' => 'CDS']);
    $office = OrganizationalOffice::query()->where('name', 'CENRO Baganga')->firstOrFail();
    $area = ProtectedArea::create([
        'name' => 'P1-B Source Matrix PA',
        'short_name' => 'P1BSM',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $office->id,
        'assignment_type' => 'supervising',
    ]);
    $user = User::factory()->create([
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => 'conservation',
        'office_designated' => 'CENRO Baganga',
    ]);
    $developmentUser = User::factory()->create([
        'section' => 'ENGP',
        'unit_assignment' => 'development',
        'office_designated' => 'CENRO Baganga',
    ]);
    foreach (['technical-reports.view', 'bams.view', 'imea.view', 'aws.view'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $developmentUser->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));

    $sources = [
        ['conservation-report', ConservationReportSubmission::create(['workflow_key' => 'homestay', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'activity_name' => 'Homestay', 'document_type' => 'Final Report', 'date_accomplished' => '2026-09-01', 'mov_file_path' => 'conservation-report-movs/p1b.pdf']), 'mov', 'technical-reports.view', $user],
        ['bams-report', BamsReportSubmission::create(['protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'activity_name' => 'BAMS', 'document_type' => 'Final Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-09-01', 'mov_file_path' => 'bams-report-movs/p1b.pdf']), 'mov', 'bams.view', $user],
        ['imea-report', ImeaReportSubmission::create(['protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'activity_name' => 'IMEA', 'document_type' => 'Final Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-09-01', 'mov_file_path' => 'imea-report-movs/p1b.pdf']), 'mov', 'imea.view', $user],
        ['aws', Aws::create(['protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'station_name' => 'P1B AWS', 'location' => 'Field', 'activity_name' => 'AWS report', 'document_type' => 'Quarterly Report', 'reporting_year' => 2026, 'quarter' => 3, 'report_period_type' => 'Quarterly', 'date_accomplished' => '2026-09-01', 'report_file_path' => 'aws_reports/p1b.pdf']), 'report_file', 'aws.view', $user],
        ['ipaf-management', IpafManagementReport::create(['protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'activity_name' => 'IPAF Management', 'document_type' => 'Final Report', 'date_accomplished' => '2026-09-01', 'mov_file_path' => 'ipaf-management-movs/p1b.pdf']), 'mov', 'technical-reports.view', $user],
        ['engp-report', EngpReportSubmission::create(['workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP', 'activity_name' => 'ENGP', 'document_type' => 'Monthly Report', 'reporting_year' => 2026, 'period_key' => '2026-09', 'period_label' => 'September 2026', 'deadline_submission' => '2026-09-30', 'mov_file_path' => 'engp-report-movs/p1b.pdf']), 'mov', 'technical-reports.view', $developmentUser],
    ];
    foreach ($sources as [$source, $record, $key, $ability, $authorized]) {
        Storage::disk('local')->put($record->getAttribute(str_ends_with($key, 'file') ? 'report_file_path' : 'mov_file_path'), '%PDF-1.4 P1-B');
        $this->actingAs($authorized)->get(route('attachments.show', [$source, $record->id, $key]))->assertOk();
        $this->actingAs($user)->get(route('attachments.show', [$source, $record->id, $key]))->assertOk();
        $withoutAbility = User::factory()->create(['section' => 'UNKNOWN', 'unit_assignment' => null, 'office_designated' => 'CENRO Baganga']);
        $this->actingAs($withoutAbility)->get(route('attachments.show', [$source, $record->id, $key]))->assertForbidden();
    }
});

test('source permission does not bypass wrong protected-area scope', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create(['section' => 'CDS']);
    $baganga = OrganizationalOffice::query()->where('name', 'CENRO Baganga')->firstOrFail();
    $mati = OrganizationalOffice::query()->where('name', 'CENRO Mati')->firstOrFail();
    $area = ProtectedArea::create(['name' => 'P1-B Owned PA', 'short_name' => 'P1BO', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => $baganga->id, 'assignment_type' => 'supervising']);
    $record = BmsRecord::create(['protected_area_id' => $area->id, 'monitoring_date' => '2026-09-01', 'taxonomic_group' => 'Birds', 'species_scientific_name' => 'P1B.Scopeus', 'attachment' => 'bms-attachments/scope.pdf']);
    Storage::disk('local')->put($record->attachment, '%PDF-1.4 P1-B');
    $wrongOffice = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => 'CENRO Mati']);
    $wrongOffice->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));

    $this->actingAs($wrongOffice)->get(route('attachments.show', ['bms-data', $record->id, 'attachment']))->assertForbidden();
});
