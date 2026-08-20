<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProtectedAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('protected-areas.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $core = $this->input('core_zone_hectares');
        $buffer = $this->input('buffer_zone_hectares');

        $hasCore = $core !== null && $core !== '';
        $hasBuffer = $buffer !== null && $buffer !== '';

        if ($hasCore || $hasBuffer) {
            $coreValue = $hasCore ? (float) $core : 0.0;
            $bufferValue = $hasBuffer ? (float) $buffer : 0.0;

            $this->merge([
                'area_hectares' => number_format($coreValue + $bufferValue, 2, '.', ''),
            ]);
        }
    }

    public function rules(): array
    {
        return $this->rulesForProtectedArea();
    }

    protected function rulesForProtectedArea(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'string', 'max:150'],
            'municipality' => ['required', 'string', 'max:150'],
            'province' => ['required', 'string', 'max:150'],
            'region' => ['required', 'string', 'max:150'],

            'area_hectares' => ['nullable', 'numeric', 'min:0'],

            'core_zone_hectares' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'buffer_zone_hectares' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'pamo' => ['nullable', 'string', 'max:255'],
            'pasu' => ['nullable', 'string', 'max:255'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:' . (now()->year + 10)],
            'legal_basis' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['Active', 'Inactive', 'Proposed'])],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
