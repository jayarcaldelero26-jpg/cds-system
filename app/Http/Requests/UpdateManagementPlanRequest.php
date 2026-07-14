<?php

namespace App\Http\Requests;

class UpdateManagementPlanRequest extends StoreManagementPlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('management-plans.update') ?? false;
    }
}
