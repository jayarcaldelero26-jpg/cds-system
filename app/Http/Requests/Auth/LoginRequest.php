<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginAttemptLimiter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $limiter = app(LoginAttemptLimiter::class);
        $key = $limiter->ensureAllowed($this, $this->string('email')->toString());

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $limiter->hit($key);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $limiter->clear($key);
    }
}
