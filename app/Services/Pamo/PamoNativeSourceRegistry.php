<?php

namespace App\Services\Pamo;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ModuleDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class PamoNativeSourceRegistry
{
    /** @var array<string, array{source:string, attachment:string, column:string, model:class-string<Model>, file_field:string, label:string}> */
    private const WORKFLOWS = [
        'bms' => ['source' => 'bms', 'attachment' => 'bms-report', 'column' => 'bms_report_submission_id', 'model' => BmsReportSubmission::class, 'file_field' => 'mov', 'label' => 'BMS'],
        'bams' => ['source' => 'bams', 'attachment' => 'bams-report', 'column' => 'bams_report_submission_id', 'model' => BamsReportSubmission::class, 'file_field' => 'mov', 'label' => 'BAMS'],
        'imea' => ['source' => 'imea', 'attachment' => 'imea-report', 'column' => 'imea_report_submission_id', 'model' => ImeaReportSubmission::class, 'file_field' => 'mov', 'label' => 'IMEA'],
        'imea_facility_maintenance' => ['source' => 'imea-maintenance', 'attachment' => 'imea-maintenance', 'column' => 'imea_facility_maintenance_report_id', 'model' => ImeaFacilityMaintenanceReport::class, 'file_field' => 'mov', 'label' => 'IMEA Facility Maintenance'],
        'automated_weather_station' => ['source' => 'aws', 'attachment' => 'aws', 'column' => 'aws_report_id', 'model' => Aws::class, 'file_field' => 'report_file', 'label' => 'Automated Weather Station'],
        'ipaf_management' => ['source' => 'ipaf-management', 'attachment' => 'ipaf-management', 'column' => 'ipaf_management_report_id', 'model' => IpafManagementReport::class, 'file_field' => 'mov', 'label' => 'Management of IPAF'],
        'revenue_collection' => ['source' => 'revenue', 'attachment' => 'ipaf-revenue', 'column' => 'ipaf_revenue_collection_id', 'model' => IpafRevenueCollection::class, 'file_field' => 'mov', 'label' => 'Revenue Collection'],
    ];

    /** @return array{source:string, attachment:string, column:string, model:class-string<Model>, file_field:string, label:string}|null */
    public function find(string $workflow): ?array
    {
        return self::WORKFLOWS[$workflow] ?? null;
    }

    /** @return array{workflow:string, definition:array}|null */
    public function findBySource(string $sourceKey): ?array
    {
        foreach (self::WORKFLOWS as $workflow => $definition) {
            if ($definition['source'] === $sourceKey) return ['workflow' => $workflow, 'definition' => $definition];
        }
        return null;
    }

    /** @return array<string, array{source:string, attachment:string, column:string, model:class-string<Model>, file_field:string, label:string}> */
    public function all(): array
    {
        return self::WORKFLOWS;
    }

    public function isEligible(string $workflow): bool
    {
        return $this->find($workflow) !== null
            && ModuleDefinition::query()->active()->where('implementation_type', ModuleDefinition::IMPLEMENTATION_SPECIALIZED)->where('requirement_domain', 'pa')->where('code', $workflow)->exists();
    }

    public function isSchemaReady(): bool
    {
        if (! Schema::hasTable('pamo_pamb_drafts')) return false;
        foreach (self::WORKFLOWS as $definition) {
            if (! Schema::hasColumn('pamo_pamb_drafts', $definition['column'])
                || ! Schema::hasTable((new $definition['model'])->getTable())) return false;
        }
        return true;
    }

    public function assertSchemaReady(): void
    {
        abort_unless($this->isSchemaReady(), 503, 'PAMO native report drafts are not installed yet.');
    }

    public function sourceColumn(string $workflow): ?string
    {
        return $this->find($workflow)['column'] ?? null;
    }
}
