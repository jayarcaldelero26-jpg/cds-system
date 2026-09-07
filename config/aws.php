<?php

return [
    /*
    | The importer records one persisted daily row for each source date and
    | stores the number of unique source timestamps in observation_count.
    | The source export is nominally a 15-minute AWS feed.
    */
    'sampling_interval_minutes' => 15,

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
