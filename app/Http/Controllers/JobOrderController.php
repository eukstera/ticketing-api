<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobOrderRequest;
use App\Models\Ticket;
use App\Services\JobOrderFormBuilder;
use App\Services\JobOrderPdfBuilder;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Downloads the ICTS Job Request / Customer Satisfaction form filled for a
 * ticket, as an Excel workbook (default) or a PDF. Read-only: it never
 * changes the ticket.
 */
class JobOrderController extends Controller
{
    use ApiResponseTrait;

    private const IT_DEPARTMENT_ID = 1;

    public function __invoke(JobOrderRequest $request, Ticket $ticket, JobOrderFormBuilder $excel, JobOrderPdfBuilder $pdf): JsonResponse|BinaryFileResponse|Response
    {
        Gate::authorize('downloadJobOrder', $ticket);

        $ticket->load('ticketStatus');

        if ($ticket->ticketStatus->label === 'False Alarm') {
            return $this->error('A false-alarm ticket has no job order.', 409);
        }

        if ((int) $ticket->department !== self::IT_DEPARTMENT_ID) {
            return $this->error('Job order forms are only available for IT tickets.', 409);
        }

        $asPdf = $request->validated('format') === 'pdf';

        try {
            $path = $asPdf ? $pdf->build($ticket) : $excel->build($ticket);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to generate the job order.', 500);
        }

        return response()->download($path, $asPdf ? $pdf->filename($ticket) : $excel->filename($ticket), [
            'Content-Type' => $asPdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
