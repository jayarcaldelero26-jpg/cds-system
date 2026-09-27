<?php

namespace App\Services\Storage;

interface StorageCapacityProvider
{
    /** @return array{provider:string,status:string,used_bytes:?int,total_bytes:?int,free_bytes:?int,used_percentage:?int,measured_at:?string,source:string,message:?string} */
    public function measure(): array;
}
