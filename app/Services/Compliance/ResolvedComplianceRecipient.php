<?php

namespace App\Services\Compliance;

final readonly class ResolvedComplianceRecipient
{
    /** @param array<int, string> $ccEmails */
    public function __construct(
        public string $key,
        public string $email,
        public array $ccEmails,
        public ?string $name,
        public string $source,
        public ?string $attentionLine = null,
        public ?int $mappingId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'email' => $this->email,
            'cc_emails' => $this->ccEmails,
            'name' => $this->name,
            'attention_line' => $this->attentionLine,
            'source' => $this->source,
            'mapping_id' => $this->mappingId,
        ];
    }
}
