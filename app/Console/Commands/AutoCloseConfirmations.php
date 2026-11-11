<?php

namespace App\Console\Commands;

use App\Models\TicketConfirmation;
use App\Services\CompletionConfirmationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 01:00 (Asia/Manila). Closes tickets whose completion the requester never
 * confirmed or reported as not fixed within TICKET_AUTOCLOSE_DAYS, via CompletionConfirmationService.
 */
#[Signature('tickets:auto-close-confirmations')]
#[Description('Close tickets whose completion nobody confirmed or disputed within TICKET_AUTOCLOSE_DAYS')]
class AutoCloseConfirmations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CompletionConfirmationService $confirmations): int
    {
        $closed = 0;

        TicketConfirmation::query()
            ->where('state', TicketConfirmation::PENDING)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (TicketConfirmation $confirmation) use ($confirmations, &$closed) {
                if ($confirmations->autoClose($confirmation)) {
                    $closed++;
                }
            });

        $this->info("Auto-closed {$closed} ticket(s).");

        return self::SUCCESS;
    }
}
