<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ticket comments: list, add, edit, delete. Only the author edits a comment; the author or an admin
 * deletes it. Section Secretaries (read-only) cannot comment.
 */
class CommentController extends Controller
{
    use ApiResponseTrait;

    /**
     * GET /api/tickets/{ticket}/comments. The comments of a ticket the caller may view.
     */
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $response = Gate::forUser($request->user())->inspect('viewComments', $ticket);

        if ($response->denied()) {
            return $this->forbidden($response->message());
        }

        $comments = Comment::where('ticket_id', $ticket->id)
            ->with('user:id,first_name,last_name')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return $this->success('Comments retrieved', [
            'result' => [
                'comments' => CommentResource::collection($comments)->resolve($request),
            ],
        ]);
    }

    /**
     * POST /api/tickets/{ticket}/comments. Adds a comment as the signed-in user.
     */
    public function store(StoreCommentRequest $request, Ticket $ticket): JsonResponse
    {
        $response = Gate::forUser($request->user())->inspect('comment', $ticket);

        if ($response->denied()) {
            return $this->forbidden($response->message());
        }

        $comment = Comment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'comment' => $request->validated('comment'),
        ]);

        $comment->load('user:id,first_name,last_name');

        return $this->success('Comment added', [
            'result' => [
                'comment' => (new CommentResource($comment))->resolve($request),
            ],
        ], 201);
    }

    /**
     * PATCH /api/tickets/comments/{comment}. Edits your own comment.
     */
    public function updateComment(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        $response = Gate::forUser($request->user())->inspect('update', $comment);

        if ($response->denied()) {
            return $this->forbidden($response->message());
        }

        $comment->update(['comment' => $request->validated('comment')]);
        $comment->load('user:id,first_name,last_name');

        return $this->success('Comment updated', [
            'result' => [
                'comment' => (new CommentResource($comment))->resolve($request),
            ],
        ]);
    }

    /**
     * DELETE /api/tickets/comments/{comment}. The author or an admin deletes a comment.
     */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $isAuthor = (int) $comment->user_id === $user->id;

        if ($user->isEncoder()) {
            return $this->forbidden(TicketPolicy::ENCODER_MESSAGE);
        }

        if (! $isAuthor && Gate::forUser($user)->denies('admin')) {
            return $this->forbidden('You can only delete your own comments.');
        }

        $comment->delete();

        return $this->success('Comment deleted', ['result' => (object) []]);
    }

    private function forbidden(?string $message = null): JsonResponse
    {
        return $this->error($message ?? 'You are not allowed to access this ticket.', 403);
    }
}
