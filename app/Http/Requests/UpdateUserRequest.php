<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Services\Authorization\OrganizationalAccessService;

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

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

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
            'office_designated' => ['required', 'string', 'max:255'], // 🚀 Gidugang validation
            'section' => ['required', 'string', 'in:CENRO_RECORDS,CENRO_CDS_CHIEF,CENRO_CDS_FOCAL,PENRO_CDS_CHIEF,PENRO_CDS_FOCAL,PAMO'],
            'unit_assignment' => ['required', 'string', 'in:conservation,development'],
            'protected_area_id' => ['nullable', 'integer', 'exists:protected_areas,id', 'required_if:section,PAMO'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            try {
                app(OrganizationalAccessService::class)->validateAssignment($this->input('unit_assignment'), $this->input('section'), $this->input('office_designated'), $this->input('protected_area_id'));
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) foreach ($messages as $message) $validator->errors()->add($key, $message);
            }
        });
    }
}
