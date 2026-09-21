<?php

namespace App\Services\Diagnostics;

final class DiagnosticResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $check,
        public readonly string $summary,
        public readonly array $details = [],
        public readonly string $cost = 'cheap',
    ) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'check' => $this->check, 'summary' => $this->summary, 'details' => $this->details, 'cost' => $this->cost, 'read_only' => true];
    }
}
