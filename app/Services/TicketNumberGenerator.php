<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class TicketNumberGenerator
{
    /**
     * Generates the next ticket number for a department in the format:
     *   MM-XXX-YYYY   e.g. "06-001-2026"
     *
     * The XXX portion is derived directly from tickets.ticket_number —
     * it finds the highest existing number for this department/month/year
     * and adds 1. Resets to 001 automatically each month, since last
     * month's tickets won't match the current MM/YYYY pattern.
     */
    public function generate(int $department): string
    {
        $now = now();
        $monthStr = $now->format('m');   // "06"
        $yearStr = $now->format('Y');    // "2026"

        // MySQL named lock — scoped to this department+month+year so
        // concurrent requests queue instead of racing for the same number.
        // Doesn't require any extra table.
        $lockKey = "ticket_number_{$department}_{$monthStr}_{$yearStr}";
        $acquired = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockKey])->acquired;

        if (!$acquired) {
            throw new \RuntimeException('Could not acquire ticket number lock, please retry.');
        }

        try {
            $lastTicket = Ticket::where('department', $department)
                ->where('ticket_number', 'like', "{$monthStr}-%-{$yearStr}")
                ->orderByRaw("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(ticket_number, '-', 2), '-', -1) AS UNSIGNED) DESC")
                ->first();

            $nextNumber = 1;
            if ($lastTicket) {
                $parts = explode('-', $lastTicket->ticket_number);
                $nextNumber = ((int) $parts[1]) + 1;
            }

            $padded = str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

            return "{$monthStr}-{$padded}-{$yearStr}";
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockKey]);
        }
    }
}
