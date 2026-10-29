<?php

namespace App\Http\Controllers;

use App\Http\Requests\FalseAlarmRequest;
use App\Models\DateCategory;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Rejects a request as a false alarm, before or after it was taken: a final
 * status with the reason recorded on the ticket timeline. The assignee and
 * Assigned Date stay as history until the ticket is reopened.
 */
class FalseAlarmController extends Controller
{
    use ApiResponseTrait;

    public const STATUS = 'False Alarm';

    public const CATEGORY = 'False Alarm Date';

    public function __invoke(FalseAlarmRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('markFalseAlarm', $ticket);

        $falseAlarmStatusId = Status::where('label', self::STATUS)->value('id');
        $categoryId = DateCategory::where('category', self::CATEGORY)->value('id');

        if ($falseAlarmStatusId === null || $categoryId === null) {
            return $this->error('The False Alarm status is not set up. Run the ReferenceDataSeeder.', 500);
        }

        $openStatusIds = Status::whereIn('label', ['Open', 'In-progress', 'Overdue', 'Blank'])->pluck('id')->all();

        $result = DB::transaction(function () use ($request, $ticket, $falseAlarmStatusId, $categoryId, $openStatusIds) {
            // Lock the row and re-check, so a double click or a racing Take/Complete has one winner.
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->status_id === (int) $falseAlarmStatusId) {
                return 'This ticket has already been marked as a false alarm.';
            }

            if (! in_array((int) $locked->status_id, array_map('intval', $openStatusIds), true)) {
                return 'Only open, in-progress or blank tickets can be marked as a false alarm.';
            }

            $activity = TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $categoryId,
                'user_id' => $request->user()->id,
                'remarks' => trim($request->validated('reason')),
                'status' => 1,
            ]);

            $locked->update(['status_id' => $falseAlarmStatusId]);

            return $activity;
        });

        if (is_string($result)) {
            return $this->error($result, 409);
        }

        $user = $request->user();

        return $this->success('Ticket marked as false alarm', [
            'result' => [
                'ticket' => $ticket->fresh(),
                'false_alarm' => [
                    'reason' => $result->remarks,
                    'marked_by' => ['id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
                    'marked_at' => $result->created_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}
