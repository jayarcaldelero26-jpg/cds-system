<?php

namespace App\Services\OfficeTat;

use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;

/** Resolves the current live TAT source families from registered workflows and adapters. */
final class LiveReportSourceRegistry
{
    public function __construct(
        private readonly SubmissionTrackingService $tracking,
        private readonly PamoNativeSourceRegistry $native,
    ) {}

    /** @return list<string> */
    public function native(): array
    {
        $registered = $this->tracking->reportSourceModels();
        $sources = [];

        foreach ($this->native->all() as $workflow => $adapter) {
            if (! $this->native->isEligible($workflow) || ! isset($registered[$adapter['source']])) continue;
            $sources[] = $adapter['source'];
        }

        return array_values(array_unique($sources));
    }

    /** @return list<string> */
    public function all(): array
    {
        $registered = $this->tracking->reportSourceModels();
        $sources = array_values(array_filter(['conservation', 'engp'], fn (string $source): bool => isset($registered[$source])));

        return array_values(array_unique([...$sources, ...$this->native()]));
    }
}