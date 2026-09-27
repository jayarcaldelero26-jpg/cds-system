<?php

return [
    // Keep package auto-detection for local development, but never allow an
    // environment override to enable diagnostics in production.
    'enabled' => \App\Support\ProductionSecuritySettings::debugbarEnabled(
        (string) env('APP_ENV', 'production'),
        env('DEBUGBAR_ENABLED'),
    ),
];
