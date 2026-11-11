<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $mailLines
     * @param  array{label:string, url:string}|null  $mailAction
     * @param  array{recipient_name?:string, eyebrow?:string, preheader?:string, footer_note?:string}  $mailOptions
     */
    public function __construct(
        public string $mailSubject,
        public string $mailTitle,
        public array $mailLines = [],
        public ?array $mailAction = null,
        public array $mailOptions = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'title' => $this->mailTitle,
                'lines' => $this->mailLines,
                'action' => $this->mailAction,
                'options' => $this->mailOptions,
                'appName' => config('app.name', 'Amigo Secreto Inclusivo'),
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
