<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Deadline alert email (Phase 5.7).
 *
 * Sent through the configured mail driver (MAIL_MAILER) — log/array in
 * local/test, SMTP/SES/Mailgun later. No external provider is required
 * to run the platform today.
 */
class DeadlineReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  reminder_id, job_id, job_title, company, deadline, notification_days
     */
    public function __construct(
        public string $recipientName,
        public array $data,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Job Application Deadline Is Coming',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.deadline-reminder',
            with: [
                'recipientName' => $this->recipientName,
                'position' => (string) ($this->data['job_title'] ?? 'a role'),
                'company' => (string) ($this->data['company'] ?? 'the company'),
                'deadline' => $this->formatDeadline($this->data['deadline'] ?? null),
            ],
        );
    }

    /**
     * "2026-10-10" → "10 October 2026" (spec example format).
     */
    private function formatDeadline(mixed $deadline): string
    {
        if (! is_string($deadline) || $deadline === '') {
            return 'an upcoming date';
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $deadline);

        return $parsed === false
            ? $deadline
            : $parsed->format('j F Y');
    }
}
