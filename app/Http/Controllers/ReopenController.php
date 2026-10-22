<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReopenRequest;
use App\Models\DateCategory;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Puts a false-alarm ticket back to Open. The earlier timeline entries stay
 * as history and the SLA clock restarts from the new Reopened Date entry.
 */
class ReopenController extends Controller
{
    use ApiResponseTrait;

    public const CATEGORY = 'Reopened Date';

    public function __invoke(ReopenRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('reopen', $ticket);

        $openId = Status::where('label', 'Open')->value('id');
        $falseAlarmId = Status::where('label', FalseAlarmController::STATUS)->value('id');
        $categoryId = DateCategory::where('category', self::CATEGORY)->value('id');

        if ($openId === null || $falseAlarmId === null || $categoryId === null) {
            return $this->error('The Reopened Date category is not set up. Run the ReferenceDataSeeder.', 500);
        }

        $reason = $request->filled('reason') ? trim($request->validated('reason')) : null;

        $result = DB::transaction(function () use ($request, $ticket, $openId, $falseAlarmId, $categoryId, $reason) {
            // Lock the row and re-check, so a double click has one winner.
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status_id === (int) $openId) {
                return 'This ticket is already open.';
            }

            if ((int) $locked->status_id !== (int) $falseAlarmId) {
                return 'Only false-alarm tickets can be reopened.';
            }

            $activity = TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $categoryId,
                'user_id' => $request->user()->id,
                'remarks' => $reason,
                'status' => 1,
            ]);

            $locked->update(['status_id' => $openId]);

            return $activity;
        });

        if (is_string($result)) {
            return $this->error($result, 409);
        }

        $user = $request->user();

        return $this->success('Ticket reopened', [
            'result' => [
                'ticket' => $ticket->fresh(),
                'reopened' => [
                    'reason' => $result->remarks,
                    'reopened_by' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
                    'reopened_at' => $result->created_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}
