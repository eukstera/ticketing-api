<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;
    
    protected $table = 'tickets';

    protected $fillable = [
        'status_id',
        'priority_id',
        'assigned_user',
        'ticket_number',
        'department',
        'status'
    ];

    public function dateActivities()
    {
        return $this->hasMany(TicketDateActivity::class);
    }

    public function assignedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn($q) => $q->where('category', 'Assigned Date'))
            ->with('user');
    }

    public function completedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn($q) => $q->where('category', 'Completed Date'))
            ->with('user');
    }

    public function requestedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn($q) => $q->where('category', 'Requested Date'))
            ->with('user');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class, 'ticket_id');
    }
}
