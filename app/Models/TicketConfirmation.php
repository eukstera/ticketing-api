<?php

namespace App\Models;

use Database\Factories\TicketConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One completion of a ticket and how the requester answered it.
 */
class TicketConfirmation extends Model
{
    /** @use HasFactory<TicketConfirmationFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DISPUTED = 'disputed';

    public const AUTO_CLOSED = 'auto_closed';

    public const HEAD_CLOSED = 'head_closed';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_TRACK = 'track';

    public const CHANNEL_HEAD = 'head';

    public const CHANNEL_AUTO = 'auto';

    protected $table = 'ticket_confirmations';

    protected $fillable = [
        'ticket_id',
        'completed_by',
        'completion_notes',
        'completed_at',
        'expires_at',
        'requester_email',
        'token_hash',
        'state',
        'channel',
        'responded_at',
        'responder_ip',
        'responder_user_agent',
        'dispute_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function survey(): HasOne
    {
        return $this->hasOne(TicketSurvey::class);
    }

    public function mailLogs(): HasMany
    {
        return $this->hasMany(TicketMailLog::class);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function hasExpired(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }
}
