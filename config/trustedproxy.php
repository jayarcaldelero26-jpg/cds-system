<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Use an exact comma-separated list of proxy IP addresses or CIDRs in the
    | deployment environment. Leave null for direct/local access. Keeping this
    | in configuration makes it available when Laravel configuration is cached.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),
];
