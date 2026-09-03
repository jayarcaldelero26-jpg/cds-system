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
            'regular_pamb' => ['pamb_working_days', 7],
            'special_pamb' => ['pamb_working_days', 7],
            'twc_meetings' => ['pamb_working_days', 7],
            'updating_pamb_manual' => ['pamb_working_days', 7],
            'homestay' => ['standard_working_days', 15],
            'maintenance_buoy' => ['standard_working_days', 15],
            'updating_pamp' => ['standard_working_days', 7],
            'restoration_plan_5_year' => ['standard_working_days', 7],
            'cepa_plan' => ['standard_working_days', 7],
            'monitoring_mangroves_corals_seagrass' => ['standard_working_days', 15],
            'additional_bms_site' => ['calendar_days', 15],
            'ecotourism_management_plan' => ['calendar_days', 7],
            'automated_weather_station' => ['standard_working_days', 7],
            'ipaf_management' => ['standard_working_days', 7],
        ];

        foreach ($rules as $code => [$mode, $days]) {
            DB::table('module_definitions')
                ->where('code', $code)
                ->update([
                    'deadline_mode' => $mode,
                    'default_deadline_days' => $days,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Built-in metadata may have been edited by an administrator after this
        // migration, so it is intentionally not reverted destructively.
    }
};
