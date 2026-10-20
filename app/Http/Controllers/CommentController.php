<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Ticket;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Ticket $ticket)
    {
        $comments = Comment::where('ticket_id', $ticket->id)
            ->with('user:id,first_name,last_name')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'message' => 'Comments retreived',
            'result' => [
                'comments' => $comments
            ]
        ], 201);
    }

    public function store(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'comment' => 'required|string'
        ]);

        $comment = Comment::create([
            'ticket_id' => $ticket->id,
            'user_id'   => $request->user()->id,
            'comment'   => $validated['comment']
        ]);

        $comment->load('user:id,first_name,last_name');

        return response()->json([
            'message' => 'Comment added',
            'result' => [
                'comment' => $comment
            ]
        ], 201);
    }

    public function updateComment(Request $request, Comment $comment)
    {
        $validated = $request->validate([
            'comment' => 'required|string|max:2000',
        ]);

        // $comment = Comment::where('ticket_id', $ticketId)->findOrFail($commentId);

        // Only the author can edit their own comment
        if ($comment->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only edit your own comments.',
            ], 200);
        }

        $comment->update([
            'comment' => $validated['comment'],
        ]); 

        return response()->json([
            'result' => [
                'comment' => $comment->fresh()->load('user'),
            ],
        ], 200);
    }
}
