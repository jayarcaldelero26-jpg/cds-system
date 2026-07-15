<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManagementPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('management-plans.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plan_type' => ['required', 'string', 'in:PAMP,EMP,CEPA,ECC,CNC,Other'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:100'],
            'prepared_year' => ['required', 'integer', 'min:1900', 'max:' . date('Y')],
            'approval_date' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', 'string', 'in:Draft,Active,Expired,For Updating,Archived'],
            'remarks' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'], // Max 20MB // Max 20MB
        ];
    }
}
