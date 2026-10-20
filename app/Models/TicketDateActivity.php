<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketDateActivity extends Model
{
    protected $table = 'ticket_date_activities';

    protected $fillable = [
        'ticket_id',
        'date_category_id',
        'user_id',
        'requestor',
        'requestorId',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'date_category_id' => 'integer',
            'user_id' => 'integer',
            'requestorId' => 'integer',
            'status' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function dateCategory()
    {
        return $this->belongsTo(DateCategory::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDisplayNameAttribute(): ?string
    {
        if ($this->user) {
            return trim("{$this->user->first_name} {$this->user->last_name}");
        }

        return $this->requestor;
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['user'] = $this->display_name;

        return $array;
    }
}
