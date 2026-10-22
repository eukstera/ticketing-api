<?php

namespace App\Services;

use App\Jobs\SendTicketMail;
use App\Models\DateCategory;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use App\Models\TicketMailLog;
use App\Models\TicketSurvey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The digital completion flow: a technician completes a ticket with notes, the
 * requester confirms or disputes it (email link or Track Ticket), a Department
 * Head can close it on their behalf, and unanswered tickets close themselves.
 * Every step runs in a transaction on locked rows so double clicks and races
 * have exactly one winner.
 */
class CompletionConfirmationService
{
    public const STATUS_PENDING = 'Pending Confirmation';

    /**
     * Completes a ticket with notes and opens a confirmation for the requester.
     *
     * @return array{0: TicketConfirmation, 1: ?TicketMailLog, 2: string} the confirmation, the email to send (if the ticket has an address) and the plain link token
     */
    public function complete(Ticket $ticket, User $by, string $notes): array
    {
        $token = $this->newToken();

        [$confirmation, $log] = DB::transaction(function () use ($ticket, $by, $notes, $token) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $label = Status::query()->whereKey($locked->status_id)->value('label');

            match ($label) {
                'Completed' => throw new ConfirmationException('The ticket is already completed.'),
                self::STATUS_PENDING => throw new ConfirmationException("This ticket is already waiting for the requester's confirmation."),
                'False Alarm' => throw new ConfirmationException('A false-alarm ticket cannot be completed.'),
                default => null,
            };

            $assignedUser = TicketDateActivity::query()
                ->where('ticket_id', $locked->id)
                ->where('date_category_id', $this->categoryId('Assigned Date'))
                ->value('user_id') ?? $locked->assigned_user;

            if ($assignedUser === null) {
                throw new ConfirmationException('An unassigned ticket cannot be completed.');
            }

            TicketDateActivity::create([
                'ticket_id' => $locked->id,
                'date_category_id' => $this->categoryId('Completed Date'),
                'user_id' => $assignedUser, // completed on behalf of the assigned technician
                'remarks' => $notes,
                'status' => 1,
            ]);

            $locked->update(['status_id' => $this->statusId(self::STATUS_PENDING)]);

            $confirmation = TicketConfirmation::create([
                'ticket_id' => $locked->id,
                'completed_by' => $by->id,
                'completion_notes' => $notes,
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('tickets.autoclose_days')),
                'requester_email' => $locked->requester_email,
                'token_hash' => $this->hash($token),
                'state' => TicketConfirmation::PENDING,
            ]);

            return [$confirmation, $this->logMail($confirmation, TicketMailLog::TYPE_CONFIRMATION, $locked->requester_email)];
        });

        return [$confirmation, $log, $token];
    }

    /**
     * Sends the email once the HTTP response has gone out; no queue worker is needed.
     */
    public function dispatchMail(?TicketMailLog $log, ?string $token = null): void
    {
        if ($log !== null) {
            SendTicketMail::dispatchAfterResponse($log->id, $token);
        }
    }

    public function confirm(TicketConfirmation $confirmation, string $channel, ?Request $request = null): TicketConfirmation
    {
        return DB::transaction(function () use ($confirmation, $channel, $request) {
            [$locked, $ticket] = $this->lockAnswerable($confirmation);

            $locked->update(['state' => TicketConfirmation::CONFIRMED, 'channel' => $channel] + $this->responder($request));
            $ticket->update(['status_id' => $this->statusId('Completed')]);

            $this->record($ticket, 'Confirmed Date', null, 'Confirmed by the requester ('.$channel.')');

            return $locked->refresh();
        });
    }

    /**
     * @return array{0: TicketConfirmation, 1: list<TicketMailLog>} the disputed confirmation and the notices to send
     */
    public function dispute(TicketConfirmation $confirmation, string $channel, string $reason, ?Request $request = null): array
    {
        $logs = [];

        $disputed = DB::transaction(function () use ($confirmation, $channel, $reason, $request, &$logs) {
            [$locked, $ticket] = $this->lockAnswerable($confirmation);

            $locked->update([
                'state' => TicketConfirmation::DISPUTED,
                'channel' => $channel,
                'dispute_reason' => $reason,
            ] + $this->responder($request));
            $ticket->update(['status_id' => $this->statusId('In-progress')]);

            $this->record($ticket, 'Disputed Date', null, $reason);

            foreach ($this->staffToNotify($ticket) as $email) {
                $logs[] = $this->logMail($locked, TicketMailLog::TYPE_DISPUTE, $email);
            }

            return $locked->refresh();
        });

        return [$disputed, $logs];
    }

    /**
     * A Department Head closes a waiting ticket on the requester's behalf.
     */
    public function closeOnBehalf(Ticket $ticket, User $head, string $reason, ?Request $request = null): TicketConfirmation
    {
        return DB::transaction(function () use ($ticket, $head, $reason, $request) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $confirmation = $this->pendingConfirmation($locked);

            $confirmation->update([
                'state' => TicketConfirmation::HEAD_CLOSED,
                'channel' => TicketConfirmation::CHANNEL_HEAD,
            ] + $this->responder($request));
            $locked->update(['status_id' => $this->statusId('Completed')]);

            $this->record($locked, 'Confirmed Date', $head->id, "Closed on the requester's behalf: {$reason}");

            return $confirmation->refresh();
        });
    }

    /**
     * Closes a confirmation nobody answered within the allowed days. Safe to repeat.
     */
    public function autoClose(TicketConfirmation $confirmation): bool
    {
        return DB::transaction(function () use ($confirmation) {
            $locked = TicketConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasExpired()) {
                return false;
            }

            $ticket = Ticket::query()->whereKey($locked->ticket_id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'state' => TicketConfirmation::AUTO_CLOSED,
                'channel' => TicketConfirmation::CHANNEL_AUTO,
                'responded_at' => now(),
            ]);

            if ((int) $ticket->status_id === $this->statusId(self::STATUS_PENDING)) {
                $ticket->update(['status_id' => $this->statusId('Completed')]);
            }

            $this->record($ticket, 'Auto-closed Date', null, 'No response within '.(int) config('tickets.autoclose_days').' days');

            return true;
        });
    }

    /**
     * Issues a fresh link and queues a new email; the previous link stops working.
     *
     * @return array{0: TicketConfirmation, 1: TicketMailLog, 2: string}
     */
    public function resend(Ticket $ticket): array
    {
        return DB::transaction(function () use ($ticket) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $confirmation = $this->pendingConfirmation($locked);

            if ($locked->requester_email === null || $locked->requester_email === '') {
                throw new ConfirmationException('This ticket has no requester email.');
            }

            $token = $this->rotateToken($confirmation);

            return [$confirmation, $this->logMail($confirmation, TicketMailLog::TYPE_CONFIRMATION, $locked->requester_email), $token];
        });
    }

    /**
     * Replaces the stored link token (used by retries, which cannot recover the old one).
     */
    public function rotateToken(TicketConfirmation $confirmation): string
    {
        $token = $this->newToken();
        $confirmation->update(['token_hash' => $this->hash($token)]);

        return $token;
    }

    /**
     * @param  array<string, int>  $ratings
     */
    public function submitSurvey(TicketConfirmation $confirmation, array $ratings, ?string $comments, ?Request $request = null): TicketSurvey
    {
        try {
            return DB::transaction(function () use ($confirmation, $ratings, $comments, $request) {
                $locked = TicketConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->firstOrFail();

                if ($locked->state !== TicketConfirmation::CONFIRMED) {
                    throw new ConfirmationException('Please confirm the service before answering the survey.');
                }

                if ($locked->survey()->exists()) {
                    throw new ConfirmationException('The survey was already submitted.');
                }

                return TicketSurvey::create(array_merge($ratings, [
                    'ticket_confirmation_id' => $locked->id,
                    'ticket_id' => $locked->ticket_id,
                    'comments' => $comments !== null && trim($comments) !== '' ? trim($comments) : null,
                    'submitted_at' => now(),
                    'submitter_ip' => $request?->ip(),
                    'submitter_user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 250, ''),
                ]));
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConfirmationException('The survey was already submitted.');
        }
    }

    /**
     * Finds the confirmation behind an emailed link token.
     */
    public function findByToken(string $token): TicketConfirmation
    {
        $confirmation = preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1
            ? TicketConfirmation::query()->where('token_hash', $this->hash($token))->first()
            : null;

        return $confirmation ?? throw new ConfirmationException('This link is not valid.', 404);
    }

    /**
     * Finds the ticket and its latest confirmation from a ticket number and the requester's name.
     *
     * @return array{0: Ticket, 1: ?TicketConfirmation}
     */
    public function findForRequester(string $ticketNumber, string $requestor): array
    {
        $ticket = Ticket::query()->with('dateActivities.dateCategory')->where('ticket_number', trim($ticketNumber))->first();

        $requested = $ticket?->dateActivities
            ->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date');

        if ($ticket === null || self::normalizeName((string) $requested?->requestor) !== self::normalizeName($requestor)) {
            throw new ConfirmationException('Ticket not found.', 404);
        }

        return [$ticket, $ticket->confirmations()->latest('id')->first()];
    }

    /**
     * Trimmed, single-spaced and lowercased, so "  juan   DELA cruz " matches "Juan Dela Cruz".
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function newToken(): string
    {
        return Str::random(48);
    }

    /**
     * Locks the confirmation and its ticket and checks the requester may still answer.
     *
     * @return array{0: TicketConfirmation, 1: Ticket}
     */
    private function lockAnswerable(TicketConfirmation $confirmation): array
    {
        $locked = TicketConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->firstOrFail();

        match ($locked->state) {
            TicketConfirmation::CONFIRMED => throw new ConfirmationException('This ticket has already been confirmed.'),
            TicketConfirmation::DISPUTED => throw new ConfirmationException('This ticket has already been disputed.'),
            TicketConfirmation::AUTO_CLOSED, TicketConfirmation::HEAD_CLOSED => throw new ConfirmationException('This ticket was already closed.'),
            default => null,
        };

        if ($locked->hasExpired()) {
            throw new ConfirmationException('This confirmation link has expired.', 410);
        }

        $ticket = Ticket::query()->whereKey($locked->ticket_id)->lockForUpdate()->firstOrFail();

        if ((int) $ticket->status_id !== $this->statusId(self::STATUS_PENDING)) {
            throw new ConfirmationException('This ticket is no longer waiting for confirmation.');
        }

        return [$locked, $ticket];
    }

    private function pendingConfirmation(Ticket $ticket): TicketConfirmation
    {
        $confirmation = $ticket->confirmations()->where('state', TicketConfirmation::PENDING)->latest('id')->lockForUpdate()->first();

        if ($confirmation === null || (int) $ticket->status_id !== $this->statusId(self::STATUS_PENDING)) {
            throw new ConfirmationException('This ticket is not waiting for confirmation.');
        }

        return $confirmation;
    }

    private function logMail(TicketConfirmation $confirmation, string $type, ?string $email): ?TicketMailLog
    {
        if ($email === null || $email === '') {
            return null;
        }

        return TicketMailLog::create([
            'ticket_id' => $confirmation->ticket_id,
            'ticket_confirmation_id' => $confirmation->id,
            'type' => $type,
            'to_email' => $email,
            'status' => TicketMailLog::PENDING,
            'next_attempt_at' => now()->addMinutes(10),
        ]);
    }

    /**
     * The assignee and the Department Heads of the ticket's department.
     *
     * @return list<string>
     */
    private function staffToNotify(Ticket $ticket): array
    {
        return User::query()
            ->where('status', true)
            ->where(function ($query) use ($ticket) {
                $query->whereKey($ticket->assigned_user)
                    ->orWhere(fn ($heads) => $heads->where('department_id', $ticket->department)
                        ->whereHas('role', fn ($role) => $role->where('role', 'Department Head')));
            })
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function record(Ticket $ticket, string $category, ?int $userId, ?string $remarks): void
    {
        TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => $this->categoryId($category),
            'user_id' => $userId,
            'remarks' => $remarks,
            'status' => 1,
        ]);
    }

    /**
     * @return array{responded_at: Carbon, responder_ip: ?string, responder_user_agent: ?string}
     */
    private function responder(?Request $request): array
    {
        return [
            'responded_at' => now(),
            'responder_ip' => $request?->ip(),
            'responder_user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 250, ''),
        ];
    }

    private function statusId(string $label): int
    {
        return (int) (Status::query()->where('label', $label)->value('id')
            ?? throw new ConfirmationException("The {$label} status is not set up. Run the ReferenceDataSeeder.", 500));
    }

    private function categoryId(string $category): int
    {
        return (int) (DateCategory::query()->where('category', $category)->value('id')
            ?? throw new ConfirmationException("The {$category} category is not set up. Run the ReferenceDataSeeder.", 500));
    }
}
