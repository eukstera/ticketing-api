<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketConfirmation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the technician and the department head that a requester disputed a completion.
 */
class TicketDisputedMail extends Mailable
{
    public function __construct(public Ticket $ticket, public TicketConfirmation $confirmation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ticket {$this->ticket->ticket_number} was disputed by the requester");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-disputed',
            with: [
                'number' => $this->ticket->ticket_number,
                'reason' => $this->confirmation->dispute_reason,
            ],
        );
    }
}
