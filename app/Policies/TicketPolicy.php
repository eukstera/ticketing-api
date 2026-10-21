<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class TicketPolicy
{
    /**
     * Administrators pass every ticket ability.
     */
    public function before(User $user): ?bool
    {
        return Gate::forUser($user)->allows('admin') ? true : null;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->isInHandlingDepartment($user, $ticket)
            || $this->isRequester($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->isInHandlingDepartment($user, $ticket);
    }

    public function complete(User $user, Ticket $ticket): bool
    {
        return (int) $ticket->assigned_user === $user->id
            || $this->isInHandlingDepartment($user, $ticket);
    }

    public function viewComments(User $user, Ticket $ticket): bool
    {
        return $this->isInHandlingDepartment($user, $ticket)
            || $this->isRequester($user, $ticket);
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->viewComments($user, $ticket);
    }

    private function isInHandlingDepartment(User $user, Ticket $ticket): bool
    {
        return $ticket->department !== null
            && (int) $ticket->department === (int) $user->department_id;
    }

    private function isRequester(User $user, Ticket $ticket): bool
    {
        return TicketDateActivity::where('ticket_id', $ticket->id)
            ->where('date_category_id', 1)
            ->where('user_id', $user->id)
            ->exists();
    }
}
