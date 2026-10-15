<?php

declare(strict_types=1);

require __DIR__.'/Support/TestDatabaseIsolationGuard.php';

$projectRoot = dirname(__DIR__);
$violations = \Tests\Support\TestDatabaseIsolationGuard::violations($projectRoot);

if ($violations !== []) {
    fwrite(
        STDERR,
        '[CDS test database isolation guard] blocked before application bootstrap: '.implode(', ', $violations).PHP_EOL,
    );

    exit(78);
}

require $projectRoot.'/vendor/autoload.php';
