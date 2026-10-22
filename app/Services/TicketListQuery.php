<?php

namespace App\Services;

use App\Http\Requests\TicketListRequest;
use App\Models\DateCategory;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the paginated, filtered ticket list and its tab counts for a
 * department, optionally narrowed to the tickets a user requested or handles.
 */
class TicketListQuery
{
    private const IT_DEPARTMENT_ID = 1;

    /**
     * @return array<string, mixed>
     */
    public function run(TicketListRequest $request, int $departmentId, ?int $userId = null): array
    {
        $requestedId = DateCategory::where('category', 'Requested Date')->value('id');

        $rows = $this->base($departmentId, $userId, $requestedId);
        $this->applyFilters($rows, $request, $departmentId, true);
        $this->applySelect($rows, $departmentId);
        $this->applySort($rows, $request);

        $tickets = $rows
            ->with(['dateActivities.dateCategory', 'dateActivities.user', 'requestedActivity', 'assignedActivity', 'completedActivity', 'falseAlarmActivity', 'reopenedActivity', 'devices'])
            ->withCount('comments')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        return [
            'ticket' => $tickets,
            'priority_sla' => Priority::where('department_id', $departmentId)->pluck('sla_days', 'level')->map(fn ($days) => (int) $days)->all(),
            'status_counts' => $this->statusCounts($request, $departmentId, $userId, $requestedId),
            'priority_counts' => $this->priorityCounts($request, $departmentId, $userId, $requestedId),
        ];
    }

    private function base(int $departmentId, ?int $userId, ?int $requestedId): Builder
    {
        return Ticket::query()
            ->join('priority', 'priority.id', 'tickets.priority_id')
            ->join('status', 'status.id', 'tickets.status_id')
            ->join('departments', 'departments.id', 'tickets.department')
            ->leftJoin('ticket_date_activities as req', function ($join) use ($requestedId) {
                $join->on('req.ticket_id', 'tickets.id')->where('req.date_category_id', $requestedId);
            })
            ->leftJoin('users as req_user', 'req_user.id', 'req.user_id')
            ->when(
                $departmentId === self::IT_DEPARTMENT_ID,
                fn (Builder $q) => $q->join('tickets_it', 'tickets_it.ticket_id', 'tickets.id')
                    ->join('type_of_concern', 'type_of_concern.id', 'tickets_it.type_of_concern'),
                fn (Builder $q) => $q->join('tickets_engineering', 'tickets_engineering.ticket_id', 'tickets.id')
                    ->join('nature_of_work', 'nature_of_work.id', 'tickets_engineering.nature_of_work'),
            )
            ->where('tickets.department', $departmentId)
            ->when($userId, fn (Builder $q) => $q->whereIn(
                'tickets.id',
                TicketDateActivity::select('ticket_id')->whereIn('date_category_id', [1, 2])->where('user_id', $userId)
            ));
    }

    private function applySelect(Builder $query, int $departmentId): void
    {
        $query->select(
            'tickets.id as id',
            'tickets.department as department_id',
            'tickets.status_id',
            'tickets.priority_id',
            'tickets.ticket_number',
            'tickets.requester_email',
            'status.label as status',
            'priority.level as priority',
            'priority.sla_days as priority_sla',
            'departments.department',
        );

        if ($departmentId === self::IT_DEPARTMENT_ID) {
            $query->addSelect(
                'tickets_it.name_of_area',
                'tickets_it.area_supervisor',
                'type_of_concern.label as type_of_concern',
                'tickets_it.type_of_tech_support',
                'tickets_it.remarks',
            );

            return;
        }

        $query->addSelect(
            'tickets_engineering.name_of_area',
            'tickets_engineering.area_supervisor',
            'tickets_engineering.scope_of_work',
            DB::raw("CASE WHEN nature_of_work.label = 'Others' THEN tickets_engineering.other_nature_of_work ELSE nature_of_work.label END as nature_of_work"),
        );
    }

    private function applySort(Builder $query, TicketListRequest $request): void
    {
        $direction = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $column = match ($request->input('sort')) {
            'ticket_number' => 'tickets.ticket_number',
            'priority' => 'priority.sla_days',
            'status' => 'status.id',
            default => 'req.created_at',
        };

        $query->orderBy($column, $direction)->orderBy('tickets.id', $direction);
    }

    /**
     * Search, date range, status and priority filters. Status is left out
     * for the tab counts so the badges stay meaningful.
     */
    private function applyFilters(Builder $query, TicketListRequest $request, int $departmentId, bool $withStatus, bool $withPriority = true): void
    {
        $isIt = $departmentId === self::IT_DEPARTMENT_ID;
        $areaColumn = $isIt ? 'tickets_it.name_of_area' : 'tickets_engineering.name_of_area';

        $query
            ->when(trim((string) $request->input('search')) !== '', function (Builder $q) use ($request, $isIt, $areaColumn) {
                $search = trim((string) $request->input('search'));
                $tokens = preg_split('/\s+/', $search);

                $q->where(function (Builder $w) use ($search, $tokens, $isIt, $areaColumn) {
                    $w->where('tickets.ticket_number', 'like', "%{$search}%")
                        ->orWhere($areaColumn, 'like', "%{$search}%");

                    if ($isIt) {
                        $w->orWhere('type_of_concern.label', 'like', "%{$search}%");
                    } else {
                        $w->orWhere('nature_of_work.label', 'like', "%{$search}%")
                            ->orWhere('tickets_engineering.other_nature_of_work', 'like', "%{$search}%");
                    }

                    // Requester: every word must match a name part or the portal requester text.
                    $w->orWhere(function (Builder $r) use ($tokens) {
                        foreach ($tokens as $token) {
                            $r->where(function (Builder $t) use ($token) {
                                $t->where('req_user.first_name', 'like', "%{$token}%")
                                    ->orWhere('req_user.last_name', 'like', "%{$token}%")
                                    ->orWhere('req.requestor', 'like', "%{$token}%");
                            });
                        }
                    });
                });
            })
            ->when($request->filled('date_from'), fn (Builder $q) => $q->where('req.created_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay()))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->where('req.created_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay()));

        if ($withPriority) {
            $query
                ->when($request->filled('priority_id'), fn (Builder $q) => $q->where('tickets.priority_id', $request->integer('priority_id')))
                ->when(! $request->filled('priority_id') && $request->filled('priority'), fn (Builder $q) => $q->where('priority.level', $request->input('priority')));
        }

        if ($withStatus) {
            $query
                ->when($request->filled('status_id'), fn (Builder $q) => $q->where('tickets.status_id', $request->integer('status_id')))
                ->when(! $request->filled('status_id') && $request->filled('status'), fn (Builder $q) => $q->where('status.label', $request->input('status')));
        }
    }

    /**
     * @return array<int, array{id: int|null, label: string, count: int}>
     */
    private function statusCounts(TicketListRequest $request, int $departmentId, ?int $userId, ?int $requestedId): array
    {
        $query = $this->base($departmentId, $userId, $requestedId);
        $this->applyFilters($query, $request, $departmentId, false);

        $counts = $query->selectRaw('tickets.status_id as group_id, COUNT(*) as total')
            ->groupBy('tickets.status_id')
            ->pluck('total', 'group_id');

        $rows = Status::where('status', true)->orderBy('id')->get()
            ->map(fn (Status $status) => ['id' => $status->id, 'label' => $status->label, 'count' => (int) ($counts[$status->id] ?? 0)])
            ->all();

        return [['id' => null, 'label' => 'All', 'count' => (int) $counts->sum()], ...$rows];
    }

    /**
     * @return array<int, array{id: int, level: string, sla_days: int, count: int}>
     */
    private function priorityCounts(TicketListRequest $request, int $departmentId, ?int $userId, ?int $requestedId): array
    {
        $query = $this->base($departmentId, $userId, $requestedId);
        $this->applyFilters($query, $request, $departmentId, false, false);

        $counts = $query->selectRaw('tickets.priority_id as group_id, COUNT(*) as total')
            ->groupBy('tickets.priority_id')
            ->pluck('total', 'group_id');

        return Priority::where('department_id', $departmentId)
            ->where(function ($q) use ($request) {
                // Active priorities, plus any inactive one a filter explicitly selects.
                $q->where('status', true)
                    ->when($request->filled('priority_id'), fn ($w) => $w->orWhere('id', $request->integer('priority_id')))
                    ->when($request->filled('priority'), fn ($w) => $w->orWhere('level', $request->input('priority')));
            })
            ->orderBy('id')->get()
            ->map(fn (Priority $priority) => [
                'id' => $priority->id,
                'level' => $priority->level,
                'sla_days' => (int) $priority->sla_days,
                'count' => (int) ($counts[$priority->id] ?? 0),
            ])
            ->all();
    }
}
