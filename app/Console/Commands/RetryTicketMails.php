<?php

namespace App\Console\Commands;

use App\Jobs\SendTicketMail;
use App\Models\TicketConfirmation;
use App\Models\TicketMailLog;
use App\Services\CompletionConfirmationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:retry-mail')]
#[Description('Retry confirmation emails that failed or never went out (no queue worker needed)')]
class RetryTicketMails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CompletionConfirmationService $confirmations): int
    {
        $max = (int) config('tickets.mail_max_attempts');
        $sent = 0;
        $skipped = 0;

        TicketMailLog::query()
            ->with('confirmation')
            ->whereIn('status', [TicketMailLog::PENDING, TicketMailLog::FAILED])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->each(function (TicketMailLog $log) use ($confirmations, $max, &$sent, &$skipped) {
                if ($log->attempts >= $max) {
                    $log->update(['status' => TicketMailLog::GAVE_UP, 'next_attempt_at' => null]);
                    $skipped++;

                    return;
                }

                $token = null;

                if ($log->type === TicketMailLog::TYPE_CONFIRMATION) {
                    // The old link token cannot be recovered from its hash, and the first email never arrived, so issue a fresh one.
                    if ($log->confirmation === null || $log->confirmation->state !== TicketConfirmation::PENDING) {
                        $log->update(['status' => TicketMailLog::GAVE_UP, 'next_attempt_at' => null, 'last_error' => 'No longer needed.']);
                        $skipped++;

                        return;
                    }

                    $token = $confirmations->rotateToken($log->confirmation);
                }

                SendTicketMail::dispatchSync($log->id, $token);
                $sent++;
            });

        $this->info("Retried {$sent} email(s); gave up on {$skipped}.");

        return self::SUCCESS;
    }
}
