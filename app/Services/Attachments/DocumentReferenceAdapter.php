<?php

namespace App\Services\Attachments;

use Illuminate\Database\Eloquent\Model;

/** Resolves one logical document slot without exposing its persistence shape to the lifecycle engine. */
interface DocumentReferenceAdapter
{
    /** @return array{path:?string,filename:?string,token:string} */
    public function read(Model $record, string $logicalSlot): array;

    public function write(Model $record, string $logicalSlot, string $path, string $filename): void;

    public function clear(Model $record, string $logicalSlot): void;
}
