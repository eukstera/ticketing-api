<?php

namespace App\Services;

use App\Models\DateCategory;
use App\Models\NatureOfWork;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketConfirmation;
use App\Models\TicketEngineering;
use App\Models\TicketIT;
use App\Models\TicketSurvey;
use App\Models\TypeOfConcern;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The consolidated report of one handling department: the filtered ticket
 * rows, their KPIs and the breakdowns behind the charts. Resolution time runs
 * from when the SLA clock started (request, or the latest reopen) to the
 * completion; "within SLA" uses the same whole-calendar-day rule as
 * tickets:promote-overdue.
 */
class ConsolidatedReport
{
    private const IT_DEPARTMENT_ID = 1;

    public const MAX_ROWS = 20000;

    /** Unfilled blank job orders are not requests yet, so they stay out of the report. */
    private const UNREPORTED_STATUSES = ['Blank'];

    /**
     * @param  array{from: Carbon, to: Carbon, date_field: string, requesting_area?: list<string>, status_id?: list<int>, priority_id?: list<int>, concern_type_id?: list<int>, staff_id?: list<int>}  $filters
     * @return Collection<int, array<string, mixed>> newest request first
     */
    public function rows(int $departmentId, array $filters): Collection
    {
        $isIt = $departmentId === self::IT_DEPARTMENT_ID;
        $detailsRelation = $isIt ? 'itDetails' : 'engineeringDetails';
        $concernColumn = $isIt ? 'type_of_concern' : 'nature_of_work';
        // A voided blank job order has no request or completion: it is dated by its print or void time instead.
        $basisCategories = DateCategory::whereIn('category', $filters['date_field'] === 'completed' ? ['Completed Date', 'Void Date'] : ['Requested Date', 'Blank JO Printed Date'])->pluck('id')->all();
        $voidIds = Status::where('label', 'Void')->pluck('id')->all();
        $hasDetailFilters = ($filters['priority_id'] ?? []) !== [] || ($filters['concern_type_id'] ?? []) !== [] || ($filters['requesting_area'] ?? []) !== [];

        $concernLabels = $isIt
            ? TypeOfConcern::query()->pluck('label', 'id')
            : NatureOfWork::query()->pluck('label', 'id');

        $tickets = Ticket::query()
            ->where('tickets.department', $departmentId)
            ->whereHas('dateActivities', fn (Builder $q) => $q
                ->whereIn('date_category_id', $basisCategories)
                ->whereBetween('created_at', [$filters['from']->copy()->startOfDay(), $filters['to']->copy()->endOfDay()]))
            ->whereNotIn('status_id', Status::whereIn('label', self::UNREPORTED_STATUSES)->pluck('id')->all())
            // Voided forms have no priority, concern or area, so those filters leave them out.
            ->when($hasDetailFilters, fn (Builder $q) => $q->whereNotIn('status_id', $voidIds))
            ->when($filters['status_id'] ?? [], fn (Builder $q, array $ids) => $q->whereIn('status_id', $ids))
            ->when($filters['priority_id'] ?? [], fn (Builder $q, array $ids) => $q->whereIn('priority_id', $ids))
            ->when($filters['staff_id'] ?? [], fn (Builder $q, array $ids) => $q->whereIn('assigned_user', $ids))
            ->when($filters['concern_type_id'] ?? [], fn (Builder $q, array $ids) => $q->whereHas($detailsRelation, fn (Builder $d) => $d->whereIn($concernColumn, $ids)))
            ->when($filters['requesting_area'] ?? [], fn (Builder $q, array $areas) => $q->whereHas($detailsRelation, fn (Builder $d) => $d->whereIn('name_of_area', $areas)))
            ->with(['ticketStatus', 'priority', 'assignedUser', $detailsRelation, 'dateActivities.dateCategory', 'confirmations.survey'])
            ->limit(self::MAX_ROWS + 1)
            ->get();

        return $tickets
            ->map(fn (Ticket $ticket) => $this->row($ticket, $detailsRelation, $concernColumn, $concernLabels))
            ->filter(fn (array $row) => $this->inRange($row, $filters))
            ->sortByDesc(fn (array $row) => $row['requested_at']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @param  Collection<int|string, string>  $concernLabels
     * @return array<string, mixed>
     */
    private function row(Ticket $ticket, string $detailsRelation, string $concernColumn, Collection $concernLabels): array
    {
        $details = $ticket->{$detailsRelation};
        $activities = $ticket->dateActivities->groupBy(fn ($activity) => $activity->dateCategory?->category);
        $latest = fn (string $category) => $activities->get($category)?->sortByDesc('id')->first()?->created_at;

        $status = $ticket->ticketStatus->label;
        $isVoid = $status === 'Void';
        $voidActivity = $isVoid ? $activities->get('Void Date')?->sortByDesc('id')->first() : null;

        $requestedAt = $isVoid ? $latest('Blank JO Printed Date') : $latest('Requested Date');
        $reopenedAt = $latest('Reopened Date');
        $filledInAt = $latest('Filled In Date');
        if ($reopenedAt !== null && $filledInAt !== null && $reopenedAt->lte($filledInAt)) {
            $reopenedAt = null;
        }
        $clockStart = $reopenedAt !== null && $requestedAt !== null && $reopenedAt->gt($requestedAt) ? $reopenedAt : $requestedAt;

        $isDone = in_array($status, ['Completed', 'Pending Confirmation'], true);
        $completedAt = $isDone ? $latest('Completed Date') : null;

        $slaDays = (int) ($ticket->priority->sla_days ?? 0);
        $minutes = $completedAt !== null && $clockStart !== null ? (int) abs($clockStart->diffInMinutes($completedAt)) : null;
        $withinSla = $completedAt !== null && $clockStart !== null && $slaDays > 0
            ? $completedAt->lt(app(WorkingCalendar::class)->dueAt($clockStart, $slaDays))
            : null;

        $confirmations = $ticket->confirmations->sortBy('id');
        $standing = $isDone ? $confirmations->last() : null;
        $survey = $standing?->survey;
        $score = $survey !== null
            ? round(collect(TicketSurvey::BLOCKS)->avg(fn (string $block) => (int) $survey->{$block}), 2)
            : null;

        $concernId = $details?->{$concernColumn};
        $concern = $concernLabels[$concernId] ?? null;
        if ($concern === 'Others' && $details instanceof TicketEngineering && $details->other_nature_of_work) {
            $concern = $details->other_nature_of_work;
        }

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'requested_at' => $requestedAt,
            'requester' => $isVoid ? null : $activities->get('Requested Date')?->sortBy('id')->first()?->requestor,
            'requesting_area' => $details?->name_of_area,
            'concern_type_id' => $concernId !== null ? (int) $concernId : null,
            'concern_type' => $concern,
            'priority_id' => (int) $ticket->priority_id,
            'priority' => $ticket->priority->level,
            'sla_days' => $slaDays,
            'status_id' => (int) $ticket->status_id,
            'status' => $status,
            'staff_id' => $ticket->assigned_user !== null ? (int) $ticket->assigned_user : null,
            'handled_by' => $ticket->assignedUser ? trim($ticket->assignedUser->first_name.' '.$ticket->assignedUser->last_name) : null,
            'completed_at' => $completedAt,
            'resolution_minutes' => $minutes,
            'within_sla' => $withinSla,
            'is_overdue' => $status === 'Overdue',
            'is_false_alarm' => $status === 'False Alarm',
            'is_void' => $isVoid,
            'void_reason' => $voidActivity?->remarks,
            'voided_at' => $voidActivity?->created_at,
            'survey_score' => $score,
            'survey' => $survey !== null ? collect(TicketSurvey::BLOCKS)->mapWithKeys(fn (string $block) => [$block => (int) $survey->{$block}])->all() : null,
            'disputes' => $confirmations
                ->where('state', TicketConfirmation::DISPUTED)
                ->map(fn (TicketConfirmation $confirmation) => ['reason' => $confirmation->dispute_reason, 'at' => $confirmation->responded_at])
                ->values()
                ->all(),
            'action_taken' => $standing?->completion_notes,
        ];
    }

    /**
     * The completed date of the latest completion decides the "completed" basis
     * (a ticket reopened after an older completion in range no longer counts).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function inRange(array $row, array $filters): bool
    {
        $moment = $filters['date_field'] === 'completed' ? ($row['completed_at'] ?? $row['voided_at']) : $row['requested_at'];

        return $moment !== null
            && $moment->betweenIncluded($filters['from']->copy()->startOfDay(), $filters['to']->copy()->endOfDay());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>, breakdowns: array<string, mixed>, satisfaction: array<string, mixed>}
     */
    public function aggregate(Collection $allRows, int $departmentId, array $filters): array
    {
        // Voided forms count in the total and the status mix only, so the rates are not diluted.
        $voids = $allRows->where('is_void', true);
        $rows = $allRows->where('is_void', false)->values();
        $total = $rows->count();
        $completed = $rows->whereNotNull('completed_at');
        $slaRated = $rows->whereNotNull('within_sla');
        $surveyed = $rows->whereNotNull('survey_score');
        $disputed = $rows->filter(fn (array $row) => $row['disputes'] !== []);
        $falseAlarms = $rows->where('is_false_alarm', true);

        $percent = fn (int|float $part, int|float $whole): ?float => $whole > 0 ? round($part / $whole * 100, 1) : null;
        $average = fn (Collection $values, int $precision = 2): ?float => $values->isEmpty() ? null : round((float) $values->avg(), $precision);

        $summary = [
            'total' => $allRows->count(),
            'voided' => $voids->count(),
            'completed' => $completed->count(),
            'within_sla_percent' => $percent($slaRated->where('within_sla', true)->count(), $slaRated->count()),
            'avg_resolution_minutes' => $average($completed->pluck('resolution_minutes')->filter(fn ($m) => $m !== null), 0),
            'overdue' => $rows->where('is_overdue', true)->count(),
            'false_alarm_rate_percent' => $percent($falseAlarms->count(), $total),
            'false_alarms' => $falseAlarms->count(),
            'avg_satisfaction' => $average($surveyed->pluck('survey_score')),
            'disputes' => $disputed->sum(fn (array $row) => count($row['disputes'])),
        ];

        return [
            'summary' => $summary,
            'breakdowns' => [
                'by_status' => $this->byStatus($allRows),
                'by_priority' => $this->byPriority($rows, $departmentId),
                'by_requesting_area' => $this->countBy($rows, 'requesting_area'),
                'by_concern_type' => $this->countBy($rows, 'concern_type'),
                'monthly' => $this->trend($rows, 'month'),
                'trend' => $this->trend($rows, $this->granularity($filters)),
                'by_staff' => $this->byStaff($rows, $percent, $average),
            ],
            'satisfaction' => $this->satisfaction($rows, $surveyed, $completed, $average),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{id: int, label: string, count: int}>
     */
    private function byStatus(Collection $rows): array
    {
        $counts = $rows->countBy('status_id');

        return Status::query()->whereNotIn('label', self::UNREPORTED_STATUSES)->orderBy('id')->get()
            ->map(fn (Status $status) => ['id' => $status->id, 'label' => $status->label, 'count' => (int) ($counts[$status->id] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{id: int, label: string, count: int}>
     */
    private function byPriority(Collection $rows, int $departmentId): array
    {
        $counts = $rows->countBy('priority_id');

        return Priority::query()->where('department_id', $departmentId)->orderBy('sla_days')->get()
            ->map(fn (Priority $priority) => ['id' => $priority->id, 'label' => $priority->level, 'count' => (int) ($counts[$priority->id] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{name: string, count: int}> biggest first, blanks grouped as "Unspecified"
     */
    private function countBy(Collection $rows, string $key): array
    {
        return $rows
            ->countBy(fn (array $row) => $row[$key] !== null && $row[$key] !== '' ? $row[$key] : 'Unspecified')
            ->sortDesc()
            ->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function granularity(array $filters): string
    {
        $days = $filters['from']->copy()->startOfDay()->diffInDays($filters['to']->copy()->startOfDay()) + 1;

        return match (true) {
            $days <= 31 => 'day',
            $days <= 182 => 'week',
            default => 'month',
        };
    }

    /**
     * Requests and completions per period, with empty periods filled in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{granularity: string, points: list<array{period: string, label: string, requested: int, completed: int}>}
     */
    private function trend(Collection $rows, string $granularity): array
    {
        $key = fn (Carbon $date): string => match ($granularity) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            default => $date->format('Y-m'),
        };

        $requested = $rows->filter(fn ($r) => $r['requested_at'] !== null)->countBy(fn ($r) => $key($r['requested_at']));
        $completed = $rows->filter(fn ($r) => $r['completed_at'] !== null)->countBy(fn ($r) => $key($r['completed_at']));
        $periods = $requested->keys()->merge($completed->keys())->unique()->sort()->values();

        if ($periods->isEmpty()) {
            return ['granularity' => $granularity, 'points' => []];
        }

        $step = ['day' => 'addDay', 'week' => 'addWeek', 'month' => 'addMonth'][$granularity];
        $cursor = Carbon::parse($periods->first().($granularity === 'month' ? '-01' : ''));
        $end = Carbon::parse($periods->last().($granularity === 'month' ? '-01' : ''));
        $points = [];

        while ($cursor->lte($end) && count($points) < 400) {
            $period = $key($cursor);
            $points[] = [
                'period' => $period,
                'label' => match ($granularity) {
                    'day' => $cursor->format('M j'),
                    'week' => 'Wk of '.$cursor->format('M j'),
                    default => $cursor->format('M Y'),
                },
                'requested' => (int) ($requested[$period] ?? 0),
                'completed' => (int) ($completed[$period] ?? 0),
            ];
            $cursor->{$step}();
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>> busiest handler first
     */
    private function byStaff(Collection $rows, callable $percent, callable $average): array
    {
        return $rows->whereNotNull('staff_id')->groupBy('staff_id')
            ->map(function (Collection $group) use ($percent, $average) {
                $rated = $group->whereNotNull('within_sla');
                $minutes = $group->whereNotNull('completed_at')->pluck('resolution_minutes')->filter(fn ($m) => $m !== null);

                return [
                    'staff_id' => $group->first()['staff_id'],
                    'name' => $group->first()['handled_by'],
                    'handled' => $group->count(),
                    'completed' => $group->whereNotNull('completed_at')->count(),
                    'within_sla_percent' => $percent($rated->where('within_sla', true)->count(), $rated->count()),
                    'avg_resolution_minutes' => $average($minutes, 0),
                    'overdue' => $group->where('is_overdue', true)->count(),
                    'false_alarms' => $group->where('is_false_alarm', true)->count(),
                    'avg_satisfaction' => $average($group->whereNotNull('survey_score')->pluck('survey_score')),
                ];
            })
            ->sortByDesc('handled')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function satisfaction(Collection $rows, Collection $surveyed, Collection $completed, callable $average): array
    {
        $distribution = array_fill_keys([1, 2, 3, 4, 5], 0);
        $blocks = array_fill_keys(TicketSurvey::BLOCKS, []);

        foreach ($surveyed as $row) {
            foreach ($row['survey'] as $block => $value) {
                if (isset($distribution[$value])) {
                    $distribution[$value]++;
                }
                $blocks[$block][] = $value;
            }
        }

        return [
            'scale' => '1-5',
            'responses' => $surveyed->count(),
            'response_rate_percent' => $completed->isEmpty() ? null : round($surveyed->count() / $completed->count() * 100, 1),
            'avg_satisfaction' => $average($surveyed->pluck('survey_score')),
            'distribution' => collect($distribution)->map(fn (int $count, int $score) => ['score' => $score, 'count' => $count])->values()->all(),
            'by_block' => collect($blocks)->map(fn (array $values, string $block) => ['block' => $block, 'average' => $values === [] ? null : round(array_sum($values) / count($values), 2)])->values()->all(),
            'by_requesting_area' => $surveyed->groupBy(fn ($row) => $row['requesting_area'] ?: 'Unspecified')
                ->map(fn (Collection $group, string $area) => ['name' => $area, 'responses' => $group->count(), 'average' => $average($group->pluck('survey_score'))])
                ->sortByDesc('responses')->values()->all(),
            'disputes' => $rows->flatMap(fn (array $row) => collect($row['disputes'])->map(fn (array $dispute) => [
                'ticket_number' => $row['ticket_number'],
                'reason' => $dispute['reason'],
                'at' => $dispute['at']?->toIso8601String(),
            ]))->values()->all(),
        ];
    }

    /**
     * The choices for the filter controls of the report's department.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function options(int $departmentId): array
    {
        $isIt = $departmentId === self::IT_DEPARTMENT_ID;
        $detailsModel = $isIt ? TicketIT::class : TicketEngineering::class;

        return [
            'statuses' => Status::query()->whereNotIn('label', self::UNREPORTED_STATUSES)->orderBy('id')->get()->map(fn (Status $s) => ['id' => $s->id, 'name' => $s->label])->all(),
            'priorities' => Priority::query()->where('department_id', $departmentId)->orderBy('sla_days')->get()
                ->map(fn (Priority $p) => ['id' => $p->id, 'name' => $p->level, 'sla_days' => (int) $p->sla_days])->all(),
            'concern_types' => ($isIt ? TypeOfConcern::query() : NatureOfWork::query())->orderBy('label')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->label])->all(),
            'requesting_areas' => $detailsModel::query()
                ->join('tickets', 'tickets.id', '=', (new $detailsModel)->getTable().'.ticket_id')
                ->where('tickets.department', $departmentId)
                ->whereNotNull('name_of_area')->where('name_of_area', '!=', '')
                ->distinct()->orderBy('name_of_area')->pluck('name_of_area')
                ->map(fn (string $area) => ['id' => $area, 'name' => $area])->all(),
            'staff' => User::query()->where('department_id', $departmentId)->where('status', true)
                ->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])->all(),
        ];
    }

    /**
     * One ticket row as the JSON preview shows it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        return [
            'id' => $row['id'],
            'ticket_number' => $row['ticket_number'],
            'requested_at' => $row['requested_at']?->toIso8601String(),
            'requester' => $row['requester'],
            'requesting_area' => $row['requesting_area'],
            'concern_type' => $row['concern_type'],
            'priority' => $row['is_void'] ? null : $row['priority'],
            'status' => $row['status'],
            'handled_by' => $row['handled_by'],
            'completed_at' => $row['completed_at']?->toIso8601String(),
            'resolution_minutes' => $row['resolution_minutes'],
            'within_sla' => $row['within_sla'],
            'is_overdue' => $row['is_overdue'],
            'is_void' => $row['is_void'],
            'void_reason' => $row['void_reason'],
            'voided_at' => $row['voided_at']?->toIso8601String(),
            'survey_score' => $row['survey_score'],
            'disputed' => $row['disputes'] !== [],
            'action_taken' => $row['action_taken'],
        ];
    }
}
