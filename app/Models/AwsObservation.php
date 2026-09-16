<?php

namespace App\Models;

/**
 * Daily imported weather data. This intentionally extends Aws so existing
 * weather calculation/presentation code remains compatible during migration.
 */
class AwsObservation extends Aws
{
    protected $table = 'aws_observations';

    protected $appends = [];

    protected $fillable = [
        'legacy_aws_id', 'protected_area_id', 'station_name', 'location', 'status',
        'report_period_type', 'start_date', 'end_date', 'timestamps',
        'precipitation', 'wind_direction', 'wind_speed', 'air_temperature',
        'relative_humidity', 'atmospheric_pressure', 'remarks',
        'observation_count', 'expected_observations', 'data_completeness',
        'port2_precipitation', 'port2_max_precipitation_rate', 'port3_water_content',
        'port3_soil_temperature', 'port3_ec', 'rainfall_difference_mm',
        'rainfall_difference_percent', 'rainfall_crosscheck_days',
        'rainfall_crosscheck_status', 'soil_condition_context',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'protected_area_id' => 'integer',
            'observation_count' => 'integer',
            'expected_observations' => 'integer',
            'data_completeness' => 'float',
        ];
    }
}
