<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one email the Admin screens send: a heading, a few lines, and optionally a secret code or a button.
 * Replies go to the Admin's own address (SK_ADMIN_REPLY_TO), so the applicant can just answer the email.
 */
class SkNotice extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $lines,
        public ?string $code = null,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $reply = config('sk.admin_reply_to');

        return new Envelope(
            subject: $this->subjectLine,
            replyTo: filled($reply) ? [new Address($reply, config('sk.admin_name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice');
    }
}
