<?php

namespace App\Jobs;

use App\Mail\TicketCompletionMail;
use App\Mail\TicketDisputedMail;
use App\Models\TicketMailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one email of the confirmation flow and records the outcome in
 * ticket_mail_log. It is dispatched with dispatchAfterResponse(), so it runs in
 * the same process right after the HTTP response and needs no queue worker;
 * because it implements ShouldQueue, switching QUEUE_CONNECTION to a real queue
 * and running a worker later needs no code change. A failure never throws: it
 * is recorded and the scheduler (tickets:retry-mail) tries again later.
 *
 * Note: with a real worker, the link token is part of the queued payload.
 */
class SendTicketMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $mailLogId, public ?string $token = null) {}

    public function handle(): void
    {
        $log = TicketMailLog::query()->with(['ticket', 'confirmation'])->find($this->mailLogId);

        if ($log === null || $log->status === TicketMailLog::SENT) {
            return;
        }

        $log->attempts++;
        $log->last_attempt_at = now();

        try {
            Mail::to($log->to_email)->send($this->mailable($log));

            $log->status = TicketMailLog::SENT;
            $log->sent_at = now();
            $log->next_attempt_at = null;
            $log->last_error = null;
        } catch (Throwable $e) {
            $error = $this->sanitize($e->getMessage());

            $log->last_error = $error;
            $log->status = $log->attempts >= (int) config('tickets.mail_max_attempts') ? TicketMailLog::GAVE_UP : TicketMailLog::FAILED;
            $log->next_attempt_at = $log->status === TicketMailLog::FAILED ? now()->addMinutes($this->backoffMinutes($log->attempts)) : null;

            Log::warning('Ticket confirmation mail failed', ['mail_log_id' => $log->id, 'attempt' => $log->attempts, 'error' => $error]);
        }

        $log->save();
    }

    private function mailable(TicketMailLog $log): TicketCompletionMail|TicketDisputedMail
    {
        if ($log->type === TicketMailLog::TYPE_DISPUTE) {
            return new TicketDisputedMail($log->ticket, $log->confirmation);
        }

        if ($this->token === null) {
            throw new \RuntimeException('The confirmation link token is missing.');
        }

        $base = rtrim((string) config('tickets.requestor_portal_url'), '/');

        if (str_ends_with($base, '.invalid')) {
            Log::warning('REQUESTOR_PORTAL_URL is not set; the confirmation link will not work.', ['mail_log_id' => $log->id]);
        }

        $url = $base.'/confirm/'.$this->token;

        return new TicketCompletionMail($log->ticket, $log->confirmation, $url);
    }

    private function backoffMinutes(int $attempts): int
    {
        $steps = (array) config('tickets.mail_retry_minutes');

        return (int) ($steps[min($attempts, count($steps)) - 1] ?? end($steps) ?: 5);
    }

    /**
     * Keeps secrets out of the log table and the log file.
     */
    private function sanitize(string $message): string
    {
        foreach (array_filter([config('mail.mailers.smtp.password'), config('mail.mailers.smtp.username')]) as $secret) {
            $message = str_replace((string) $secret, '***', $message);
        }

        return Str::limit($message, 500);
    }
}
