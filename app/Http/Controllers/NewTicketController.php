<?php

namespace App\Http\Controllers;

use App\Http\Requests\NewTicketsRequest;
use App\Models\DateCategory;
use App\Models\Status;
use App\Models\Ticket;
use App\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cheap read-only feed for the new-ticket alarm: tickets of the caller's own
 * department created after an id cursor.
 */
class NewTicketController extends Controller
{
    use ApiResponseTrait;

    private const IT_DEPARTMENT_ID = 1;

    private const LIMIT = 20;

    private const SUBJECT_LENGTH = 120;

    public function index(NewTicketsRequest $request): JsonResponse
    {
        Gate::authorize('view-new-tickets');

        $departmentId = (int) $request->user()->department_id;

        if (! $request->has('after_id') || $request->input('after_id') === null) {
            try {
                return $this->respond([], (int) Ticket::query()->where('department', $departmentId)->max('id'), false);
            } catch (Throwable $e) {
                report($e);

                return $this->error('The new-ticket feed is unavailable.', 503);
            }
        }

        $afterId = $request->integer('after_id');

        try {
            $rows = $this->newTickets($departmentId, $afterId)->limit(self::LIMIT + 1)->get();

            $hasMore = $rows->count() > self::LIMIT;
            $tickets = $rows->take(self::LIMIT)->map(fn ($row) => $this->present($row))->values()->all();
        } catch (Throwable $e) {
            // The alarm only polls: a failure is logged and the poll answers "nothing new" instead of an error page.
            report($e);

            return $this->respond([], $afterId, false);
        }

        return $this->respond($tickets, $tickets === [] ? $afterId : (int) end($tickets)['id'], $hasMore);
    }

    private function newTickets(int $departmentId, int $afterId): Builder
    {
        $requestedId = DateCategory::where('category', 'Requested Date')->value('id');

        $query = Ticket::query()
            ->join('priority', 'priority.id', 'tickets.priority_id')
            ->join('departments', 'departments.id', 'tickets.department')
            ->leftJoin('ticket_date_activities as req', function ($join) use ($requestedId) {
                $join->on('req.ticket_id', 'tickets.id')->where('req.date_category_id', $requestedId);
            })
            ->where('tickets.department', $departmentId)
            ->where('tickets.id', '>', $afterId)
            ->whereNotIn('tickets.status_id', Status::whereIn('label', ['False Alarm', 'Blank', 'Void'])->pluck('id')->all())
            ->orderBy('tickets.id')
            ->select(
                'tickets.id',
                'tickets.ticket_number',
                'tickets.department as department_id',
                'departments.department',
                'priority.level as priority',
                'req.requestor as requester',
                'tickets.created_at',
            );

        if ($departmentId === self::IT_DEPARTMENT_ID) {
            return $query
                ->leftJoin('tickets_it', 'tickets_it.ticket_id', 'tickets.id')
                ->leftJoin('type_of_concern', 'type_of_concern.id', 'tickets_it.type_of_concern')
                ->addSelect(
                    'tickets_it.name_of_area',
                    'tickets_it.remarks as details',
                    'type_of_concern.label as concern',
                );
        }

        return $query
            ->leftJoin('tickets_engineering', 'tickets_engineering.ticket_id', 'tickets.id')
            ->addSelect('tickets_engineering.name_of_area', 'tickets_engineering.scope_of_work as details');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        // The request text, else the concern type (picked here rather than in SQL, so no collation can break it).
        $subject = trim((string) ($row->details ?? '')) !== '' ? (string) $row->details : trim((string) ($row->concern ?? ''));

        return [
            'id' => (int) $row->id,
            'ticket_number' => $row->ticket_number,
            'department_id' => (int) $row->department_id,
            'department' => $row->department,
            'subject' => $subject === '' ? null : Str::limit($subject, self::SUBJECT_LENGTH, ''),
            'name_of_area' => $row->name_of_area,
            'priority' => $row->priority,
            'requester' => $row->requester,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     */
    private function respond(array $tickets, int $latestId, bool $hasMore): JsonResponse
    {
        return $this->success('New tickets retrieved', [
            'result' => ['tickets' => $tickets, 'latest_id' => $latestId, 'has_more' => $hasMore],
        ]);
    }
}
