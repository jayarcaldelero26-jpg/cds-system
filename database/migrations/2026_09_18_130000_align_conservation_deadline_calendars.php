<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_definitions')) {
            return;
        }

        $rules = [
            'homestay' => ['standard_working_days', 15],
            'maintenance_monuments' => ['standard_working_days', 7],
            'maintenance_buoy' => ['standard_working_days', 15],
            'updating_pamp' => ['standard_working_days', 7],
            'restoration_plan_5_year' => ['standard_working_days', 7],
            'additional_bms_site' => ['standard_working_days', 15],
            'cepa_plan' => ['standard_working_days', 7],
            'vtol_operations' => ['standard_working_days', 7],
            'bdfe_terrestrial' => ['standard_working_days', 7],
            'bdfap' => ['standard_working_days', 7],
            'maintenance_pamo_ecotourism' => ['standard_working_days', 7],
            'rehabilitation_pa_office' => ['standard_working_days', 7],
            'ecotourism_management_plan' => ['standard_working_days', 7],
            'management_effectiveness_assessment' => ['standard_working_days', 7],
            'maintenance_pa_information_system' => ['standard_working_days', 7],
            'monitoring_mangroves_corals_seagrass' => ['standard_working_days', 15],
            'water_quality_monitoring' => ['standard_working_days', 7],
            'mpan' => ['standard_working_days', 7],
            'bms' => ['standard_working_days', 15],
            'bams' => ['standard_working_days', 15],
            'imea' => ['standard_working_days', 15],
            'automated_weather_station' => ['standard_working_days', 7],
            'ipaf_management' => ['standard_working_days', 7],
            'imea_facility_maintenance' => ['standard_working_days', 7],
            'technical_reports' => ['standard_working_days', 7],
        ];

        foreach ($rules as $code => [$mode, $days]) {
            $definition = DB::table('module_definitions')
                ->where('code', $code)
                ->where('requirement_domain', 'pa')
                ->first(['id', 'requirement_metadata']);

            if ($definition === null) {
                continue;
            }

            $metadata = json_decode((string) $definition->requirement_metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];

            if (in_array($code, [
                'homestay',
                'maintenance_monuments',
                'maintenance_buoy',
                'updating_pamp',
                'restoration_plan_5_year',
                'additional_bms_site',
                'cepa_plan',
                'vtol_operations',
                'bdfe_terrestrial',
                'bdfap',
                'maintenance_pamo_ecotourism',
                'rehabilitation_pa_office',
                'ecotourism_management_plan',
                'management_effectiveness_assessment',
                'maintenance_pa_information_system',
                'monitoring_mangroves_corals_seagrass',
                'water_quality_monitoring',
                'mpan',
            ], true)) {
                $metadata['canonical_deadline_mode'] = $mode;
                $metadata['canonical_deadline_days'] = $days;
            }

            DB::table('module_definitions')
                ->where('id', $definition->id)
                ->update([
                    'deadline_mode' => $mode,
                    'default_deadline_days' => $days,
                    'requirement_metadata' => $metadata === [] ? $definition->requirement_metadata : json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // The correction is intentionally not reversed because prior metadata
        // values were incorrect for the authoritative Conservation calendar.
    }
};
