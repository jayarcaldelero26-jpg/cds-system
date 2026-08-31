<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class OverdueComplianceMemorandum extends Mailable
{
    use Queueable, SerializesModels;

    public const SENDER_DISPLAY_NAME = 'Enhanced Digital Alert and Tracking System (eDATS)';
    public const PROTECTED_AREA_OVERDUE_SUBJECT = '⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports';

    /** @param array<int, array<string, mixed>> $groups @param array<string, mixed> $recipient */
    public function __construct(
        public readonly array $groups,
        public readonly array $settings,
        public readonly array $recipient = [],
        public readonly string $subjectPrefix = '',
        public readonly ?string $alertType = null,
        public readonly array $presentation = [],
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->presentation['subject'] ?? ($this->settings['email_subject'] ?? $this->settings['subject']);

        if (($this->presentation['template'] ?? null) === 'protected_area_overdue') {
            $subject = self::PROTECTED_AREA_OVERDUE_SUBJECT;
        }

        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::SENDER_DISPLAY_NAME),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compliance.overdue-memorandum');
    }
}
