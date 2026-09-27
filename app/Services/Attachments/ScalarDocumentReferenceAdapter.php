<?php

namespace App\Services\Attachments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ScalarDocumentReferenceAdapter implements DocumentReferenceAdapter
{
    public function __construct(private readonly string $pathColumn, private readonly ?string $filenameColumn = null) {}

    public function read(Model $record, string $logicalSlot): array
    {
        $this->assertFields($record);
        $path = $record->getAttribute($this->pathColumn);
        $filename = $this->filenameColumn ? $record->getAttribute($this->filenameColumn) : null;

        return [
            'path' => is_string($path) && $path !== '' ? $path : null,
            'filename' => is_string($filename) && $filename !== '' ? $filename : null,
            'token' => hash('sha256', serialize([$path, $filename])),
        ];
    }

    public function write(Model $record, string $logicalSlot, string $path, string $filename): void
    {
        $this->assertFields($record);
        $record->setAttribute($this->pathColumn, $path);
        if ($this->filenameColumn) $record->setAttribute($this->filenameColumn, $filename);
    }

    public function clear(Model $record, string $logicalSlot): void
    {
        $this->assertFields($record);
        $record->setAttribute($this->pathColumn, null);
        if ($this->filenameColumn) $record->setAttribute($this->filenameColumn, null);
    }

    private function assertFields(Model $record): void
    {
        $schema = Schema::connection($record->getConnectionName());
        if (! $schema->hasColumn($record->getTable(), $this->pathColumn)
            || ($this->filenameColumn && ! $schema->hasColumn($record->getTable(), $this->filenameColumn))) {
            throw ValidationException::withMessages(['attachment' => 'The document reference is not supported.']);
        }
    }
}
