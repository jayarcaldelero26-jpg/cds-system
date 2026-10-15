<?php

namespace App\Services\Attachments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Resolves only a server-staged returned final copy, never a source row's working document. */
final class StagedFinalDocumentAdapter implements DocumentReferenceAdapter
{
    public function __construct(private readonly string $path, private readonly string $filename) {}

    public function read(Model $record, string $logicalSlot): array
    {
        return ['path' => $this->path, 'filename' => $this->filename, 'token' => $this->path];
    }

    public function write(Model $record, string $logicalSlot, string $path, string $filename): void
    {
        throw new LogicException('Returned final copies are immutable staged files.');
    }

    public function clear(Model $record, string $logicalSlot): void
    {
        throw new LogicException('Returned final copies are immutable staged files.');
    }
}
