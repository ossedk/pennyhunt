<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic actionable alert: a subject, a few plain lines, one link.
 * Used for signal fires, LiveDesk verdict flips, halts on held names and
 * time-exit reminders. Plain and fast — these are trade-desk pages, not
 * newsletters.
 */
class PennyhuntAlert extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subjectLine,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Pennyhunt] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
