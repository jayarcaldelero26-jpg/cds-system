<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceAlertSetting extends Model
{
    protected $fillable = [
        'alerts_enabled', 'automatic_send_enabled', 'send_time', 'timezone', 'email_subject', 'to_label',
        'attention_line', 'from_line', 'memorandum_subject', 'introductory_text', 'compliance_warning_text',
        'strict_compliance_text', 'signatory_name', 'signatory_position', 'office_name', 'office_address',
        'focal_person_name', 'focal_person_position', 'focal_person_contact', 'do_not_reply_text',
        'system_generated_footer_text', 'sender_display_name', 'fallback_recipient_email', 'fallback_cc_emails',
        'test_recipient_email', 'singleton_key',
    ];

    protected function casts(): array
    {
        return ['alerts_enabled' => 'boolean', 'automatic_send_enabled' => 'boolean', 'fallback_cc_emails' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $settings): void {
            $settings->singleton_key = 1;
            $settings->timezone = 'Asia/Manila';
        });
    }
}
