<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may edit a comment: only its author. Section Secretaries (read-only) are always denied. Deleting
 * is decided in CommentController (author or admin).
 */
class CommentPolicy
{
    public function update(User $user, Comment $comment): Response
    {
        if ($user->isEncoder()) {
            return Response::deny(TicketPolicy::ENCODER_MESSAGE);
        }

        return (int) $comment->user_id === $user->id
            ? Response::allow()
            : Response::deny('You can only edit your own comments.');
    }
}
