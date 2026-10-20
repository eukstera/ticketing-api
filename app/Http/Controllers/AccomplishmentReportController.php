<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

class AccomplishmentReportController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'user_id'   => 'nullable|integer',
        ]);

        $userId  = $request->input('user_id', $request->user()->id);
        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo   = Carbon::parse($request->date_to)->endOfDay();

        $preparedByUser = 
        User::where('users.id', $request->user()->id)
        ->join('designations', 'designations.id', 'users.designation_id')
        ->first();

        
        $preparedByName = $preparedByUser
            ? strtoupper(trim($preparedByUser->first_name . ' ' . $preparedByUser->last_name))
            : '';
        $preparedByPosition = $preparedByUser->designation ?? '';

        $approvedByName     = 'JOSEFA SOCORRO MOLON PATAWARAN';
        $approvedByPosition = 'CMT II – ICTS';

        $comments = Comment::with('ticket')
            ->select('comments.*', 'ticket_date_activities.requestor as activity_requestor')
            ->join('tickets', function ($q) {
                $q->on('tickets.id', 'comments.ticket_id');
            })
            ->join('ticket_date_activities', function ($q) {
                $q->on('ticket_date_activities.ticket_id', 'comments.ticket_id')
                    ->where('date_category_id', 1);
            })
            ->where('comments.user_id', $userId)
            ->whereBetween('tickets.created_at', [$dateFrom, $dateTo])
            ->orderBy('tickets.created_at')
            ->get();

        // ---- 2. Group by calendar day ----
        $grouped = $comments->groupBy(function ($c) {
            return $c->created_at->format('Y-m-d');
        });

        // Make sure every day in the range appears (even if empty / off-duty)
        $period = collect();
        for ($d = $dateFrom->copy(); $d->lte($dateTo); $d->addDay()) {
            $period->push($d->format('Y-m-d'));
        }

        // ---- 3. Build the document ----
        $phpWord = new PhpWord();
        // $phpWord->getSettings()->setUpdateFields(true);

        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
        $phpWord->setDefaultParagraphStyle([
            'spaceAfter'  => 0,
            'spaceBefore' => 0,
            'lineHeight'  => 1.0,
        ]);

        $section = $phpWord->addSection([
            'marginTop'    => 3600, 
            'marginBottom' => 700,
            'marginLeft'   => 900,
            'marginRight'  => 900,
            'headerHeight' => 900,
            'colsNum'      => 2,
            'colsSpace'    => 400,
        ]);

        $center = ['alignment' => Jc::CENTER];

        $dohLogoPath     = public_path('images/doh-logo.png');
        $schistoLogoPath = public_path('images/scisto-logo.jpg');

        // Reusable letterhead builder — attached to every section that needs
        $addLetterhead = function ($section) use ($center, $dohLogoPath, $schistoLogoPath, $dateFrom, $dateTo) {
            $header = $section->addHeader();

            $table = $header->addTable([
                'borderSize'        => 0,
                'borderColor'       => 'FFFFFF',
                'borderTopSize'     => 0,
                'borderBottomSize'  => 0,
                'borderLeftSize'    => 0,
                'borderRightSize'   => 0,
                'borderInsideHSize' => 0,
                'borderInsideVSize' => 0,
                'cellMargin'        => 0,
                'alignment'         => Jc::CENTER,
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
            $centerCell->addText(
                $dateFrom->format('F j') . '-' . $dateTo->format('j, Y'),
                ['size' => 11],
                $center
            );

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
                $date->format('m/d/Y') . ' – ' . $date->format('l'),
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

                foreach ($dayComments as $c) {
                    $ticket = $c->ticket;

                    if ($ticket) {
                        $prefix  = 'J.O.#' . $ticket->ticket_number . ' – ';
                        $body_txt = $c->comment;
                        $requestedBy = $c->activity_requestor
                            ? '. Requested by ' . $c->activity_requestor . '.'
                            : '.';
                    } else {
                        $prefix = '';
                        $body_txt = $c->comment;
                        $requestedBy = '';
                    }

                    $body->addText(
                        $num . '. ' . $prefix . $body_txt . $requestedBy,
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
            'marginTop'    => 3600, // matches main section fix
            'marginBottom' => 700,
            'marginLeft'   => 900,
            'marginRight'  => 900,
            'headerHeight' => 900,
        ]);
        $addLetterhead($sigSection);

        $sigLeft   = ['alignment' => Jc::START];
        $sigCenter = ['alignment' => Jc::CENTER];

        $sigSection->addTextBreak(4);

        $sigTable = $sigSection->addTable([
            'borderSize'  => 0,
            'borderColor' => 'FFFFFF',
            'cellMargin'  => 0,
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
            ? $dateFrom->format('F j') . '-' . $dateTo->format('j')
            : $dateFrom->format('F j') . ' - ' . $dateTo->format('F j');

        $filename = $preparedByUser->last_name . ' ' . $rangeLabel . ' Report.docx';
        $tempFile = tempnam(sys_get_temp_dir(), 'dar') . '.docx';

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }
}
