<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsolidatedReportRequest;
use App\Services\ConsolidatedReport;
use App\Services\ConsolidatedWorkbookBuilder;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Consolidated report of the caller's own department, for Encoders and Department Heads.
 */
class ConsolidatedReportController extends Controller
{
    use ApiResponseTrait;

    public function index(ConsolidatedReportRequest $request, ConsolidatedReport $report): JsonResponse
    {
        $departmentId = (int) $request->user()->department_id;
        $filters = $request->filters();
        $rows = $report->rows($departmentId, $filters);

        if ($rows->count() > ConsolidatedReport::MAX_ROWS) {
            return $this->error('Too many tickets match. Narrow the date range or filters.', 422);
        }

        $perPage = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->map(fn (array $row) => $report->present($row))->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url()],
        );

        return $this->success('Consolidated report retrieved', ['result' => [
            'filters' => $this->describe($filters),
            ...$report->aggregate($rows, $departmentId, $filters),
            'tickets' => $paginator,
        ]]);
    }

    public function options(Request $request, ConsolidatedReport $report): JsonResponse
    {
        Gate::authorize('consolidated-report');

        return $this->success('Report options retrieved', [
            'result' => $report->options((int) $request->user()->department_id) + [
                'date_fields' => [['id' => 'requested', 'name' => 'Date requested'], ['id' => 'completed', 'name' => 'Date completed']],
            ],
        ]);
    }

    public function export(ConsolidatedReportRequest $request, ConsolidatedReport $report, ConsolidatedWorkbookBuilder $builder): JsonResponse|BinaryFileResponse
    {
        $user = $request->user()->loadMissing('department');
        $departmentId = (int) $user->department_id;
        $filters = $request->filters();
        $rows = $report->rows($departmentId, $filters);

        if ($rows->count() > ConsolidatedReport::MAX_ROWS) {
            return $this->error('Too many tickets match. Narrow the date range or filters.', 422);
        }

        try {
            $path = $builder->build(
                $report->aggregate($rows, $departmentId, $filters),
                $rows,
                $report->options($departmentId),
                $filters,
                $user,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to generate the report.', 500);
        }

        $filename = sprintf('consolidation_%s_%s.xlsx', $filters['from']->format('Y-m-d'), $filters['to']->format('Y-m-d'));

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function describe(array $filters): array
    {
        return [
            'from' => $filters['from']->toDateString(),
            'to' => $filters['to']->toDateString(),
            'date_field' => $filters['date_field'],
            'requesting_area' => $filters['requesting_area'],
            'status_id' => $filters['status_id'],
            'priority_id' => $filters['priority_id'],
            'concern_type_id' => $filters['concern_type_id'],
            'staff_id' => $filters['staff_id'],
        ];
    }
}
