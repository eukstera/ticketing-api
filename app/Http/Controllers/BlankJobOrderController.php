<?php

namespace App\Http\Controllers;

use App\Models\DateCategory;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use App\Services\TicketNumberGenerator;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reserves the next ticket number for a blank job order form that is filled in
 * by hand. The ticket starts as "Blank", owned by (assigned to) whoever
 * printed it, and is filled in, voided or rejected as a false alarm later; the
 * number is never given back. The form itself is
 * downloaded from GET /tickets/{ticket}/job-order?format=pdf.
 */
class BlankJobOrderController extends Controller
{
    use ApiResponseTrait;

    public function __invoke(Request $request, TicketNumberGenerator $ticketNumberGenerator): JsonResponse
    {
        Gate::authorize('print-blank-job-order');

        $user = $request->user();
        $departmentId = (int) $user->department_id;

        $blankStatusId = Status::where('label', 'Blank')->value('id');
        $printedCategoryId = DateCategory::where('category', 'Blank JO Printed Date')->value('id');
        $assignedCategoryId = DateCategory::where('category', 'Assigned Date')->value('id');
        $priorityId = Priority::where('department_id', $departmentId)->orderBy('id')->value('id');

        if ($blankStatusId === null || $printedCategoryId === null || $assignedCategoryId === null || $priorityId === null) {
            return $this->error('The Blank status is not set up. Run the ReferenceDataSeeder.', 500);
        }

        $ticket = DB::transaction(function () use ($user, $departmentId, $blankStatusId, $printedCategoryId, $assignedCategoryId, $priorityId, $ticketNumberGenerator) {
            $ticket = Ticket::create([
                'status_id' => $blankStatusId,
                // Replaced by the real priority when the form is filled in.
                'priority_id' => $priorityId,
                'ticket_number' => $ticketNumberGenerator->generate($departmentId),
                'department' => $departmentId,
                // Whoever prints the form owns the ticket.
                'assigned_user' => $user->id,
            ]);

            // Empty details keep the ticket visible to the lists until it is filled in.
            TicketIT::create([
                'ticket_id' => $ticket->id,
                'type_of_concern' => '',
                'name_of_area' => '',
                'remarks' => '',
            ]);

            TicketDateActivity::create([
                'ticket_id' => $ticket->id,
                'date_category_id' => $printedCategoryId,
                'user_id' => $user->id,
            ]);

            TicketDateActivity::create([
                'ticket_id' => $ticket->id,
                'date_category_id' => $assignedCategoryId,
                'user_id' => $user->id,
                'status' => 1,
            ]);

            return $ticket;
        });

        return $this->success('Blank job order reserved', [
            'result' => [
                'ticket' => [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'status' => 'Blank',
                    'assigned_user' => $user->id,
                ],
                'assignee' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
                'job_order_url' => "/api/tickets/{$ticket->id}/job-order?format=pdf",
            ],
        ], 201);
    }
}
