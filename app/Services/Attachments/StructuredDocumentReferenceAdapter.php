<?php

namespace App\Services\Attachments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Slot-aware adapter for JSON arrays/maps; writes only the selected key. */
final class StructuredDocumentReferenceAdapter implements DocumentReferenceAdapter
{
    public function __construct(
        private readonly string $field,
        private readonly string $pathKey = 'path',
        private readonly string $filenameKey = 'original_name',
        private readonly string $mimeKey = 'mime_type',
        private readonly string $sizeKey = 'size',
    ) {}

    public function read(Model $record, string $logicalSlot): array
    {
        $this->assertField($record);
        $items = $this->items($record);
        $item = $items[$logicalSlot] ?? null;
        $path = is_string($item) ? $item : (is_array($item) ? ($item[$this->pathKey] ?? $item['file_path'] ?? null) : null);
        $filename = is_array($item) ? ($item[$this->filenameKey] ?? $item['name'] ?? null) : null;

        return [
            'path' => is_string($path) && $path !== '' ? $path : null,
            'filename' => is_string($filename) && $filename !== '' ? $filename : null,
            'token' => hash('sha256', serialize($item)),
        ];
    }

    public function write(Model $record, string $logicalSlot, string $path, string $filename): void
    {
        $this->assertField($record);
        $items = $this->items($record);
        $current = $items[$logicalSlot] ?? null;
        $metadata = is_array($current) ? $current : [];
        $pathKey = array_key_exists('file_path', $metadata) && ! array_key_exists($this->pathKey, $metadata) ? 'file_path' : $this->pathKey;
        $metadata[$pathKey] = $path;
        $metadata[$this->filenameKey] = $filename;
        $absolute = \Illuminate\Support\Facades\Storage::disk(CurrentDocumentReplacementService::DISK)->path($path);
        $metadata[$this->mimeKey] = mime_content_type($absolute) ?: 'application/octet-stream';
        $metadata[$this->sizeKey] = filesize($absolute);
        $items[$logicalSlot] = $metadata;
        $record->setAttribute($this->field, $items);
    }

    public function clear(Model $record, string $logicalSlot): void
    {
        $this->assertField($record);
        $items = $this->items($record);
        unset($items[$logicalSlot]);
        $record->setAttribute($this->field, $items);
    }

    private function items(Model $record): array
    {
        $items = $record->getAttribute($this->field);
        if (is_string($items)) $items = json_decode($items, true);
        return is_array($items) ? $items : [];
    }

    private function assertField(Model $record): void
    {
        if (! Schema::connection($record->getConnectionName())->hasColumn($record->getTable(), $this->field)) {
            throw ValidationException::withMessages(['attachment' => 'The document collection is not supported.']);
        }
    }
}
