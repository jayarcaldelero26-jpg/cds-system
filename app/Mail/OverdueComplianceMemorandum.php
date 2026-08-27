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

    /** @param array<int, array<string, mixed>> $groups @param array<string, mixed> $recipient */
    public function __construct(
        public readonly array $groups,
        public readonly array $settings,
        public readonly array $recipient = [],
        public readonly string $subjectPrefix = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->settings['sender_display_name'] ?: config('mail.from.name')),
            subject: $this->subjectPrefix.($this->settings['email_subject'] ?? $this->settings['subject']),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compliance.overdue-memorandum');
    }
}
