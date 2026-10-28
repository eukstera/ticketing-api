<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class TicketPolicy
{
    public const ENCODER_MESSAGE = 'Encoders have read-only access.';

    /**
     * Every action that changes a ticket; Encoders may only view and export.
     *
     * @var list<string>
     */
    private const ENCODER_BLOCKED = ['assign', 'take', 'markFalseAlarm', 'complete', 'reopen', 'closeOnBehalf', 'resendConfirmation', 'comment'];

    /**
     * Administrators pass every ticket ability except completing: only the
     * member who took a ticket completes it.
     */
    public function before(User $user, string $ability): Response|bool|null
    {
        if ($user->isEncoder() && in_array($ability, self::ENCODER_BLOCKED, true)) {
            return Response::deny(self::ENCODER_MESSAGE);
        }

        if ($ability === 'complete') {
            return null;
        }

        return Gate::forUser($user)->allows('admin') ? true : null;
    }

    public function view(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket) || $this->isRequester($user, $ticket),
            'You can only view tickets from your department or tickets you requested.'
        );
    }

    /**
     * Department Heads pass in before(); everyone else may only take (see take()).
     */
    public function assign(User $user, Ticket $ticket): Response
    {
        return Response::deny('Only Department Heads can assign tickets. You can only take an untaken ticket yourself.');
    }

    /**
     * A staff member taking an untaken ticket for themselves.
     */
    public function take(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket),
            'You can only take tickets from your own department.'
        );
    }

    public function markFalseAlarm(User $user, Ticket $ticket): Response
    {
        return $this->allowIf(
            $this->isInHandlingDepartment($user, $ticket) || $this->isAssignee($user, $ticket),
            'Only the assignee or members of the handling department can mark a ticket as a false alarm.'
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
            $this->isAssignee($user, $ticket),
            'Only the member who took this ticket can complete it.'
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

    private function isAssignee(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_user !== null && (int) $ticket->assigned_user === (int) $user->id;
    }

    private function isRequester(User $user, Ticket $ticket): bool
    {
        return TicketDateActivity::where('ticket_id', $ticket->id)
            ->where('date_category_id', 1)
            ->where('user_id', $user->id)
            ->exists();
    }
}
