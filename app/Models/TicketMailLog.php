<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email of the confirmation flow: pending, sent, failed (will be
 * retried) or gave_up (retries exhausted or no longer needed).
 */
class TicketMailLog extends Model
{
    public const TYPE_CONFIRMATION = 'confirmation';

    public const TYPE_DISPUTE = 'dispute_notice';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const GAVE_UP = 'gave_up';

    protected $table = 'ticket_mail_log';

    protected $fillable = [
        'ticket_id',
        'ticket_confirmation_id',
        'type',
        'to_email',
        'status',
        'attempts',
        'last_error',
        'last_attempt_at',
        'next_attempt_at',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(TicketConfirmation::class, 'ticket_confirmation_id');
    }
}
