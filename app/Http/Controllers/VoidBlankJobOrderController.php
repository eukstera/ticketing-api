<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidBlankJobOrderRequest;
use App\Models\DateCategory;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Voids an unused or spoiled blank job order. The ticket number stays used and
 * the ticket shows as Void; only a Blank ticket can be voided.
 */
class VoidBlankJobOrderController extends Controller
{
    use ApiResponseTrait;

    public function __invoke(VoidBlankJobOrderRequest $request, Ticket $ticket): JsonResponse
    {
        $blankId = Status::where('label', 'Blank')->value('id');
        $voidId = Status::where('label', 'Void')->value('id');
        $categoryId = DateCategory::where('category', 'Void Date')->value('id');

        if ($blankId === null || $voidId === null || $categoryId === null) {
            return $this->error('The Void status is not set up. Run the ReferenceDataSeeder.', 500);
        }

        $result = DB::transaction(function () use ($request, $ticket, $blankId, $voidId, $categoryId) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status_id === (int) $voidId) {
                return 'This ticket is already void.';
            }

            if ((int) $locked->status_id !== (int) $blankId) {
                return 'Only a blank job order can be voided.';
            }

            $activity = TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $categoryId,
                'user_id' => $request->user()->id,
                'remarks' => $request->validated('reason'),
                'status' => 1,
            ]);

            $locked->update(['status_id' => $voidId]);

            return $activity;
        });

        if (is_string($result)) {
            return $this->error($result, 409);
        }

        $user = $request->user();

        return $this->success('Blank job order voided', [
            'result' => [
                'ticket' => $ticket->fresh(),
                'void' => [
                    'reason' => $result->remarks,
                    'voided_by' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
                    'voided_at' => $result->created_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}
