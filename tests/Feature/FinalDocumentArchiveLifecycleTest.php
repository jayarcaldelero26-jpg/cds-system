<?php

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\ArchiveOfficeResolver;
use App\Services\Archive\ArchivePathBuilder;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\FinalDocumentSourceContractRegistry;
use App\Services\Archive\RegionalReceivingOfficeResolver;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'fake',
        'services.document_archive.final_return_archive_enabled' => true,
    ]);
    Storage::fake('local');
    Notification::fake();
    app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());
});

function finalArchiveActor(string $category, string $office, array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'protected_area_id' => null,
        'is_active' => true,
        'is_approved' => true,
    ], $overrides));
    foreach (['reports.view', 'bms.update', 'submission-tracking.view'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    foreach (['technical-reports.view', 'technical-reports.update', 'bams.update', 'imea.update', 'aws.update'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    return $user;
}

function finalArchiveArea(User $creator, string $office = 'CENRO Mati', string $name = 'Final archive scope PA'): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    $officeId = OrganizationalOffice::query()->where('name', $office)->value('id');
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising',
    ]);

    return $area;
}

/** @return array{report:BmsReportSubmission,penro_focal:User,events_before:int,gateway:FakeDocumentArchiveGateway} */
function finalArchiveReleasedBms(string $name = 'Final archive release fixture'): array
{
    ModuleDefinition::query()->firstOrCreate(['code' => 'bms'], [
        'name' => 'BMS', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
    $creator = User::factory()->create();
    $area = finalArchiveArea($creator, 'CENRO Mati', $name);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => $name,
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);
    $path = 'bms-report-movs/final-archive-source-'.$report->id.'.pdf';
    $report->update(['mov_file_path' => $path, 'mov_file_name' => 'Unsigned source report.pdf']);
    Storage::disk('local')->put($path, "%PDF-1.4\nUnsigned working source copy");

    $actors = [
        'cenro_focal' => finalArchiveActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
        'cenro_chief' => finalArchiveActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
        'cenro_records' => finalArchiveActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
        'penro_records' => finalArchiveActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
        'office' => finalArchiveActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
        'tsd' => finalArchiveActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
        'penro_focal' => finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
        'penro_chief' => finalArchiveActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'),
    ];
    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        ['cenro_focal', 'forward_to_cenro_chief'], ['cenro_chief', 'receive_at_cenro_chief'],
        ['cenro_chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'],
        ['cenro_records', 'forward_to_penro_records'], ['penro_records', 'receive_at_penro_records'],
        ['penro_records', 'forward_to_office_penro'], ['office', 'receive_at_office_penro'],
        ['office', 'assign_to_tsd_chief'], ['tsd', 'receive_at_tsd_chief'],
        ['tsd', 'forward_to_cds_focal'], ['penro_focal', 'receive_at_cds_focal'],
        ['penro_focal', 'forward_to_cds_chief'], ['penro_chief', 'receive_at_cds_chief'],
        ['penro_chief', 'recommend_to_office_penro'], ['office', 'receive_at_office_penro_final'],
        ['office', 'approve_for_regional_release'], ['penro_records', 'receive_at_penro_records_final'],
        ['penro_records', 'release_to_regional'],
    ] as [$actorKey, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, $actors[$actorKey]->id);
    }

    return [
        'report' => $report->fresh(), 'penro_focal' => $actors['penro_focal'],
        'events_before' => \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->id)->count(),
        'gateway' => app(GoogleDriveArchiveGateway::class),
    ];
}

/** @return array{report:\Illuminate\Database\Eloquent\Model,penro_focal:User,events_before:int,gateway:FakeDocumentArchiveGateway} */
function finalArchiveReleasedSource(string $source, string $name, bool $legacyPambRelease = false): array
{
    if ($legacyPambRelease && $source !== 'conservation') throw new InvalidArgumentException('Legacy PAMB release fixtures require the Conservation source.');
    $development = $source === 'engp';
    $unit = $development ? OrganizationalAccessService::DEVELOPMENT : OrganizationalAccessService::CONSERVATION;
    $owner = User::factory()->create();
    $area = $development ? null : finalArchiveArea($owner, 'CENRO Mati', $name.' PA');
    $workflowKey = $legacyPambRelease ? 'regular_pamb' : 'homestay';
    $moduleCode = match ($source) {
        'conservation' => $workflowKey,
        'bams' => 'bams',
        'imea' => 'imea',
        'imea-maintenance' => 'imea_facility_maintenance',
        'aws' => 'automated_weather_station',
        'ipaf-management' => 'ipaf_management',
        'revenue' => 'revenue_collection',
        'engp' => 'engp_cbep',
        default => throw new InvalidArgumentException('Unsupported final archive source fixture.'),
    };
    ModuleDefinition::query()->firstOrCreate(['code' => $moduleCode], [
        'name' => $name, 'program_area' => $development ? 'development' : 'protected_area_management_and_development',
        'implementation_type' => $source === 'conservation' ? 'generic' : 'specialized',
        'module_type' => 'regular_target', 'deadline_mode' => 'standard_working_days', 'is_active' => true,
        ...($source === 'conservation' ? ['requirement_domain' => 'pa', 'requirement_key' => $workflowKey] : []),
    ]);

    $common = [
        'protected_area_id' => $area?->getKey(), 'target_office' => 'CENRO Mati',
        'activity_name' => $name, 'document_type' => 'Report', 'date_conducted' => '2026-10-01',
        'date_accomplished' => '2026-10-01', 'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
    ];
    $report = match ($source) {
        'conservation' => ConservationReportSubmission::query()->create([
            ...$common, 'workflow_key' => $workflowKey, 'reporting_period' => 'Quarter 3',
        ]),
        'bams' => BamsReportSubmission::query()->create([...$common, 'semester' => '1st Semester']),
        'imea' => ImeaReportSubmission::query()->create([...$common, 'semester' => '1st Semester']),
        'imea-maintenance' => ImeaFacilityMaintenanceReport::query()->create([...$common, 'quarter' => 'Quarter 3']),
        'aws' => Aws::query()->create([...$common, 'station_name' => 'Final Archive AWS', 'location' => 'Mati', 'status' => 'Active']),
        'ipaf-management' => IpafManagementReport::query()->create($common),
        'revenue' => IpafRevenueCollection::query()->create([
            ...collect($common)->except(['date_conducted', 'date_accomplished'])->all(), 'document_type' => 'Revenue Report',
            'reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '1000.00',
        ]),
        'engp' => EngpReportSubmission::query()->create([
            'workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'section_name' => 'NGP',
            'activity_name' => $name, 'document_type' => 'Monthly Report', 'reporting_year' => 2026,
            'period_key' => '2026-10', 'period_label' => 'October 2026', 'deadline_submission' => '2026-11-10',
            'mov_external_url' => 'https://example.invalid/source-reference-only',
            'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
        ]),
    };

    $attachmentSource = app(FinalDocumentSourceContractRegistry::class)
        ->forRegisteredSource($source, $report, app(ReportDocumentAdapterResolver::class));
    if (! $attachmentSource) throw new RuntimeException('The source fixture did not resolve its official attachment slot.');
    $attachmentPath = match ($source) {
        'aws' => 'aws_reports/'.$source.'-'.$report->getKey().'.pdf',
        'engp' => 'engp-report-movs/'.$source.'-'.$report->getKey().'.pdf',
        default => $source.'-final-movs/'.$source.'-'.$report->getKey().'.pdf',
    };
    $pathColumn = $source === 'aws' ? 'report_file_path' : 'mov_file_path';
    $nameColumn = $source === 'aws' ? 'report_file_name' : 'mov_file_name';
    $report->forceFill([$pathColumn => $attachmentPath, ...($source === 'engp' ? [] : [$nameColumn => 'Unsigned source.pdf'])])->saveOrFail();
    Storage::disk('local')->put($attachmentPath, "%PDF-1.7\nUnsigned source copy for {$source}");

    $actorOverrides = ['unit_assignment' => $unit];
    $actors = [
        'cenro_focal' => finalArchiveActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati', $actorOverrides),
        'cenro_chief' => finalArchiveActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati', $actorOverrides),
        'cenro_records' => finalArchiveActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati', $actorOverrides),
        'penro_records' => finalArchiveActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental', $actorOverrides),
        'office' => finalArchiveActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental', $actorOverrides),
        'tsd' => finalArchiveActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental', $actorOverrides),
        'penro_focal' => finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental', $actorOverrides),
        'penro_chief' => finalArchiveActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental', $actorOverrides),
    ];

    if ($legacyPambRelease) {
        $movProcessing = app(PambMovProcessingService::class);
        try { $movProcessing->submit($report->fresh(), $actors['cenro_focal']); }
        catch (\Throwable $exception) { throw new RuntimeException('Final archive PAMB MOV submission failed ('.$exception::class.', '.$exception->getCode().'): '.$exception->getMessage(), 0, $exception); }
    }

    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        ['cenro_focal', 'forward_to_cenro_chief'], ['cenro_chief', 'receive_at_cenro_chief'],
        ['cenro_chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'],
        ['cenro_records', 'forward_to_penro_records'], ['penro_records', 'receive_at_penro_records'],
        ['penro_records', 'forward_to_office_penro'], ['office', 'receive_at_office_penro'],
        ['office', 'assign_to_tsd_chief'], ['tsd', 'receive_at_tsd_chief'],
        ['tsd', 'forward_to_cds_focal'], ['penro_focal', 'receive_at_cds_focal'],
        ['penro_focal', 'forward_to_cds_chief'], ['penro_chief', 'receive_at_cds_chief'],
        ['penro_chief', 'recommend_to_office_penro'], ['office', 'receive_at_office_penro_final'],
        ['office', 'approve_for_regional_release'], ['penro_records', 'receive_at_penro_records_final'],
        ['penro_records', 'release_to_regional'],
    ] as [$actorKey, $action]) {
        if ($legacyPambRelease && $action === 'release_to_regional') continue;
        try {
            $routing->transition($report->fresh(), $source, $action, $actors[$actorKey]->getKey());
        } catch (\Throwable $exception) {
            throw new RuntimeException("Final archive fixture transition {$source}:{$action} failed: ".$exception->getMessage(), 0, $exception);
        }
        if ($legacyPambRelease && $action === 'receive_at_cenro_chief') {
            try { app(PambMovProcessingService::class)->review($report->fresh(), $actors['cenro_chief'], PambMovProcessingService::READY_FOR_RELEASE); }
            catch (\Throwable $exception) { $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : $exception->getCode(); throw new RuntimeException('Final archive PAMB MOV review failed ('.$exception::class.', '.$status.'): '.$exception->getMessage(), 0, $exception); }
        }
    }

    if ($legacyPambRelease) {
        $legacyReleasedAt = now()->addMinute();
        $report->forceFill(['date_endorsed_regional' => $legacyReleasedAt->toDateString()])->saveOrFail();
        PambRoutingEvent::query()->create([
            'conservation_report_submission_id' => $report->getKey(),
            'workflow_key' => $workflowKey,
            'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL,
            'occurred_at' => $legacyReleasedAt,
            'recorded_by' => $actors['penro_records']->getKey(),
        ]);
    }

    return [
        'report' => $report->fresh(), 'penro_focal' => $actors['penro_focal'],
        'events_before' => \App\Models\DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $report->getKey())->count(),
        'gateway' => app(GoogleDriveArchiveGateway::class),
    ];
}

/** @return array{report:BmsReportSubmission,penro_focal:User,events_before:int,gateway:FakeDocumentArchiveGateway} */
function finalArchiveReleasedDirectMhrwsBms(): array
{
    ModuleDefinition::query()->firstOrCreate(['code' => 'bms'], [
        'name' => 'BMS', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
    $owner = User::factory()->create();
    $area = ProtectedArea::query()->create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS',
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
    ]);
    $penroOffice = OrganizationalOffice::query()->where('office_type', 'penro')->where('is_active', true)->firstOrFail();
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(), 'organizational_office_id' => $penroOffice->getKey(),
        'assignment_type' => 'supervising',
    ]);
    $report = BmsReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(), 'target_office' => $penroOffice->name,
        'activity_name' => 'Direct MHRWS final archive fixture', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-10-01',
    ]);
    $path = 'bms-report-movs/direct-mhrws-'.$report->getKey().'.pdf';
    $report->forceFill(['mov_file_path' => $path, 'mov_file_name' => 'Unsigned MHRWS source.pdf'])->saveOrFail();
    Storage::disk('local')->put($path, '%PDF-1.7'."\nUnsigned direct MHRWS source");

    $actors = [
        'focal' => finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, $penroOffice->name),
        'records' => finalArchiveActor(OrganizationalAccessService::PENRO_RECORDS, $penroOffice->name),
        'office' => finalArchiveActor(OrganizationalAccessService::OFFICE_PENRO, $penroOffice->name),
        'tsd' => finalArchiveActor(OrganizationalAccessService::PENRO_TSD_CHIEF, $penroOffice->name),
        'chief' => finalArchiveActor(OrganizationalAccessService::PENRO_CHIEF, $penroOffice->name),
    ];
    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        ['records', 'receive_at_penro_records'],
        ['records', 'forward_to_office_penro'], ['office', 'receive_at_office_penro'],
        ['office', 'assign_to_tsd_chief'], ['tsd', 'receive_at_tsd_chief'],
        ['tsd', 'forward_to_cds_focal'], ['focal', 'receive_at_cds_focal'],
        ['focal', 'forward_to_cds_chief'], ['chief', 'receive_at_cds_chief'],
        ['chief', 'recommend_to_office_penro'], ['office', 'receive_at_office_penro_final'],
        ['office', 'approve_for_regional_release'], ['records', 'receive_at_penro_records_final'],
        ['records', 'release_to_regional'],
    ] as [$actorKey, $action]) {
        try {
            $routing->transition($report->fresh(), 'bms', $action, $actors[$actorKey]->getKey());
        } catch (\Throwable $exception) {
            throw new RuntimeException("Direct MHRWS final archive fixture transition {$action} failed: ".$exception->getMessage(), 0, $exception);
        }
    }

    return [
        'report' => $report->fresh(), 'penro_focal' => $actors['focal'],
        'events_before' => \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->count(),
        'gateway' => app(GoogleDriveArchiveGateway::class),
    ];
}

function finalArchiveSpreadsheetUpload(string $filename): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cds-final-archive-xlsx-');
    if (! is_string($path)) throw new RuntimeException('Could not create a disposable XLSX test file.');
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not open the disposable XLSX test file.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');
    $zip->close();

    return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true);
}

test('source registry binds each supported family to its real official slot and excludes Management Plans', function (): void {
    $documents = app(ReportDocumentAdapterResolver::class);
    $registry = app(FinalDocumentSourceContractRegistry::class);
    $fixtures = [
        'conservation' => new ConservationReportSubmission(['workflow_key' => 'regular_pamb']),
        'bms' => new BmsReportSubmission(),
        'bams' => new BamsReportSubmission(),
        'imea' => new ImeaReportSubmission(),
        'imea-maintenance' => new ImeaFacilityMaintenanceReport(),
        'aws' => new Aws(),
        'ipaf-management' => new IpafManagementReport(),
        'revenue' => new IpafRevenueCollection(),
        'engp' => new EngpReportSubmission(['workflow_key' => 'engp_annual']),
    ];
    $expectedSlots = [
        'conservation' => ['conservation-report', 'mov'], 'bms' => ['bms-report', 'mov'],
        'bams' => ['bams-report', 'mov'], 'imea' => ['imea-report', 'mov'],
        'imea-maintenance' => ['imea-maintenance', 'mov'], 'aws' => ['aws', 'report_file'],
        'ipaf-management' => ['ipaf-management', 'mov'], 'revenue' => ['ipaf-revenue', 'mov'],
        'engp' => ['engp-report', 'mov'],
    ];
    $commonTypes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $expectedExtensions = [
        'conservation' => $commonTypes, 'bms' => $commonTypes, 'bams' => $commonTypes, 'imea' => $commonTypes,
        'imea-maintenance' => $commonTypes, 'aws' => [...$commonTypes, 'xls', 'xlsx'],
        'ipaf-management' => $commonTypes, 'revenue' => $commonTypes,
        'engp' => [...$commonTypes, 'xls', 'xlsx'],
    ];
    $expectedLimits = [
        'conservation' => 102400, 'bms' => 102400, 'bams' => 102400, 'imea' => 102400,
        'imea-maintenance' => 20480, 'aws' => 10240, 'ipaf-management' => 10240,
        'revenue' => 10240, 'engp' => 10240,
    ];

    foreach ($fixtures as $source => $record) {
        $contract = $registry->forRegisteredSource($source, $record, $documents);
        expect($contract)->not->toBeNull()
            ->and([$contract['attachment_source'], $contract['slot']])->toBe($expectedSlots[$source])
            ->and($contract['extensions'])->toBe($expectedExtensions[$source])
            ->and($contract['max_kilobytes'])->toBe($expectedLimits[$source]);
    }

    expect($registry->forRegisteredSource('management-plans', new \App\Models\ManagementPlan(), $documents))->toBeNull()
        ->and($registry->allowedMimeTypes('aws'))->toContain('application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($registry->allowedMimeTypes('engp'))->toContain('application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($registry->logicalIdentity('aws', 1))->not->toBe($registry->logicalIdentity('aws', 2));
});

test('every archive source enforces its declared upload size cap and spreadsheet boundary', function (): void {
    $registry = app(FinalDocumentSourceContractRegistry::class);
    $limits = [
        'conservation' => 102400, 'bms' => 102400, 'bams' => 102400, 'imea' => 102400,
        'imea-maintenance' => 20480, 'aws' => 10240, 'ipaf-management' => 10240,
        'revenue' => 10240, 'engp' => 10240,
    ];
    foreach ($limits as $source => $limit) {
        $oversized = UploadedFile::fake()->createWithContent('oversized-'.$source.'.pdf', "%PDF-1.7\nsynthetic size-boundary file");
        $oversized->size($limit + 1);
        try {
            expect(fn () => $registry->validateUpload($source, $oversized))->toThrow(ValidationException::class);
        } finally {
            if (is_file($oversized->getPathname())) @unlink($oversized->getPathname());
        }
    }

    foreach (['conservation', 'bms', 'bams', 'imea', 'imea-maintenance', 'ipaf-management', 'revenue'] as $source) {
        $spreadsheet = finalArchiveSpreadsheetUpload('unsupported-'.$source.'.xlsx');
        try {
            expect(fn () => $registry->validateUpload($source, $spreadsheet))->toThrow(ValidationException::class);
        } finally {
            if (is_file($spreadsheet->getPathname())) @unlink($spreadsheet->getPathname());
        }
    }

    foreach (['aws', 'engp'] as $source) {
        $spreadsheet = finalArchiveSpreadsheetUpload('supported-'.$source.'.xlsx');
        try {
            expect($registry->validateUpload($source, $spreadsheet)['extension'])->toBe('xlsx');
        } finally {
            if (is_file($spreadsheet->getPathname())) @unlink($spreadsheet->getPathname());
        }
    }
});

test('each remaining generic source releases, receives, archives its returned set, and protects the final copy', function (string $source, bool $spreadsheet): void {
    $fixture = finalArchiveReleasedSource($source, 'Final archive '.$source.' lifecycle');
    $archives = app(FinalDocumentArchiveService::class);
    $gateway = $fixture['gateway'];
    $beforeReceiptUploads = $gateway->uploadAttempts();
    $beforeReceiptEvents = $fixture['events_before'];
    $receipt = $archives->recordReceipt(
        $fixture['penro_focal'], $source, (int) $fixture['report']->getKey(),
        now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
    );
    expect($receipt->source_type)->toBe($source)
        ->and((int) $receipt->source_id)->toBe((int) $fixture['report']->getKey())
        ->and($gateway->uploadAttempts())->toBe($beforeReceiptUploads)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $fixture['report']->getKey())->count())->toBe($beforeReceiptEvents);

    $upload = $spreadsheet
        ? finalArchiveSpreadsheetUpload('regional-return-'.$source.'.xlsx')
        : UploadedFile::fake()->createWithContent('regional-return-'.$source.'.pdf', "%PDF-1.7\nReturned signed {$source} copy");
    try {
        $result = $archives->createOrRetryManifest(
            $fixture['penro_focal'], $source, (int) $fixture['report']->getKey(), (string) Str::uuid(), [$upload], true,
        );
    } finally {
        if ($spreadsheet && is_file($upload->getPathname())) @unlink($upload->getPathname());
    }

    $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('receipt_id', $receipt->getKey())->firstOrFail();
    $file = $manifest->files->firstOrFail();
    expect($result['status'])->toBe('archived')
        ->and($manifest->archive_status)->toBe('ARCHIVED')
        ->and($manifest->archive_phase)->toBe(FinalDocumentArchiveManifest::PHASE)
        ->and($manifest->files)->toHaveCount(1)
        ->and($file->file_status)->toBe('ARCHIVED')
        ->and($file->original_filename)->toBe('regional-return-'.$source.($spreadsheet ? '.xlsx' : '.pdf'))
        ->and($file->mime_type)->toBeIn(app(FinalDocumentSourceContractRegistry::class)->allowedMimeTypes($source))
        ->and($file->staged_path)->toStartWith('regional-return-final/')
        ->and($archives->authorizeManifestFileReader($fixture['penro_focal'], $file)->getKey())->toBe($file->document_archive_id);

    $this->actingAs($fixture['penro_focal'])->get(route('final-archives.files.show', $file->getKey()).'?download=1')->assertOk();
    $this->actingAs($fixture['penro_focal'])->get(route('final-archives.index', ['source' => $source, 'record' => $fixture['report']->getKey()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selected.source', $source)
            ->where('selected.latest_manifest.status', 'ARCHIVED'));

    if ($source === 'engp') {
        expect($fixture['report']->mov_external_url)->toBe('https://example.invalid/source-reference-only')
            ->and($file->staged_path)->not->toBe($fixture['report']->mov_external_url)
            ->and(Storage::disk('local')->exists($file->staged_path))->toBeTrue();
    }
})->with([
    'generic Conservation' => ['conservation', false],
    'BAMS' => ['bams', false],
    'IMEA' => ['imea', false],
    'IMEA Maintenance' => ['imea-maintenance', false],
    'AWS spreadsheet return' => ['aws', true],
    'IPAF Management' => ['ipaf-management', false],
    'Revenue' => ['revenue', false],
    'ENGP Development spreadsheet return' => ['engp', true],
]);

test('legacy Regular PAMB release evidence supports separate receipt and final archive without another custody event', function (): void {
    $fixture = finalArchiveReleasedSource('conservation', 'Legacy Regular PAMB final archive', true);
    $report = $fixture['report'];
    $eventsBefore = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->count();
    $legacyRelease = PambRoutingEvent::query()->where('conservation_report_submission_id', $report->getKey())
        ->where('stage_key', PambRoutingTimelineService::RELEASED_TO_REGIONAL)->firstOrFail();
    $state = app(DocumentRoutingTransitionService::class)->state($report, 'conservation');
    $lastProjected = $state['events']->last();
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and((int) data_get($lastProjected->metadata, 'legacy_pamb_event_id'))->toBe((int) $legacyRelease->getKey());

    $gateway = $fixture['gateway'];
    $attemptsBeforeReceipt = $gateway->uploadAttempts();
    $receipt = app(FinalDocumentArchiveService::class)->recordReceipt(
        $fixture['penro_focal'], 'conservation', (int) $report->getKey(),
        $legacyRelease->occurred_at->copy()->addMinute()->format('Y-m-d H:i:s'),
    );
    expect($receipt->release_evidence_type)->toBe('pamb_routing_events')
        ->and($receipt->release_evidence_key)->toBe('pamb_routing_events:'.$legacyRelease->getKey())
        ->and($gateway->uploadAttempts())->toBe($attemptsBeforeReceipt)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->count())->toBe($eventsBefore);

    $result = app(FinalDocumentArchiveService::class)->createOrRetryManifest(
        $fixture['penro_focal'], 'conservation', (int) $report->getKey(), (string) Str::uuid(),
        [UploadedFile::fake()->createWithContent('legacy-pamb-final.pdf', "%PDF-1.7\nSigned legacy PAMB return")], true,
    );
    $manifest = FinalDocumentArchiveManifest::query()->where('receipt_id', $receipt->getKey())->firstOrFail();
    expect($result['status'])->toBe('archived')
        ->and($manifest->archive_status)->toBe('ARCHIVED')
        ->and($manifest->source_type)->toBe('conservation')
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->getKey())->count())->toBe($eventsBefore);
});

test('direct PENRO MHRWS release receives and archives under the authoritative PENRO assignment', function (): void {
    $fixture = finalArchiveReleasedDirectMhrwsBms();
    $report = $fixture['report'];
    $archives = app(FinalDocumentArchiveService::class);
    $receipt = $archives->recordReceipt(
        $fixture['penro_focal'], 'bms', (int) $report->getKey(),
        now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
    );

    expect($receipt->receiving_office)->toBe('PENRO Davao Oriental')
        ->and($receipt->release_evidence_type)->toBe('document_routing_events')
        ->and($fixture['gateway']->uploadAttempts())->toBe(0);

    $result = $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', (int) $report->getKey(), (string) Str::uuid(),
        [UploadedFile::fake()->createWithContent('mhrws-final-return.pdf', "%PDF-1.7\nMHRWS signed final report")], true,
    );
    $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('receipt_id', $receipt->getKey())->firstOrFail();
    $file = $manifest->files->firstOrFail();

    expect($result['status'])->toBe('archived')
        ->and($manifest->archive_status)->toBe('ARCHIVED')
        ->and($manifest->files)->toHaveCount(1)
        ->and($archives->authorizeManifestFileReader($fixture['penro_focal'], $file)->getKey())->toBe($file->document_archive_id);
    $this->actingAs($fixture['penro_focal'])
        ->get(route('final-archives.files.show', $file->getKey()).'?download=1')->assertOk();
});

test('PAMO no_role readers retain only their assigned PA Conservation visibility', function (): void {
    ModuleDefinition::query()->firstOrCreate(['code' => 'regular_pamb'], [
        'name' => 'Regular PAMB Meetings', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'generic', 'module_type' => 'regular_target',
        'deadline_mode' => 'pamb_working_days', 'requirement_domain' => 'pa',
        'requirement_key' => 'regular_pamb', 'is_active' => true,
    ]);
    ModuleDefinition::query()->where('code', 'regular_pamb')->update([
        'implementation_type' => 'generic', 'requirement_domain' => 'pa',
        'requirement_key' => 'regular_pamb', 'is_active' => true,
    ]);
    $creator = User::factory()->create();
    $area = finalArchiveArea($creator, 'CENRO Mati', 'PAMO final archive reader PA');
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => 'pamo_final_archive_reader', 'name' => 'PAMO Final Archive Reader', 'office_type' => 'pamo', 'is_active' => true,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(), 'organizational_office_id' => $pamoOffice->getKey(),
        'assignment_type' => 'pamo',
    ]);
    $area->forceFill(['status' => 'Active', 'pamo' => $pamoOffice->name])->save();
    $record = ConservationReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(), 'workflow_key' => 'regular_pamb',
        'target_office' => 'CENRO Mati', 'activity_name' => 'PAMO scoped archive reader check',
        'date_conducted' => '2026-10-01',
    ]);
    $reader = User::factory()->create([
        'section' => OrganizationalAccessService::PAMO, 'unit_assignment' => null,
        'protected_area_id' => $area->getKey(), 'office_designated' => $pamoOffice->name,
        'is_active' => true, 'is_approved' => true,
    ]);
    $reader->assignRole(\Spatie\Permission\Models\Role::findOrCreate('no_role', 'web'));

    $archives = app(FinalDocumentArchiveService::class);
    $source = app(SubmissionTrackingService::class)->source('conservation');
    expect($archives->canBrowse($reader))->toBeTrue()
        ->and(app(DocumentRoutingAccessService::class)->canView($reader, $record, 'conservation', $source['ability']))->toBeTrue()
        ->and(app(FinalDocumentSourceContractRegistry::class)->forRegisteredSource('conservation', $record, app(ReportDocumentAdapterResolver::class)))->not->toBeNull()
        ->and($archives->context($reader, 'conservation', (int) $record->getKey())['source'])->toBe('conservation');

    $foreignOffice = OrganizationalOffice::query()->create([
        'code' => 'pamo_final_archive_foreign', 'name' => 'PAMO Foreign Archive Reader', 'office_type' => 'pamo', 'is_active' => true,
    ]);
    $foreign = User::factory()->create([
        'section' => OrganizationalAccessService::PAMO, 'unit_assignment' => null,
        'protected_area_id' => $area->getKey(), 'office_designated' => $foreignOffice->name,
        'is_active' => true, 'is_approved' => true,
    ]);
    $foreign->assignRole(\Spatie\Permission\Models\Role::findOrCreate('no_role', 'web'));
    expect(fn () => $archives->context($foreign, 'conservation', (int) $record->getKey()))
        ->toThrow(HttpException::class);
});

test('final-return archive folders resolve a direct MHRWS report under its authoritative PENRO office', function (): void {
    $creator = User::factory()->create();
    $penro = OrganizationalOffice::query()->where('office_type', 'penro')->where('is_active', true)->firstOrFail();
    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS', 'category' => 'Protected Landscape',
        'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $penro->id,
        'assignment_type' => 'supervising',
    ]);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => $penro->name,
        'activity_name' => 'MHRWS direct final report', 'document_type' => 'Report', 'semester' => '1st Semester',
    ]);
    $source = app(SubmissionTrackingService::class)->source('bms');
    $resolver = app(ArchiveOfficeResolver::class);

    expect($resolver->resolveRegionalReturn($source, $report))->toBe($penro->name);
    expect(fn () => $resolver->resolve($source, $report))->toThrow(ValidationException::class);
    expect(app(RegionalReceivingOfficeResolver::class)->resolve('bms', $report, $source))->toBe($penro->name);

    $module = ModuleDefinition::query()->firstOrCreate(['code' => 'bms'], [
        'name' => 'BMS', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
    $path = app(ArchivePathBuilder::class)->build($module, 'Conservation Unit', $penro->name, 'MHRWS direct final report', 'pdf', true);
    expect($path['segments'])->toBe(['Conservation Unit', $penro->name, 'BMS']);
});

test('final receipt office is derived from the report PA assignment and rejects a mismatched source office', function (): void {
    $creator = User::factory()->create();
    $area = finalArchiveArea($creator, 'CENRO Mati', 'Receiving-office contract PA');
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Receiving office must follow PA assignment',
        'document_type' => 'Report', 'semester' => '1st Semester',
    ]);
    $source = app(SubmissionTrackingService::class)->source('bms');
    $resolver = app(RegionalReceivingOfficeResolver::class);

    expect($resolver->resolve('bms', $report, $source))->toBe('PENRO Davao Oriental');

    $mismatchedArea = finalArchiveArea($creator, 'CENRO Baganga', 'Mismatched source office PA');
    $mismatchedReport = BmsReportSubmission::create([
        'protected_area_id' => $mismatchedArea->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Mismatched source office must fail closed',
        'document_type' => 'Report', 'semester' => '1st Semester',
    ]);

    expect($resolver->resolve('bms', $mismatchedReport, $source))->toBeNull();
});

test('final receipt belongs to the exact active PENRO focal and never calls the archive provider', function (): void {
    $fixture = finalArchiveReleasedBms();
    $gateway = $fixture['gateway'];
    $uploadCountBeforeReceipt = $gateway->uploadAttempts();
    $archives = app(FinalDocumentArchiveService::class);
    $wrongOffice = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Unknown');
    $wrongCategory = finalArchiveActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $inactive = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental', ['is_active' => false]);
    $unapproved = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental', ['is_approved' => false]);
    $globalReader = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $globalReader->assignRole(\Spatie\Permission\Models\Role::findOrCreate('CDS Admin', 'web'));
    $dateOnly = BmsReportSubmission::create([
        'protected_area_id' => $fixture['report']->protected_area_id,
        'target_office' => 'CENRO Mati', 'activity_name' => 'Date-only release must not qualify',
        'document_type' => 'Report', 'semester' => '1st Semester',
        'date_endorsed_regional' => now()->toDateString(),
    ]);

    $releaseEvent = \App\Models\DocumentRoutingEvent::query()
        ->where('source_type', 'bms')->where('source_id', $fixture['report']->id)
        ->where('event_key', 'released')->latest('id')->firstOrFail();
    $beforeRelease = \Carbon\CarbonImmutable::parse($releaseEvent->occurred_at)
        ->setTimezone('Asia/Manila')->subMinute()->format('Y-m-d H:i:s');

    expect(fn () => $archives->recordReceipt($fixture['penro_focal'], 'bms', $fixture['report']->id, $beforeRelease))
        ->toThrow(ValidationException::class);

    expect(fn () => $archives->recordReceipt($fixture['penro_focal'], 'bms', $dateOnly->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(ValidationException::class);

    expect(fn () => $archives->recordReceipt($wrongOffice, 'bms', $fixture['report']->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(HttpException::class);
    expect(fn () => $archives->recordReceipt($wrongCategory, 'bms', $fixture['report']->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(HttpException::class);
    expect(fn () => $archives->recordReceipt($globalReader, 'bms', $fixture['report']->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(HttpException::class);
    expect(fn () => $archives->recordReceipt($inactive, 'bms', $fixture['report']->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(HttpException::class);
    expect(fn () => $archives->recordReceipt($unapproved, 'bms', $fixture['report']->id, now()->addMinute()->toDateTimeString()))
        ->toThrow(HttpException::class);

    $physicallyReceivedAt = now('Asia/Manila')->addMinutes(2)->format('Y-m-d H:i:s');
    $receipt = $archives->recordReceipt($fixture['penro_focal'], 'bms', $fixture['report']->id, $physicallyReceivedAt);
    $duplicate = $archives->recordReceipt($fixture['penro_focal'], 'bms', $fixture['report']->id, now('Asia/Manila')->addMinutes(5)->format('Y-m-d H:i:s'));

    expect($duplicate->id)->toBe($receipt->id)
        ->and(FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe(1)
        ->and($gateway->uploadAttempts())->toBe($uploadCountBeforeReceipt)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe($fixture['events_before']);
});

test('final archive survives a lost provider response, retries by exact identity, and protects scoped downloads', function (): void {
    $fixture = finalArchiveReleasedBms('Final archive retry fixture');
    $gateway = $fixture['gateway'];
    $dispatchUploadCount = $gateway->uploadAttempts();
    $gateway->throwAfterNextUpload();
    $archives = app(FinalDocumentArchiveService::class);
    $physicallyReceivedAt = now('Asia/Manila')->addMinutes(2)->format('Y-m-d H:i:s');
    $archives->recordReceipt($fixture['penro_focal'], 'bms', $fixture['report']->id, $physicallyReceivedAt);

    expect(fn () => $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
        [UploadedFile::fake()->createWithContent('regional-return.pdf', "%PDF-1.7\nReturned signed BMS report")], false,
    ))->toThrow(ValidationException::class);
    expect(FinalDocumentArchiveManifest::query()->count())->toBe(0)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount);
    expect(fn () => $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
        [], true,
    ))->toThrow(ValidationException::class);
    expect(FinalDocumentArchiveManifest::query()->count())->toBe(0)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount);
    $unsupportedSourceFile = finalArchiveSpreadsheetUpload('not-supported-by-bms.xlsx');
    try {
        expect(fn () => $archives->createOrRetryManifest(
            $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
            [$unsupportedSourceFile], true,
        ))->toThrow(ValidationException::class);
    } finally {
        if (is_file($unsupportedSourceFile->getPathname())) @unlink($unsupportedSourceFile->getPathname());
    }
    expect(FinalDocumentArchiveManifest::query()->count())->toBe(0)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount);

    $firstAttempt = $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
        [
            UploadedFile::fake()->createWithContent('returned-a.pdf', "%PDF-1.7\nReturned signed BMS copy A"),
            UploadedFile::fake()->createWithContent('returned-b.pdf', "%PDF-1.7\nReturned signed BMS copy B"),
        ], true,
    );
    $manifest = FinalDocumentArchiveManifest::query()->with('files')->firstOrFail();
    expect($firstAttempt['status'])->toBe('failed')
        ->and($manifest->archive_status)->toBe('FAILED')
        ->and($manifest->files)->toHaveCount(2)
        ->and($manifest->files->first()->file_status)->toBe('FAILED')
        ->and($manifest->files->pluck('logical_identity')->all())->toBe([
            'regional-return-mov:part:00001', 'regional-return-mov:part:00002',
        ])
        ->and(strlen((string) $manifest->signature_set_sha256))->toBe(64)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount + 1);

    $retry = $archives->retry($fixture['penro_focal'], (int) $manifest->getKey());
    $manifest->refresh();
    $file = $manifest->files()->firstOrFail();
    expect($retry['status'])->toBe('archived')
        ->and($manifest->archive_phase)->toBe(FinalDocumentArchiveManifest::PHASE)
        ->and($manifest->archive_status)->toBe('ARCHIVED')
        ->and($manifest->signature_confirmation)->toBeTrue()
        ->and((int) $manifest->signature_confirmed_by)->toBe((int) $fixture['penro_focal']->id)
        ->and($manifest->files)->toHaveCount(2)
        ->and($manifest->files->every(fn ($item) => $item->file_status === 'ARCHIVED'))->toBeTrue()
        ->and($file->file_status)->toBe('ARCHIVED')
        ->and($file->document_archive_id)->not->toBeNull()
        ->and($file->original_filename)->toBe('returned-a.pdf')
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount + 2)
        ->and(array_values($gateway->mimeTypes()))->toBe(['application/pdf', 'application/pdf'])
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe($fixture['events_before']);

    $duplicate = $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
        [
            UploadedFile::fake()->createWithContent('returned-b.pdf', "%PDF-1.7\nReturned signed BMS copy B"),
            UploadedFile::fake()->createWithContent('returned-a.pdf', "%PDF-1.7\nReturned signed BMS copy A"),
        ], true,
    );
    expect($duplicate['status'])->toBe('archived')
        ->and(FinalDocumentArchiveManifest::query()->count())->toBe(1)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount + 2);
    expect(fn () => $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(),
        [UploadedFile::fake()->createWithContent('returned-a.pdf', "%PDF-1.7\nA different returned copy")], true,
    ))->toThrow(ValidationException::class);
    expect(FinalDocumentArchiveManifest::query()->count())->toBe(1)
        ->and($gateway->uploadAttempts())->toBe($dispatchUploadCount + 2);

    $this->actingAs($fixture['penro_focal'])
        ->get(route('final-archives.index', ['source' => 'bms', 'record' => $fixture['report']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SubmissionTracking/Archives')
            ->where('selected.source', 'bms')
            ->where('selected.latest_manifest.status', 'ARCHIVED'));
    $this->get(route('final-archives.files.show', $file->getKey()).'?preview=1')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('final-archives.files.show', $manifest->files->last()->getKey()).'?download=1')->assertOk();

    $sameOfficeReader = finalArchiveActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    expect($archives->authorizeManifestFileReader($sameOfficeReader, $file)->getKey())->toBe($file->document_archive_id);
    $globalReader = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $globalReader->assignRole(\Spatie\Permission\Models\Role::findOrCreate('CDS Admin', 'web'));
    expect($archives->authorizeManifestFileReader($globalReader, $file)->getKey())->toBe($file->document_archive_id);
    $trackingOnly = User::factory()->create(['is_active' => true, 'is_approved' => true]);
    $trackingOnly->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));
    expect($archives->canBrowse($trackingOnly))->toBeFalse();
    ModuleDefinition::query()->firstOrCreate(['code' => 'automated_weather_station'], [
        'name' => 'Automated Weather Stations', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
    $reportId = (int) $fixture['report']->getKey();
    $sameIdAws = Aws::query()->forceCreate([
        'id' => $reportId,
        'protected_area_id' => $fixture['report']->protected_area_id,
        'target_office' => 'CENRO Mati', 'activity_name' => 'Equal ID cross-source denial',
        'station_name' => 'Equal ID AWS', 'location' => 'Mati', 'status' => 'Active',
    ]);
    expect((int) $sameIdAws->getKey())->toBe($reportId);
    $manifest->forceFill(['source_type' => 'aws'])->saveOrFail();
    expect(fn () => $archives->authorizeManifestFileReader($sameOfficeReader, $file->fresh()))
        ->toThrow(HttpException::class);
    $manifest->forceFill(['source_type' => 'bms'])->saveOrFail();
    $file->forceFill(['original_filename' => 'changed-after-confirmation.pdf'])->saveOrFail();
    expect(fn () => $archives->authorizeManifestFileReader($sameOfficeReader, $file->fresh()))
        ->toThrow(HttpException::class);

    $foreignReader = finalArchiveActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    expect(fn () => $archives->authorizeManifestFileReader($foreignReader, $file))
        ->toThrow(HttpException::class);
    $this->actingAs($foreignReader)->get(route('final-archives.files.show', $file->getKey()).'?download=1')->assertNotFound();

    $forged = finalArchiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    expect(fn () => $archives->context($forged, 'management-plans', $fixture['report']->id))
        ->toThrow(HttpException::class);
    expect(fn () => $archives->context($forged, 'bms', 999999))
        ->toThrow(ModelNotFoundException::class);
});

test('eligible PENRO focal sees released records awaiting receipt while foreign PA scopes and inactive modules stay hidden', function (): void {
    $fixture = finalArchiveReleasedBms('Final archive receipt queue fixture');
    $gateway = $fixture['gateway'];
    $focal = $fixture['penro_focal'];
    $uploadAttempts = $gateway->uploadAttempts();
    $routingEvents = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count();

    $this->actingAs($focal)->get(route('final-archives.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SubmissionTracking/Archives')
            ->where('awaitingReceipt.total', 1)
            ->where('awaitingReceipt.data.0.can_receive_final', true));
    expect($gateway->uploadAttempts())->toBe($uploadAttempts)
        ->and(FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe(0)
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->id)->count())->toBe($routingEvents);

    $foreignCenroFocal = finalArchiveActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $archives = app(FinalDocumentArchiveService::class);
    expect(fn () => $archives->context($foreignCenroFocal, 'bms', $fixture['report']->id))
        ->toThrow(HttpException::class);
    $this->actingAs($foreignCenroFocal)
        ->get(route('final-archives.index', ['source' => 'bms', 'record' => $fixture['report']->id]))
        ->assertNotFound();

    ModuleDefinition::query()->where('code', 'bms')->update(['is_active' => false]);
    expect(fn () => $archives->context($focal, 'bms', $fixture['report']->id))
        ->toThrow(ModelNotFoundException::class);
});

test('partial multi-file failure retains verified objects and retries only unfinished files', function (): void {
    $fixture = finalArchiveReleasedBms('Final archive partial file fixture');
    $gateway = $fixture['gateway'];
    $baselineAttempts = $gateway->uploadAttempts();
    $gateway->rejectUploadAtAttempt($baselineAttempts + 2);
    $archives = app(FinalDocumentArchiveService::class);
    $archives->recordReceipt(
        $fixture['penro_focal'], 'bms', $fixture['report']->id,
        now('Asia/Manila')->addMinutes(2)->format('Y-m-d H:i:s'),
    );

    $files = [
        UploadedFile::fake()->createWithContent('part-a.pdf', "%PDF-1.7\nA"),
        UploadedFile::fake()->createWithContent('part-b.pdf', "%PDF-1.7\nB"),
    ];
    $first = $archives->createOrRetryManifest(
        $fixture['penro_focal'], 'bms', $fixture['report']->id, (string) Str::uuid(), $files, true,
    );
    $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')->firstOrFail();
    expect($first['status'])->toBe('failed')
        ->and($manifest->archive_status)->toBe('FAILED')
        ->and($manifest->files->pluck('file_status')->all())->toBe(['ARCHIVED', 'FAILED'])
        ->and($gateway->uploadAttempts())->toBe($baselineAttempts + 2);

    $verifiedFirstObject = $manifest->files->first()->archive?->google_drive_file_id;
    $retry = $archives->retry($fixture['penro_focal'], (int) $manifest->getKey());
    $manifest->refresh();
    $manifest->load('files.archive');
    expect($retry['status'])->toBe('archived')
        ->and($manifest->files->pluck('file_status')->all())->toBe(['ARCHIVED', 'ARCHIVED'])
        ->and($manifest->files->first()->archive?->google_drive_file_id)->toBe($verifiedFirstObject)
        ->and($gateway->uploadAttempts())->toBe($baselineAttempts + 3);
});
