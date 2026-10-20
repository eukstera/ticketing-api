<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class TicketNumberGenerator
{
    /**
     * Generates the next ticket number in the format:
     *   MM-XXX-YYYY   e.g. "06-001-2026"
     *
     * The counter lives in ticket_sequences (one row per year and month,
     * shared by all departments because ticket_number is unique) and is
     * locked with SELECT ... FOR UPDATE, so it works on any database. The
     * department argument is accepted for compatibility but no longer
     * affects the number. Callers must run this inside the same DB::transaction
     * that inserts the ticket: the row lock then holds until the ticket
     * is committed, and a rolled-back insert gives the number back.
     */
    public function generate(?int $department = null): string
    {
        $now = now();

        return DB::transaction(function () use ($now) {
            $sequence = $this->lockSequence((int) $now->format('Y'), (int) $now->format('n'));

            $next = $sequence->last_number + 1;

            DB::table('ticket_sequences')->where('id', $sequence->id)->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

            return $now->format('m').'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT).'-'.$now->format('Y');
        });
    }

    /**
     * Fetches the counter row for update, creating it on first use.
     */
    private function lockSequence(int $year, int $month): object
    {
        $find = fn () => DB::table('ticket_sequences')
            ->where(['year' => $year, 'month' => $month])
            ->lockForUpdate()
            ->first();

        $sequence = $find();

        if (! $sequence) {
            DB::table('ticket_sequences')->insertOrIgnore([
                'year' => $year,
                'month' => $month,
                'last_number' => $this->highestExistingNumber($year, $month),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = $find();
        }

        return $sequence;
    }

    /**
     * Seeds a new counter from tickets created before the counter existed.
     */
    private function highestExistingNumber(int $year, int $month): int
    {
        $monthStr = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return Ticket::where('ticket_number', 'like', "{$monthStr}-%-{$year}")
            ->pluck('ticket_number')
            ->map(fn (string $number) => (int) (explode('-', $number)[1] ?? 0))
            ->max() ?? 0;
    }
}
