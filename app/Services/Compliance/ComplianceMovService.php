<?php

namespace App\Services\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ComplianceMovService
{
    public const MESSAGE = 'An MOV / supporting document is required.';

    public function hasValidSingleFile(Model $record, string $pathField): bool
    {
        $path = $record->getAttribute($pathField);

        return is_string($path) && trim($path) !== '' && Storage::disk('public')->exists($path);
    }

    /** @param array<int, mixed> $attachments */
    public function hasValidAttachments(array $attachments, array $removedPaths = []): bool
    {
        foreach ($attachments as $attachment) {
            $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
            if (is_string($path) && ! in_array($path, $removedPaths, true) && Storage::disk('public')->exists($path)) {
                return true;
            }
        }

        return false;
    }

    public function requireValidSingleFile(Model $record, string $pathField, string $inputField): void
    {
        if (! $this->hasValidSingleFile($record, $pathField)) {
            throw ValidationException::withMessages([$inputField => self::MESSAGE]);
        }
    }

    public function requireValidAttachments(Model $record, array $removedPaths, string $inputField = 'attachments'): void
    {
        if (! $this->hasValidAttachments($record->attachments ?? [], $removedPaths)) {
            throw ValidationException::withMessages([$inputField => 'At least one supporting document is required.']);
        }
    }
}
