<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketDateActivity;

/**
 * What the Job Order form shows for a ticket. The Excel and PDF builders both
 * render this one value object, so the field mapping, truncation and ticking
 * rules live here and the two outputs cannot drift apart.
 */
final class JobOrderData
{
    /** Characters that fit on one "Detail of works requested" line. */
    public const DETAIL_LINE_LENGTH = 92;

    public const DETAIL_LINES = 3;

    public const PRIORITIES = ['ihomis', 'urgent', 'important', 'long_term'];

    /** @var array<string, string> concern type (lowercase) => category key */
    private const CATEGORIES = [
        'hardware' => 'hardware',
        'software' => 'software',
        'network connection' => 'network',
        'photoshop layout' => 'photoshop',
        'audio/video' => 'audio',
        'tutorial / training' => 'tutorial',
    ];

    /**
     * @param  list<string>  $detailLines
     */
    public function __construct(
        public readonly string $number,
        public readonly ?string $division,
        public readonly ?string $equipment,
        public readonly array $detailLines,
        public readonly ?string $priority,
        public readonly ?string $expectedDate,
        public readonly ?string $requesterName,
        public readonly ?string $requesterDesignation,
        public readonly ?string $requestedDate,
        public readonly ?string $category,
        public readonly ?string $othersLabel,
        public readonly ?string $start,
        public readonly ?string $performerName,
        public readonly ?string $performerDesignation,
    ) {}

    public static function fromTicket(Ticket $ticket): self
    {
        $ticket->loadMissing([
            'priority',
            'ticketStatus',
            'itDetails.concernType',
            'devices',
            'assignedUser.designation',
            'dateActivities.dateCategory',
            'dateActivities.user.designation',
        ]);

        if ($ticket->ticketStatus->label === 'Blank') {
            return self::blank($ticket);
        }

        $details = $ticket->itDetails;
        $requested = $ticket->dateActivities->first(fn (TicketDateActivity $activity) => $activity->dateCategory?->category === 'Requested Date');
        $requesterName = $requested?->user ? trim("{$requested->user->first_name} {$requested->user->last_name}") : $requested?->requestor;

        $priority = self::priorityKey((string) $ticket->priority?->level);

        $label = trim((string) $details?->concernType?->label);
        $category = $label === '' ? null : (self::CATEGORIES[mb_strtolower($label)] ?? 'others');

        $taker = $ticket->assignedUser;
        $assigned = $taker === null ? null : $ticket->latestActivity('Assigned Date');

        return new self(
            number: self::displayNumber($ticket),
            division: self::limit($details?->name_of_area, 26),
            equipment: self::limit($ticket->devices->map(fn ($device) => $device->qty > 1 ? "{$device->device} x{$device->qty}" : $device->device)->implode(', '), 28),
            detailLines: self::detailLines(trim((string) preg_replace('/\s+/u', ' ', (string) ($details?->remarks ?: $label)))),
            priority: $priority,
            expectedDate: $priority === null ? null : $ticket->dueAt()?->format('M j, Y'),
            requesterName: self::limit($requesterName, 24),
            requesterDesignation: self::limit($requested?->user?->designation?->designation, 38),
            requestedDate: $requested?->created_at?->format('M j, Y'),
            category: $category,
            othersLabel: $category === 'others' ? self::limit($label, 12) : null,
            start: ($assigned?->updated_at ?? $assigned?->created_at)?->format('M j, Y g:i A'),
            performerName: self::limit($taker === null ? null : trim("{$taker->first_name} {$taker->last_name}"), 30),
            performerDesignation: self::limit($taker?->designation?->designation, 30),
        );
    }

    /**
     * A blank form for handwriting: only the reserved ticket number is filled.
     */
    public static function blank(Ticket $ticket): self
    {
        return new self(
            number: self::displayNumber($ticket),
            division: null,
            equipment: null,
            detailLines: [],
            priority: null,
            expectedDate: null,
            requesterName: null,
            requesterDesignation: null,
            requestedDate: null,
            category: null,
            othersLabel: null,
            start: null,
            performerName: null,
            performerDesignation: null,
        );
    }

    /**
     * The ticket number as printed on the paper form: 10-003-2026 => 10-003-26.
     */
    public static function displayNumber(Ticket $ticket): string
    {
        return (string) preg_replace('/^(\d{2}-\d{3}-)\d{2}(\d{2})$/', '$1$2', $ticket->ticket_number);
    }

    public static function filename(Ticket $ticket, string $extension): string
    {
        return trim((string) preg_replace('/[\\\\\/:*?"<>|\r\n]+/', ' ', 'JO '.self::displayNumber($ticket))).'.'.$extension;
    }

    private static function priorityKey(string $level): ?string
    {
        $level = mb_strtolower(trim($level));

        return match (true) {
            str_contains($level, 'ihomis') => 'ihomis',
            str_contains($level, 'long term') => 'long_term',
            str_contains($level, 'important') => 'important',
            $level === 'urgent' => 'urgent',
            default => null,
        };
    }

    private static function limit(?string $text, int $max): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : mb_strimwidth($text, 0, $max, '…');
    }

    /**
     * Up to three lines for the detail rows; the last ends in an ellipsis if cut.
     *
     * @return list<string>
     */
    private static function detailLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", wordwrap($text, self::DETAIL_LINE_LENGTH, "\n", true));

        if (count($lines) <= self::DETAIL_LINES) {
            return $lines;
        }

        $lines = array_slice($lines, 0, self::DETAIL_LINES);
        $last = self::DETAIL_LINES - 1;
        $lines[$last] = rtrim(mb_substr($lines[$last], 0, self::DETAIL_LINE_LENGTH - 1)).'…';

        return $lines;
    }
}
