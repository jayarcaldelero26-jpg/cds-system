<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManagementPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('management-plans.create') ?? false;
    }

    public function rules(): array
    {
        return $this->managementPlanRules();
    }

    protected function managementPlanRules(): array
    {
        return [
            'protected_area_id' => ['required', 'integer', Rule::exists('protected_areas', 'id')->whereNull('deleted_at')],
            'plan_type' => ['required', Rule::in(['PAMP', 'EMP', 'CEPA', 'ECC', 'CNC', 'Other'])],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:100'],
            'prepared_year' => ['required', 'integer', 'min:1800', 'max:'.(now()->year + 10)],
            'approval_date' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['Draft', 'Active', 'Expired', 'For Updating', 'Archived'])],
            'remarks' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ];
    }
}
