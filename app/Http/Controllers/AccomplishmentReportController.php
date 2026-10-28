<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccomplishmentReportRequest;
use App\Models\Comment;
use App\Models\ReportSignatory;
use App\Models\Status;
use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Throwable;

class AccomplishmentReportController extends Controller
{
    private const FALLBACK_SIGNATORY_NAME = 'JOSEFA SOCORRO MOLON PATAWARAN';

    private const FALLBACK_SIGNATORY_TITLE = 'CMT II – ICTS';

    public function generate(AccomplishmentReportRequest $request)
    {
        $userId = (int) ($request->validated('user_id') ?? $request->user()->id);

        if ($userId !== $request->user()->id && ! Gate::allows('admin')) {
            return response()->json([
                'success' => false,
                'message' => "Only Department Heads can export another user's report.",
            ], 403);
        }

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();
        $rangeHeading = $this->rangeHeading($dateFrom, $dateTo);

        $preparedByUser = User::with('designation')->find($userId);

        $preparedByName = $preparedByUser
            ? strtoupper(trim($preparedByUser->first_name.' '.$preparedByUser->last_name))
            : '';
        $preparedByPosition = $preparedByUser?->designation?->designation ?? '';

        [$approvedByName, $approvedByPosition] = $this->resolveSignatory($request);

        // ---- 2. Group by calendar day ----
        $grouped = $this->actionsTaken($userId, $dateFrom, $dateTo)->groupBy(function ($entry) {
            return $entry['at']->format('Y-m-d');
        });

        // Make sure every day in the range appears (even if empty / off-duty)
        $period = collect();
        for ($d = $dateFrom->copy(); $d->lte($dateTo); $d->addDay()) {
            $period->push($d->format('Y-m-d'));
        }

        // ---- 3. Build the document ----
        $phpWord = new PhpWord;
        // $phpWord->getSettings()->setUpdateFields(true);

        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
        $phpWord->setDefaultParagraphStyle([
            'spaceAfter' => 0,
            'spaceBefore' => 0,
            'lineHeight' => 1.0,
        ]);

        $section = $phpWord->addSection([
            'marginTop' => 3600,
            'marginBottom' => 700,
            'marginLeft' => 900,
            'marginRight' => 900,
            'headerHeight' => 900,
            'colsNum' => 2,
            'colsSpace' => 400,
        ]);

        $center = ['alignment' => Jc::CENTER];

        $dohLogoPath = public_path('images/doh-logo.png');
        $schistoLogoPath = public_path('images/scisto-logo.jpg');

        // Reusable letterhead builder — attached to every section that needs
        $addLetterhead = function ($section) use ($center, $dohLogoPath, $schistoLogoPath, $rangeHeading) {
            $header = $section->addHeader();

            $table = $header->addTable([
                'borderSize' => 0,
                'borderColor' => 'FFFFFF',
                'borderTopSize' => 0,
                'borderBottomSize' => 0,
                'borderLeftSize' => 0,
                'borderRightSize' => 0,
                'borderInsideHSize' => 0,
                'borderInsideVSize' => 0,
                'cellMargin' => 0,
                'alignment' => Jc::CENTER,
            ]);

            $table->addRow();

            $leftCell = $table->addCell(1400, ['valign' => 'top']);
            if (file_exists($dohLogoPath)) {
                $leftCell->addImage($dohLogoPath, [
                    'width' => 70,
                    'height' => 70,
                    'alignment' => Jc::START,
                ]);
            }

            $centerCell = $table->addCell(6200, ['valign' => 'center']);
            $centerCell->addText('Republic of the Philippines', ['size' => 11], $center);
            $centerCell->addText('Department of Health Region VIII', ['size' => 11], $center);
            $centerCell->addText(
                'Gov. Benjamin T. Romualdez General Hospital and Schistosomiasis Center',
                ['bold' => true, 'size' => 11],
                $center
            );
            $centerCell->addText('Palo, Leyte', ['size' => 11], $center);
            $centerCell->addText('DAILY ACCOMPLISHMENT REPORT', ['bold' => true, 'size' => 12], $center);
            $centerCell->addText($rangeHeading, ['size' => 11], $center);

            $rightCell = $table->addCell(1400, ['valign' => 'top']);
            if (file_exists($schistoLogoPath)) {
                $rightCell->addImage($schistoLogoPath, [
                    'width' => 70,
                    'height' => 70,
                    'alignment' => Jc::END,
                ]);
            }
        };

        $addLetterhead($section);

        $body = $section; // body content below writes into the same section

        foreach ($period as $dateStr) {
            $date = Carbon::parse($dateStr);
            $dayComments = $grouped->get($dateStr, collect());
            $entrySpacing = ['spaceAfter' => 60];

            // Date heading, e.g. "06/16/2026 – Tuesday." (no underline)
            $body->addText(
                $date->format('m/d/Y').' – '.$date->format('l'),
                ['bold' => true],
                $entrySpacing
            );
            $body->addText(
                '*ICTS TASK/TECHNICAL SUPPORT',
                ['bold' => true, 'italic' => true, 'underline' => 'single'],
                $entrySpacing
            );

            if ($dayComments->isEmpty()) {
                $body->addText('-No records found.', ['italic' => true], $entrySpacing);
            } else {
                // Manual counter so numbering restarts at 1 for every day,
                // instead of continuing across the whole report.
                $num = 1;
                $indent = ['indentation' => ['left' => 400, 'hanging' => 400], 'spaceAfter' => 60];

                foreach ($dayComments as $entry) {
                    $prefix = 'J.O.#'.$entry['ticket_number'].' – ';
                    $requestedBy = $entry['requestor'] ? '. Requested by '.$entry['requestor'].'.' : '.';

                    $body->addText(
                        $num.'. '.$prefix.$entry['text'].$requestedBy,
                        [],
                        $indent
                    );
                    $num++;
                }
            }

            $body->addTextBreak(1);
        }

        // ---- Signatory page (own page, same repeating letterhead) ----
        $sigSection = $phpWord->addSection([
            'marginTop' => 3600, // matches main section fix
            'marginBottom' => 700,
            'marginLeft' => 900,
            'marginRight' => 900,
            'headerHeight' => 900,
        ]);
        $addLetterhead($sigSection);

        $sigLeft = ['alignment' => Jc::START];
        $sigCenter = ['alignment' => Jc::CENTER];

        $sigSection->addTextBreak(4);

        $sigTable = $sigSection->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'cellMargin' => 0,
        ]);

        $sigTable->addRow();
        $sigCell = $sigTable->addCell(5000); // left ~55% of page width
        $sigTable->addCell(4000); // empty right spacer, keeps block on the left

        $sigCell->addText('Prepared by:', ['bold' => true], $sigLeft);
        $sigCell->addTextBreak(2);
        $sigCell->addText($preparedByName, ['bold' => true, 'underline' => 'single'], $sigLeft);
        $sigCell->addText($preparedByPosition, [], $sigLeft);

        $sigCell->addTextBreak(4);

        $sigCell->addText('Approved by:', ['bold' => true], $sigLeft);
        $sigCell->addTextBreak(2);
        $sigCell->addText($approvedByName, ['bold' => true, 'underline' => 'single'], $sigLeft);
        $sigCell->addText($approvedByPosition, [], $sigLeft);

        $sameMonth = $dateFrom->format('Y-m') === $dateTo->format('Y-m');

        $rangeLabel = $sameMonth
            ? $dateFrom->format('F j').'-'.$dateTo->format('j')
            : $dateFrom->format('F j').' - '.$dateTo->format('F j');

        $filename = ($preparedByUser?->last_name ?? 'Accomplishment').' '.$rangeLabel.' Report.docx';
        $filename = trim(preg_replace('/[\\\\\/:*?"<>|\r\n]+/', ' ', $filename));
        $tempFile = tempnam(sys_get_temp_dir(), 'dar');

        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);
        } catch (Throwable $e) {
            @unlink($tempFile);
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report.',
            ], 500);
        }

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /**
     * The "action taken" lines: the completion notes the user typed when
     * completing a ticket, dated by the completion. A ticket completed more
     * than once (disputed, then completed again) counts only for its latest
     * completion, and not at all while that completion stands disputed.
     * Tickets completed before completion notes existed (Completed, no
     * confirmation record) still use the user's comments, so old reports keep
     * their content.
     *
     * @return Collection<int, array{at: Carbon, ticket_number: string, text: string, requestor: ?string}>
     */
    private function actionsTaken(int $userId, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $completions = TicketConfirmation::query()
            ->with('ticket')
            ->whereIn('id', fn ($query) => $query->selectRaw('MAX(id)')->from('ticket_confirmations')->groupBy('ticket_id'))
            ->where('completed_by', $userId)
            ->where('state', '!=', TicketConfirmation::DISPUTED)
            ->whereBetween('completed_at', [$dateFrom, $dateTo])
            ->get();

        $completedStatusId = Status::where('label', 'Completed')->value('id');

        $legacy = Comment::query()
            ->with('ticket')
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereHas('ticket', fn ($query) => $query->where('status_id', $completedStatusId)->whereDoesntHave('confirmations'))
            ->get();

        $requestors = TicketDateActivity::query()
            ->whereIn('ticket_id', $completions->pluck('ticket_id')->merge($legacy->pluck('ticket_id'))->unique())
            ->where('date_category_id', 1)
            ->pluck('requestor', 'ticket_id');

        return $completions
            ->toBase()
            ->filter(fn ($completion) => $completion->ticket !== null)
            ->map(fn ($completion) => [
                'at' => $completion->completed_at,
                'ticket_number' => $completion->ticket->ticket_number,
                'text' => $completion->completion_notes,
                'requestor' => $requestors[$completion->ticket_id] ?? null,
            ])
            ->merge($legacy->map(fn ($comment) => [
                'at' => $comment->created_at,
                'ticket_number' => $comment->ticket->ticket_number,
                'text' => $comment->comment,
                'requestor' => $requestors[$comment->ticket_id] ?? null,
            ]))
            ->sortBy(fn ($entry) => $entry['at']->getTimestamp())
            ->values();
    }

    /**
     * "Approved by" block: ad-hoc name and title, else the chosen saved
     * signatory, else the saved default, else the built-in fallback.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSignatory(AccomplishmentReportRequest $request): array
    {
        if ($request->filled('signatory_name')) {
            return [
                strtoupper(trim($request->validated('signatory_name'))),
                trim($request->validated('signatory_title')),
            ];
        }

        $signatory = $request->filled('signatory_id')
            ? ReportSignatory::query()->find($request->integer('signatory_id'))
            : ReportSignatory::query()->where('is_default', true)->where('status', true)->first();

        if ($signatory !== null) {
            return [strtoupper($signatory->name), $signatory->title];
        }

        return [self::FALLBACK_SIGNATORY_NAME, self::FALLBACK_SIGNATORY_TITLE];
    }

    /**
     * Period heading, e.g. "May 1-7, 2026", "April 28 - May 3, 2026" or
     * "December 28, 2025 - January 3, 2026".
     */
    private function rangeHeading(Carbon $from, Carbon $to): string
    {
        if ($from->isSameMonth($to)) {
            $days = $from->isSameDay($to) ? $from->format('j') : $from->format('j').'-'.$to->format('j');

            return $from->format('F').' '.$days.', '.$from->format('Y');
        }

        if ($from->isSameYear($to)) {
            return $from->format('F j').' - '.$to->format('F j, Y');
        }

        return $from->format('F j, Y').' - '.$to->format('F j, Y');
    }
}
