<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device line (type and quantity) of an IT hardware ticket.
 */
class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'device',
        'qty',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
