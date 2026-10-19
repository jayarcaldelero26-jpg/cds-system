<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginAttemptLimiter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** @throws ValidationException */
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