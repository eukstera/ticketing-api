<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Ticket;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('viewComments', $ticket)) {
            return $this->forbidden();
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

    public function store(StoreCommentRequest $request, Ticket $ticket): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('comment', $ticket)) {
            return $this->forbidden();
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

    public function updateComment(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        if (Gate::forUser($request->user())->denies('update', $comment)) {
            return $this->forbidden('You can only edit your own comments.');
        }

        $comment->update(['comment' => $request->validated('comment')]);
        $comment->load('user:id,first_name,last_name');

        return $this->success('Comment updated', [
            'result' => [
                'comment' => (new CommentResource($comment))->resolve($request),
            ],
        ]);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $isAuthor = (int) $comment->user_id === $user->id;

        if (! $isAuthor && Gate::forUser($user)->denies('admin')) {
            return $this->forbidden('Only the author or an admin can delete this comment.');
        }

        $comment->delete();

        return $this->success('Comment deleted', ['result' => (object) []]);
    }

    private function forbidden(string $message = 'You are not allowed to access this ticket.'): JsonResponse
    {
        return $this->error($message, 403);
    }
}
