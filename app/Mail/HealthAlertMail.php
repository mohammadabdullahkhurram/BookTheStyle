<?php

namespace App\Mail;

use App\Models\Salon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Green→red health alert to the agency's owners/admins: checks that were
 * passing on the previous run and are failing now, in plain language, with
 * a link to the salon's health-check page. Agency staff only — this mail
 * never goes to salon staff or clients.
 */
class HealthAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{key: string, label: string, message: string, was: string}>  $regressions
     */
    public function __construct(
        public string $recipientName,
        public Salon $salon,
        public array $regressions,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans_choice(
                'Health alert — :salon: 1 check started failing|Health alert — :salon: :count checks started failing',
                count($this->regressions),
                ['salon' => $this->salon->name, 'count' => count($this->regressions)],
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.health-alert',
            with: [
                'name' => $this->recipientName,
                'salon' => $this->salon,
                'regressions' => $this->regressions,
                'healthUrl' => route('salon.check-connections', $this->salon),
            ],
        );
    }
}
