<?php

namespace App\Http\Middleware;

use App\Models\Ticket;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOverdueTickets
{
    public function handle(Request $request, Closure $next)
    {
        // Runs before every request that has this middleware applied
        $this->promoteOverdueTickets();

        return $next($request);
    }

    private function promoteOverdueTickets(): void
    {
        Ticket::join('ticket_date_activities as td', function ($join) {
            $join->on('td.ticket_id', 'tickets.id')
                ->where('td.date_category_id', 1);
        })
            ->join('priority', 'priority.id', 'tickets.priority_id')
            ->whereIn('tickets.status_id', [1, 2])
            ->whereRaw('DATEDIFF(NOW(), td.created_at) >= priority.sla')
            ->update(['tickets.status_id' => 4]);
    }
}
