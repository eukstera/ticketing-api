<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketConfirmation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Asks the requester to confirm that a completed ticket was really resolved.
 */
class TicketCompletionMail extends Mailable
{
    public function __construct(public Ticket $ticket, public TicketConfirmation $confirmation, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Please confirm: ticket {$this->ticket->ticket_number} has been completed");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-completion',
            text: 'emails.ticket-completion-text',
            with: [
                'number' => $this->ticket->ticket_number,
                'notes' => $this->confirmation->completion_notes,
                'url' => $this->url,
                'days' => (int) config('tickets.autoclose_days'),
            ],
        );
    }
}
