<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Illuminate\Auth\Access\Response;
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

    public function view(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket) || $this->isRequester($user, $ticket),
            'You can only view tickets from your department or tickets you requested.'
        );
    }

    public function assign(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket),
            'You can only assign tickets in your department, to members of that department.'
        );
    }

    public function markFalseAlarm(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket),
            'Only members of the handling department can mark a ticket as a false alarm.'
        );
    }

    public function downloadJobOrder(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket),
            'Only members of the handling department can download a job order.'
        );
    }

    /**
     * Department Heads pass in before(); nobody else may close for the requester.
     */
    public function closeOnBehalf(User $user, Ticket $ticket): Response
    {
        return Response::deny("Only Department Heads can close a ticket on the requester's behalf.");
    }

    public function resendConfirmation(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket),
            'Only members of the handling department can resend the confirmation email.'
        );
    }

    /**
     * Department Heads pass in before(); nobody else may reopen.
     */
    public function reopen(User $user, Ticket $ticket): Response
    {
        return Response::deny('Only Department Heads can reopen a ticket.');
    }

    public function complete(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            (int) $ticket->assigned_user === $user->id || $this->isInHandlingDepartment($user, $ticket),
            'Only the assignee or members of the handling department can complete this ticket.'
        );
    }

    public function viewComments(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket) || $this->isRequester($user, $ticket),
            'You can only view or comment on tickets from your department or tickets you requested.'
        );
    }

    public function comment(User $user, Ticket $ticket): Response
    {
        return $this->viewComments($user, $ticket);
    }

    private function allowIf(bool $allowed, string $message): Response
    {
        return $allowed ? Response::allow() : Response::deny($message);
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
