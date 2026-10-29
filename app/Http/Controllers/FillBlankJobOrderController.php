<?php

namespace App\Http\Controllers;

use App\Http\Requests\FillBlankJobOrderRequest;
use App\Models\DateCategory;
use App\Models\Device;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use App\Models\TypeOfConcern;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enters the handwritten details of a blank job order into the same ticket and
 * number. The ticket becomes In-progress, still assigned to the member who
 * printed the form (or to the member a Department Head hands it to), and its
 * SLA clock starts at the requested time written on the paper.
 */
class FillBlankJobOrderController extends Controller
{
    use ApiResponseTrait;

    public function __invoke(FillBlankJobOrderRequest $request, Ticket $ticket): JsonResponse
    {
        $blankId = Status::where('label', 'Blank')->value('id');
        $categories = DateCategory::whereIn('category', ['Requested Date', 'Assigned Date', 'Filled In Date'])->pluck('id', 'category');

        if ($blankId === null || $categories->count() < 3) {
            return $this->error('The Blank status is not set up. Run the ReferenceDataSeeder.', 500);
        }

        $assignee = $request->filled('assigned_user') ? (int) $request->input('assigned_user') : null;
        $user = $request->user();

        $conflict = DB::transaction(function () use ($request, $ticket, $blankId, $categories, $assignee, $user) {
            // Lock the row and re-check, so two people filling the same form have one winner.
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status_id !== (int) $blankId) {
                return 'Only a blank job order can be filled in.';
            }

            $requestedAt = Carbon::parse($request->input('requested_at'));

            $concern = TypeOfConcern::firstOrCreate(['label' => $request->input('type_of_concern')]);

            TicketIT::query()->where('ticket_id', $locked->id)->update([
                'type_of_concern' => $concern->id,
                'name_of_area' => $request->input('name_of_area'),
                'remarks' => $request->input('remarks'),
                'area_supervisor' => $request->input('area_supervisor'),
            ]);

            $devices = array_map(fn ($device) => [
                'ticket_id' => $locked->id,
                'device' => $device['type'],
                'qty' => $device['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $request->input('devices') ?? []);

            if ($devices !== []) {
                Device::insert($devices);
            }

            TicketDateActivity::unguarded(fn () => TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $categories['Requested Date'],
                // The requester is the person written on the paper; who entered it is on the Filled In Date entry.
                'requestor' => $request->input('requestor'),
                'status' => 1,
                'created_at' => $requestedAt,
                'updated_at' => $requestedAt,
            ]));

            TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $categories['Filled In Date'],
                'user_id' => $user->id,
                'status' => 1,
            ]);

            // The printer stays the owner; a Department Head may hand it to another member.
            $owner = $assignee ?? $locked->assigned_user ?? $user->id;

            TicketDateActivity::updateOrCreate(
                ['ticket_id' => $locked->id, 'date_category_id' => $categories['Assigned Date']],
                ['user_id' => $owner, 'status' => 1],
            );

            $locked->update([
                'status_id' => Status::where('label', 'In-progress')->value('id'),
                'priority_id' => $request->input('priority'),
                'assigned_user' => $owner,
                'requester_email' => $request->input('requestor_email'),
            ]);

            return null;
        });

        if ($conflict !== null) {
            return $this->error($conflict, 409);
        }

        return $this->success('Blank job order filled in', ['result' => ['ticket' => $ticket->fresh()]]);
    }
}
