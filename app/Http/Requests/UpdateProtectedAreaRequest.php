<?php

namespace App\Http\Requests;

class UpdateProtectedAreaRequest extends StoreProtectedAreaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('protected-areas.update') ?? false;
    }
}
