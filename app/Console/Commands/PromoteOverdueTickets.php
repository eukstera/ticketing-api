<?php

namespace App\Console\Commands;

use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:promote-overdue')]
#[Description('Mark Open and In-progress tickets as Overdue once their priority SLA (in days) has passed')]
class PromoteOverdueTickets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $statusIds = Status::whereIn('label', ['Open', 'In-progress', 'Overdue'])->pluck('id', 'label');

        if ($statusIds->count() < 3) {
            $this->error('Status lookup rows (Open, In-progress, Overdue) are missing.');

            return self::FAILURE;
        }

        $promoted = 0;

        foreach (Priority::where('sla_days', '>', 0)->get() as $priority) {
            // Same semantics as DATEDIFF(NOW(), requested) >= sla: whole calendar days.
            $cutoff = now()->subDays($priority->sla_days)->endOfDay();

            $promoted += Ticket::where('priority_id', $priority->id)
                ->whereIn('status_id', [$statusIds['Open'], $statusIds['In-progress']])
                ->whereHas('dateActivities', function ($query) use ($cutoff) {
                    $query->where('created_at', '<=', $cutoff)
                        ->whereHas('dateCategory', fn ($category) => $category->where('category', 'Requested Date'));
                })
                // A reopen restarts the clock: skip tickets reopened after the cutoff.
                ->whereDoesntHave('dateActivities', function ($query) use ($cutoff) {
                    $query->where('created_at', '>', $cutoff)
                        ->whereHas('dateCategory', fn ($category) => $category->where('category', 'Reopened Date'));
                })
                ->update(['status_id' => $statusIds['Overdue']]);
        }

        $this->info("Promoted {$promoted} ticket(s) to Overdue.");

        return self::SUCCESS;
    }
}
