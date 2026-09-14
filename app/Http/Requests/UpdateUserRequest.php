<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(app(OrganizationalAccessService::class)->normalizeAssignment($this->all()));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $legacyPamo = $user instanceof User && $user->section === OrganizationalAccessService::PAMO;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'account_role' => ['sometimes', 'required', 'string', Rule::in([OrganizationalAccessService::ACCOUNT_ROLE_USER, OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN])],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(['no_role'])],
            'operational_group' => [Rule::requiredIf(! $legacyPamo), 'nullable', 'string', Rule::in(['cenro', 'penro', ...($legacyPamo ? [OrganizationalAccessService::OPERATIONAL_GROUP_PAMO] : [])])],
            'office_designated' => ['nullable', 'string', 'max:255'],
            'section' => ['required', 'string', Rule::in(['CENRO_RECORDS', 'PENRO_RECORDS', 'OFFICE_OF_THE_PENRO', 'PENRO_TSD_CHIEF', 'CENRO_CDS_CHIEF', 'CENRO_CDS_FOCAL', 'PENRO_CDS_CHIEF', 'PENRO_CDS_FOCAL', ...($legacyPamo ? [OrganizationalAccessService::PAMO] : [])])],
            'unit_assignment' => ['nullable', 'string', 'in:conservation,development'],
            'protected_area_id' => ['nullable', 'integer', 'exists:protected_areas,id'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->route('user')?->section === OrganizationalAccessService::PAMO && $this->input('section') === OrganizationalAccessService::PAMO) return;
            try {
                $data = app(OrganizationalAccessService::class)->normalizeAssignment($this->all());
                app(OrganizationalAccessService::class)->validateAssignment(
                    $data['unit_assignment'] ?? null,
                    $data['section'] ?? null,
                    $data['office_designated'] ?? null,
                    $data['protected_area_id'] ?? null,
                    null,
                );
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) $validator->errors()->add($key, $message);
                }
            }
        });
    }
}
