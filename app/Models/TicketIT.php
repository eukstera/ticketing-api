<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketIT extends Model
{
    use HasFactory;

    protected $table = 'tickets_it';

    protected $fillable = [
        'ticket_id',
        // 'type_of_tech_support',
        'type_of_concern',
        'name_of_area',
        'remarks',
        'area_supervisor',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function concernType(): BelongsTo
    {
        return $this->belongsTo(TypeOfConcern::class, 'type_of_concern');
    }
}
