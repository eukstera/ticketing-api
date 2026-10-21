<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommentPolicy
{
    public function update(User $user, Comment $comment): Response
    {
        return (int) $comment->user_id === $user->id
            ? Response::allow()
            : Response::deny('You can only edit your own comments.');
    }
}
