<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_id' => 'integer',
            'priority_id' => 'integer',
            'assigned_user' => 'integer',
            'department' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function ticketStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id');
    }

    public function handlingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user');
    }

    public function itDetails(): HasOne
    {
        return $this->hasOne(TicketIT::class, 'ticket_id');
    }

    public function engineeringDetails(): HasOne
    {
        return $this->hasOne(TicketEngineering::class, 'ticket_id');
    }

    public function dateActivities()
    {
        return $this->hasMany(TicketDateActivity::class);
    }

    public function assignedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'Assigned Date'))
            ->with('user');
    }

    public function completedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'Completed Date'))
            ->with('user');
    }

    public function requestedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'Requested Date'))
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
