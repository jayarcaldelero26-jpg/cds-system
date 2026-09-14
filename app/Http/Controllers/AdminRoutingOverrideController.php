<?php

namespace App\Http\Controllers;

use App\Services\SubmissionTracking\AdminRoutingOverrideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class AdminRoutingOverrideController extends Controller
{
    public function __construct(private readonly AdminRoutingOverrideService $overrides) {}

    public function options(Request $request, string $source, int $record, GenerateVerificationOptions $generate): JsonResponse
    {
        abort_unless($this->overrides->canUse($request->user()), 403);
        $available = $this->overrides->available($source, $record, $request->user());
        if (! $request->user()->hasPasskeysEnabled()) return response()->json(['message' => 'Register a passkey in Security settings before using Administrative Override.'], 422);
        abort_unless($available['available'], 422);
        $options = $generate($request->user());
        $request->session()->put('submission_tracking.override_passkey_options', WebAuthn::toJson($options));
        $request->session()->put('submission_tracking.override_context', ['source' => $source, 'record' => $record, 'stage' => $available['current_stage']]);
        return response()->json(['options' => WebAuthn::toBrowserArray($options), 'override' => $available]);
    }

    public function execute(Request $request, string $source, int $record): JsonResponse
    {
        abort_unless($this->overrides->canUse($request->user()), 403);
        $data = $request->validate([
            'action' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ]);
        if (trim($data['reason']) === '') throw ValidationException::withMessages(['reason' => 'An override reason is required.']);
        $context = $request->session()->pull('submission_tracking.override_context');
        $serialized = $request->session()->pull('submission_tracking.override_passkey_options');
        if (! is_array($context) || $context['source'] !== $source || (int) $context['record'] !== $record || ! is_string($serialized)) throw ValidationException::withMessages(['credential' => 'Passkey verification expired. Start the override again.']);
        try {
            $options = WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);
            $credential = WebAuthn::fromJson(json_encode($data['credential'], JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['credential' => 'Invalid passkey verification data.']);
        }
        $override = $this->overrides->execute($source, $record, $data['action'], $request->user(), $data['reason'], $credential, $options);
        return response()->json(['message' => 'Administrative override recorded.', 'override_id' => $override->id]);
    }
}