<?php

return [
    'active_protected_area_codes' => [
        'APL',
        'MHRWS',
        'MPL',
        'PBPLS',
    ],

    /*
    | The importer records one persisted daily row for each source date and
    | stores the number of unique source timestamps in observation_count.
    | The source export is nominally a 15-minute AWS feed.
    */
    'sampling_interval_minutes' => 15,

    // These thresholds and labels are the existing Daily AWS import rule.
    // Keep them centralized so summaries and imports interpret weather the
    // same way without coupling that interpretation to data completeness.
    'weather_conditions' => [
        'thresholds' => [
            'moderate_rain_above_mm' => 15.0,
            'heavy_rain_above_mm' => 50.0,
            'strong_wind_above_mps' => 10.0,
            'high_temperature_above_c' => 32.0,
            'cool_temperature_below_c' => 20.0,
        ],
        'labels' => [
            'normal' => 'Normal Weather Conditions',
            'unavailable' => 'Weather Condition Unavailable',
            'heavy_rain' => 'Heavy Rainfall Advisory',
            'moderate_rain' => 'Moderate Rain Observed',
            'strong_wind' => 'Strong Wind Alert',
            'high_temperature' => 'High Temperature',
            'cool_temperature' => 'Cool Conditions',
            'variable' => 'Variable Weather Conditions',
        ],
    ],

    'monthly_summary' => [
        'regular_completeness_minimum' => 95.0,
        'insufficient_completeness_maximum' => 50.0,
        'number_decimals' => 2,
        'vapor_pressure_decimals' => 3,
        'metric_ranges' => [
            'atmospheric_pressure' => [0.0, 200.0],
            'air_temperature' => [-80.0, 80.0],
            'relative_humidity' => [0.0, 100.0],
            'precipitation' => [0.0, null],
            'wind_speed' => [0.0, 100.0],
            'wind_direction' => [0.0, 360.0],
        ],
    ],
];
