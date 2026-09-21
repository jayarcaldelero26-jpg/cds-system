<?php

namespace App\Http\Requests;

use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(app(OrganizationalAccessService::class)->normalizeAssignment($this->all()));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'account_role' => ['sometimes', 'required', 'string', Rule::in([OrganizationalAccessService::ACCOUNT_ROLE_USER, OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN])],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(['no_role'])],
            'operational_group' => ['required', 'string', 'in:cenro,penro'],
            'office_designated' => ['nullable', 'string', 'max:255'],
            'section' => ['required', 'string', 'in:CENRO_RECORDS,PENRO_RECORDS,OFFICE_OF_THE_PENRO,PENRO_TSD_CHIEF,CENRO_CDS_CHIEF,CENRO_CDS_FOCAL,PENRO_CDS_CHIEF,PENRO_CDS_FOCAL'],
            'unit_assignment' => ['nullable', 'string', 'in:conservation,development'],
            'protected_area_id' => ['nullable', 'integer', 'exists:protected_areas,id'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            try {
                $data = app(OrganizationalAccessService::class)->normalizeAssignment($this->all());
                app(OrganizationalAccessService::class)->validateAssignment(
                    $data['unit_assignment'] ?? null,
                    $data['section'] ?? null,
                    $data['office_designated'] ?? null,
                    $data['protected_area_id'] ?? null,
                    null,
                    $data['operational_group'] ?? null,
                );
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) $validator->errors()->add($key, $message);
                }
            }
        });
    }
}