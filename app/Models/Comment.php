<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment on a ticket (comments table). The author is user_id.
 */
class Comment extends Model
{
    use HasFactory;

    protected $table = 'comments';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'comment',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
            'status' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
