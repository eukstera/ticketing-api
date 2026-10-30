<?php

namespace App\Models;

use App\Services\WorkingCalendar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Ticket extends Model
{
    use HasFactory;

    protected $table = 'tickets';

    protected $fillable = [
        'status_id',
        'priority_id',
        'assigned_user',
        'ticket_number',
        'requester_email',
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
            'priority_sla' => 'integer',
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

    /**
     * The latest rejection, and only while the ticket is still a false alarm.
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(TicketConfirmation::class);
    }

    /**
     * The latest completion and how the requester answered it.
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(TicketConfirmation::class)->latestOfMany();
    }

    public function falseAlarmActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'False Alarm Date'))
            ->whereHas('ticket.ticketStatus', fn ($q) => $q->where('label', 'False Alarm'))
            ->orderByDesc('id')
            ->with('user');
    }

    /**
     * The voiding of a blank job order, and only while the ticket is still void.
     */
    public function voidActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'Void Date'))
            ->whereHas('ticket.ticketStatus', fn ($q) => $q->where('label', 'Void'))
            ->orderByDesc('id')
            ->with('user');
    }

    /**
     * The latest reopen, kept as history after the ticket moves on.
     */
    public function reopenedActivity()
    {
        return $this->hasOne(TicketDateActivity::class)
            ->whereHas('dateCategory', fn ($q) => $q->where('category', 'Reopened Date'))
            ->orderByDesc('id')
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

    /**
     * The newest timeline entry of a category, or null.
     */
    public function latestActivity(string $category): ?TicketDateActivity
    {
        return $this->dateActivities
            ->filter(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === $category)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * The SLA clock starts at the request, or at the latest reopen if later.
     */
    public function clockStartedAt(): ?Carbon
    {
        $requestedAt = $this->dateActivities
            ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date')
            ?->created_at;
        $reopenedAt = $this->latestActivity('Reopened Date')?->created_at;

        // A blank job order has no clock until it is filled in, and a reopen
        // from before the fill-in does not restart the paper's request time.
        if ($requestedAt === null) {
            return null;
        }

        $filledInAt = $this->latestActivity('Filled In Date')?->created_at;
        if ($reopenedAt !== null && $filledInAt !== null && $reopenedAt->lessThanOrEqualTo($filledInAt)) {
            $reopenedAt = null;
        }

        return $reopenedAt !== null && $reopenedAt->greaterThan($requestedAt) ? $reopenedAt : $requestedAt;
    }

    /**
     * Working days (Monday to Friday), matching tickets:promote-overdue: a ticket
     * is due at the start of the day sla_days working days after its clock started.
     */
    public function dueAt(): ?Carbon
    {
        return $this->dueAtWithin((int) $this->priority->sla_days);
    }

    /**
     * The due moment for a given number of SLA days, for callers that already have the priority's days.
     */
    public function dueAtWithin(int $slaDays): ?Carbon
    {
        $start = $this->clockStartedAt();

        if ($start === null || $slaDays <= 0) {
            return null;
        }

        return app(WorkingCalendar::class)->dueAt($start, $slaDays);
    }

    /**
     * Whether a ticket in this status counts as overdue now: the Overdue status itself,
     * or an unfinished ticket whose due moment has passed. Finished, rejected,
     * blank and void tickets never are.
     */
    public static function isPastDue(string $statusLabel, ?Carbon $dueAt): bool
    {
        return match ($statusLabel) {
            'Overdue' => true,
            'Completed', 'Pending Confirmation', 'False Alarm', 'Blank', 'Void' => false,
            default => $dueAt !== null && now()->greaterThanOrEqualTo($dueAt),
        };
    }
}
