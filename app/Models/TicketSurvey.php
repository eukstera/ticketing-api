<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSurvey extends Model
{
    /** @var list<string> */
    public const BLOCKS = ['service', 'assurance', 'reliability', 'communication', 'access_facilities', 'integrity', 'outcome'];

    protected $table = 'ticket_surveys';

    protected $fillable = [
        'ticket_confirmation_id',
        'ticket_id',
        'service',
        'assurance',
        'reliability',
        'communication',
        'access_facilities',
        'integrity',
        'outcome',
        'comments',
        'submitted_at',
        'submitter_ip',
        'submitter_user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(TicketConfirmation::class, 'ticket_confirmation_id');
    }
}
