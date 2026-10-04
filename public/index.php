<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$cdsPerfHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$cdsPerfHost = preg_replace('/:\d+$/', '', $cdsPerfHost);
$cdsLocalPerfOptIn = $cdsPerfHost === 'cds-system.test'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && ($_GET['__cds_perf'] ?? null) === '1';
$cdsLocalPerfBootstrap = $cdsLocalPerfOptIn ? [
    'front_controller_started' => hrtime(true),
] : null;

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
if ($cdsLocalPerfOptIn) {
    $cdsLocalPerfBootstrap['autoload_started'] = hrtime(true);
}
require __DIR__.'/../vendor/autoload.php';
if ($cdsLocalPerfOptIn) {
    $cdsLocalPerfBootstrap['autoload_finished'] = hrtime(true);
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

if ($cdsLocalPerfOptIn) {
    $cdsLocalPerfBootstrap['app_constructed'] = hrtime(true);
    $GLOBALS['cds_local_navigation_bootstrap_timing'] = $cdsLocalPerfBootstrap;
    $app->booted(static function (): void {
        if (isset($GLOBALS['cds_local_navigation_bootstrap_timing'])) {
            $GLOBALS['cds_local_navigation_bootstrap_timing']['framework_booted'] = hrtime(true);
        }
    });
}

$app->handleRequest(Request::capture());
