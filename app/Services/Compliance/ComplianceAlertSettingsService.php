<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAlertSetting;
use Illuminate\Support\Arr;

class ComplianceAlertSettingsService
{
    public const TIMEZONE = 'Asia/Manila';

    public function testEmailEnabled(): bool
    {
        return (bool) config('compliance_alerts.test_email_enabled');
    }

    /** @return array<string, mixed> */
    public function effective(): array
    {
        $defaults = config('compliance_alerts');
        $record = ComplianceAlertSetting::query()->where('singleton_key', 1)->first();

        if (! $record) {
            return $this->normalise($defaults);
        }

        $stored = Arr::only($record->toArray(), (new ComplianceAlertSetting)->getFillable());

        return $this->normalise(array_replace($defaults, array_filter($stored, fn ($value) => $value !== null)));
    }

    public function record(): ComplianceAlertSetting
    {
        return ComplianceAlertSetting::query()->firstOrCreate(['singleton_key' => 1], [
            'alerts_enabled' => false,
            'automatic_send_enabled' => false,
            'send_time' => config('compliance_alerts.send_time', '08:00'),
            'timezone' => self::TIMEZONE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): ComplianceAlertSetting
    {
        $attributes['timezone'] = self::TIMEZONE;
        $settings = $this->record();
        $settings->fill($attributes)->save();

        return $settings->fresh();
    }

    /** @return array{requires_review:bool,fallback_configured:bool,approved_count:int,current_count:int} */
    public function fallbackCcReviewState(?array $effective = null): array
    {
        $effective ??= $this->effective();
        $approved = $this->normalisedEmailSet(config('compliance_alerts.approved_fallback_cc_emails', []));
        $current = $this->normalisedEmailSet($effective['fallback_cc_emails'] ?? []);
        $fallbackConfigured = filter_var($effective['fallback_recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) !== false;

        return [
            'requires_review' => $fallbackConfigured && $current !== $approved,
            'fallback_configured' => $fallbackConfigured,
            'approved_count' => count($approved),
            'current_count' => count($current),
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function normalise(array $settings): array
    {
        $settings['alerts_enabled'] = $settings['alerts_enabled'] ?? $settings['enabled'] ?? false;
        $settings['automatic_send_enabled'] = $settings['automatic_send_enabled'] ?? $settings['alerts_enabled'];
        $settings['fallback_cc_emails'] = $this->emails($settings['fallback_cc_emails'] ?? $settings['cc_recipients'] ?? []);
        $settings['fallback_recipient_email'] = trim((string) ($settings['fallback_recipient_email'] ?? ''));
        if ($settings['fallback_recipient_email'] === '') {
            $settings['fallback_recipient_email'] = $this->emails($settings['recipients'] ?? [])[0] ?? '';
        }
        $settings['sender_display_name'] = $settings['sender_display_name'] ?? $settings['sender_name'] ?? config('mail.from.name');
        $settings['email_subject'] = $settings['email_subject'] ?? $settings['subject'];
        $settings['attention_line'] = $settings['attention_line'] ?? $settings['attention'];
        $settings['from_line'] = $settings['from_line'] ?? $settings['from'];
        $settings['timezone'] = self::TIMEZONE;

        return $settings;
    }

    /** @return array<int, string> */
    private function emails(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $values)));
    }

    /** @return array<int, string> */
    private function normalisedEmailSet(mixed $value): array
    {
        $emails = array_values(array_unique(array_map('strtolower', $this->emails($value))));
        sort($emails);

        return $emails;
    }
}
