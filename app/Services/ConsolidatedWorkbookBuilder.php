<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the consolidated report as an .xlsx with native Excel charts:
 * Summary (filters, KPI tiles, charts), Tickets, Staff and Satisfaction.
 * Cells that do not apply stay blank; percentages are stored as fractions.
 */
class ConsolidatedWorkbookBuilder
{
    private const HEADER_FILL = '1F3A5F';

    private const STATUS_COLORS = [
        'Open' => '3B82F6',
        'In-progress' => 'F59E0B',
        'Completed' => '22C55E',
        'Overdue' => 'EF4444',
        'False Alarm' => '8B5CF6',
        'Pending Confirmation' => '14B8A6',
        'Void' => '374151',
    ];

    private const PALETTE = ['3B82F6', '14B8A6', 'F59E0B', 'D946EF', '6366F1', 'EF4444', '22C55E', '9CA3AF'];

    /**
     * @param  array{summary: array<string, mixed>, breakdowns: array<string, mixed>, satisfaction: array<string, mixed>}  $data
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $options
     * @param  array<string, mixed>  $filters
     * @return string path of the temporary .xlsx
     */
    public function build(array $data, Collection $rows, array $options, array $filters, User $user): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Consolidated report')->setCreator($user->first_name.' '.$user->last_name);

        $summary = $spreadsheet->getActiveSheet()->setTitle('Summary');
        $tickets = $spreadsheet->createSheet()->setTitle('Tickets');
        $staff = $spreadsheet->createSheet()->setTitle('Staff');
        $satisfaction = $spreadsheet->createSheet()->setTitle('Satisfaction');

        $this->summarySheet($summary, $data, $options, $filters, $user);
        $this->ticketsSheet($tickets, $rows);
        $this->staffSheet($staff, $data['breakdowns']['by_staff']);
        $this->satisfactionSheet($satisfaction, $data['satisfaction']);

        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'cons').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    // ------------------------------------------------------------------ Summary

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<array<string, mixed>>>  $options
     * @param  array<string, mixed>  $filters
     */
    private function summarySheet(Worksheet $sheet, array $data, array $options, array $filters, User $user): void
    {
        $sheet->setShowGridlines(false);
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(15);
        }
        $sheet->getColumnDimension('I')->setWidth(3);

        $department = $user->department?->department ?? 'Department';
        $sheet->setCellValue('A1', "Consolidated report - {$department}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::HEADER_FILL);
        $sheet->setCellValue('A2', 'Generated '.now()->format('Y-m-d H:i').' by '.trim($user->first_name.' '.$user->last_name));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');

        $names = fn (string $optionKey, array $ids): string => $ids === [] ? 'All' : collect($options[$optionKey])
            ->whereIn('id', $ids)->pluck('name')->implode(', ');

        $recap = [
            'Date basis' => $filters['date_field'] === 'completed' ? 'Date completed' : 'Date requested',
            'From' => $filters['from']->format('Y-m-d'),
            'To' => $filters['to']->format('Y-m-d'),
            'Requesting departments' => $filters['requesting_area'] === [] ? 'All' : implode(', ', $filters['requesting_area']),
            'Statuses' => $names('statuses', $filters['status_id']),
            'Priorities' => $names('priorities', $filters['priority_id']),
            'Concern types' => $names('concern_types', $filters['concern_type_id']),
            'Handling staff' => $names('staff', $filters['staff_id']),
        ];

        $row = 4;
        foreach ($recap as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $kpis = $data['summary'];
        $tiles = [
            ['Total tickets', $kpis['total'], '#,##0'],
            ['Completed', $kpis['completed'], '#,##0'],
            ['Within SLA', $this->fraction($kpis['within_sla_percent']), '0.0%'],
            ['Avg. resolution time', $kpis['avg_resolution_minutes'] !== null ? round($kpis['avg_resolution_minutes'] / 60, 1) : null, '0.0" h"'],
            ['Overdue', $kpis['overdue'], '#,##0'],
            ['False alarm rate', $this->fraction($kpis['false_alarm_rate_percent']), '0.0%'],
            ['Avg. satisfaction', $kpis['avg_satisfaction'], '0.0" / 5"'],
            ['Disputes', $kpis['disputes'], '#,##0'],
            ['Voided', $kpis['voided'], '#,##0'],
        ];

        $tileTop = 13;
        foreach ($tiles as $index => [$label, $value, $format]) {
            $block = intdiv($index, 4);
            $first = $index % 4 * 2 + 1;
            $labelRow = $tileTop + $block * 3;
            $valueRow = $labelRow + 1;
            $c1 = $this->column($first);
            $c2 = $this->column($first + 1);

            $sheet->mergeCells("{$c1}{$labelRow}:{$c2}{$labelRow}");
            $sheet->mergeCells("{$c1}{$valueRow}:{$c2}{$valueRow}");
            $sheet->setCellValue("{$c1}{$labelRow}", $label);
            if ($value !== null) {
                $sheet->setCellValue("{$c1}{$valueRow}", $value);
            }
            $sheet->getStyle("{$c1}{$valueRow}")->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle("{$c1}{$labelRow}:{$c2}{$valueRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2F7']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D3E0']]],
            ]);
            $sheet->getStyle("{$c1}{$labelRow}")->getFont()->setSize(9)->getColor()->setRGB('4B5563');
            $sheet->getStyle("{$c1}{$valueRow}")->getFont()->setBold(true)->setSize(18)->getColor()->setRGB(self::HEADER_FILL);
            $sheet->getRowDimension($valueRow)->setRowHeight(30);
        }

        // Helper ranges the charts read from, below the tiles.
        $row = 21;
        $sheet->setCellValue("A{$row}", 'Chart data')->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row += 2;

        $statusColors = [];
        [$statusRows, $row] = $this->helperTable($sheet, $row, 'Status', 'Tickets', collect($data['breakdowns']['by_status'])->where('count', '>', 0)->values()->all(), 'label', 'count', function (string $label) use (&$statusColors) {
            $statusColors[] = self::STATUS_COLORS[$label] ?? '9CA3AF';
        });
        [$priorityRows, $row] = $this->helperTable($sheet, $row, 'Priority', 'Tickets', $data['breakdowns']['by_priority'], 'label', 'count');
        [$areaRows, $row] = $this->helperTable($sheet, $row, 'Requesting department', 'Tickets', array_slice($data['breakdowns']['by_requesting_area'], 0, 15), 'name', 'count');
        [$trendRows, $row] = $this->trendTable($sheet, $row, $data['breakdowns']['trend']['points']);

        $this->barChart($sheet, 'Tickets by status', 'Status', 'Tickets', 'A', 'B', $statusRows, 'J2', 'Q19', $statusColors);
        $priorityColors = [];
        foreach ($data['breakdowns']['by_priority'] as $index => $priority) {
            $priorityColors[] = strcasecmp($priority['label'], 'Complex') === 0 ? 'D946EF' : self::PALETTE[$index % count(self::PALETTE)];
        }
        $this->barChart($sheet, 'Tickets by priority', 'Priority', 'Tickets', 'A', 'B', $priorityRows, 'R2', 'Y19', $priorityColors);
        $this->barChart($sheet, 'Tickets by requesting department', 'Requesting department', 'Tickets', 'A', 'B', $areaRows, 'R21', 'Y38', ['6366F1']);
        $this->lineChart($sheet, $trendRows, 'J21', 'Q38');

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getSheetView()->setZoomScale(90);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: array{first: int, last: int}|null, 1: int} the data row range (null when empty) and the next free row
     */
    private function helperTable(Worksheet $sheet, int $row, string $labelHeader, string $valueHeader, array $items, string $labelKey, string $valueKey, ?callable $perItem = null): array
    {
        $sheet->setCellValue("A{$row}", $labelHeader);
        $sheet->setCellValue("B{$row}", $valueHeader);
        $this->headerStyle($sheet, "A{$row}:B{$row}");
        $first = $row + 1;

        foreach ($items as $item) {
            $row++;
            $sheet->setCellValue("A{$row}", $item[$labelKey]);
            $sheet->setCellValue("B{$row}", $item[$valueKey]);
            if ($perItem !== null) {
                $perItem($item[$labelKey]);
            }
        }

        return [$items === [] ? null : ['first' => $first, 'last' => $row], $row + 2];
    }

    /**
     * @param  list<array{period: string, label: string, requested: int, completed: int}>  $points
     * @return array{0: array{first: int, last: int}|null, 1: int}
     */
    private function trendTable(Worksheet $sheet, int $row, array $points): array
    {
        $sheet->setCellValue("A{$row}", 'Period');
        $sheet->setCellValue("B{$row}", 'Requested');
        $sheet->setCellValue("C{$row}", 'Completed');
        $this->headerStyle($sheet, "A{$row}:C{$row}");
        $first = $row + 1;

        foreach ($points as $point) {
            $row++;
            $sheet->setCellValue("A{$row}", $point['label']);
            $sheet->setCellValue("B{$row}", $point['requested']);
            $sheet->setCellValue("C{$row}", $point['completed']);
        }

        return [$points === [] ? null : ['first' => $first, 'last' => $row], $row + 2];
    }

    /**
     * @param  array{first: int, last: int}|null  $range
     * @param  list<string>  $colors
     */
    private function barChart(Worksheet $sheet, string $title, string $xTitle, string $yTitle, string $labelColumn, string $valueColumn, ?array $range, string $from, string $to, array $colors): void
    {
        if ($range === null) {
            return;
        }

        $count = $range['last'] - $range['first'] + 1;
        $name = "'{$sheet->getTitle()}'!";
        $header = $range['first'] - 1;

        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$name}\${$valueColumn}\${$range['first']}:\${$valueColumn}\${$range['last']}", null, $count);
        $values->setFillColor($colors === [] ? ['3B82F6'] : array_slice(array_pad($colors, $count, $colors[0] ?? '3B82F6'), 0, $count));

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\${$valueColumn}\${$header}", null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\${$labelColumn}\${$range['first']}:\${$labelColumn}\${$range['last']}", null, $count)],
            [$values],
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $this->place($sheet, new Chart(str_replace(' ', '', $title), new Title($title), null, new PlotArea(null, [$series]), true, 'gap', new Title($xTitle), new Title($yTitle)), $from, $to);
    }

    /**
     * @param  array{first: int, last: int}|null  $range
     */
    private function lineChart(Worksheet $sheet, ?array $range, string $from, string $to): void
    {
        if ($range === null) {
            return;
        }

        $count = $range['last'] - $range['first'] + 1;
        $name = "'{$sheet->getTitle()}'!";
        $header = $range['first'] - 1;
        $labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\$A\${$range['first']}:\$A\${$range['last']}", null, $count)];

        $series = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            [0, 1],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\$B\${$header}", null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\$C\${$header}", null, 1),
            ],
            [$labels[0], $labels[0]],
            [
                (new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$name}\$B\${$range['first']}:\$B\${$range['last']}", null, $count))->setFillColor('3B82F6'),
                (new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$name}\$C\${$range['first']}:\$C\${$range['last']}", null, $count))->setFillColor('22C55E'),
            ],
        );

        $this->place($sheet, new Chart('Trend', new Title('Requests and completions over time'), new Legend(Legend::POSITION_BOTTOM, null, false), new PlotArea(null, [$series]), true, 'gap', new Title('Period'), new Title('Tickets')), $from, $to);
    }

    private function place(Worksheet $sheet, Chart $chart, string $from, string $to): void
    {
        $chart->setTopLeftPosition($from);
        $chart->setBottomRightPosition($to);
        $sheet->addChart($chart);
    }

    // ------------------------------------------------------------------ Tickets

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function ticketsSheet(Worksheet $sheet, Collection $rows): void
    {
        $headers = ['Ticket no.', 'Requested', 'Requesting department', 'Requester', 'Concern type', 'Priority', 'Status', 'Handled by', 'Completed', 'Resolution time (hours)', 'Resolution time', 'Within SLA', 'Satisfaction (1-5)', 'Disputed', 'Action taken', 'Void reason'];
        $widths = [16, 17, 26, 22, 24, 26, 20, 22, 17, 14, 14, 11, 13, 10, 50, 40];

        foreach ($headers as $index => $header) {
            $column = $this->column($index + 1);
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getColumnDimension($column)->setWidth($widths[$index]);
        }
        $this->headerStyle($sheet, 'A1:P1');
        $sheet->getRowDimension(1)->setRowHeight(32);

        $line = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$line}", $row['ticket_number']);
            $this->dateCell($sheet, "B{$line}", $row['requested_at']);
            $sheet->setCellValue("C{$line}", $row['requesting_area']);
            $sheet->setCellValue("D{$line}", $row['requester']);
            $sheet->setCellValue("E{$line}", $row['concern_type']);
            $sheet->setCellValue("F{$line}", $row['priority']);
            $sheet->setCellValue("G{$line}", $row['status']);
            $sheet->setCellValue("H{$line}", $row['handled_by']);
            $this->dateCell($sheet, "I{$line}", $row['completed_at']);

            if ($row['resolution_minutes'] !== null) {
                $sheet->setCellValue("J{$line}", round($row['resolution_minutes'] / 60, 2));
                $sheet->setCellValue("K{$line}", $this->duration($row['resolution_minutes']));
            }
            if ($row['within_sla'] !== null) {
                $sheet->setCellValue("L{$line}", $row['within_sla'] ? 'Yes' : 'No');
            }
            if ($row['survey_score'] !== null) {
                $sheet->setCellValue("M{$line}", $row['survey_score']);
            }
            if ($row['disputes'] !== []) {
                $sheet->setCellValue("N{$line}", 'Yes');
            }
            $sheet->setCellValue("O{$line}", $row['action_taken']);
            $sheet->setCellValue("P{$line}", $row['void_reason']);
            $line++;
        }

        $last = max($line - 1, 1);
        $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        $sheet->getStyle("I2:I{$last}")->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        $sheet->getStyle("J2:J{$last}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("M2:M{$last}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("O2:P{$last}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A2:P{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        if ($last >= 2) {
            $overdue = new Conditional;
            $overdue->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('$G2="Overdue"');
            $overdue->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('FDE2E2');

            $outside = new Conditional;
            $outside->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('$L2="No"');
            $outside->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('FEF3C7');

            $void = new Conditional;
            $void->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('$G2="Void"');
            $void->getStyle()->getFont()->getColor()->setRGB('6B7280');
            $void->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('E5E7EB');

            $sheet->getStyle("A2:P{$last}")->setConditionalStyles([$overdue, $outside, $void]);
        }

        $sheet->freezePane('B2');
        $sheet->setAutoFilter("A1:P{$last}");
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    }

    // ------------------------------------------------------------------ Staff

    /**
     * @param  list<array<string, mixed>>  $staff
     */
    private function staffSheet(Worksheet $sheet, array $staff): void
    {
        $headers = ['Handler', 'Tickets handled', 'Completed', 'Within SLA', 'Avg. resolution (hours)', 'Overdue', 'False alarms', 'Avg. satisfaction (1-5)'];
        $widths = [28, 16, 13, 13, 20, 11, 13, 20];

        foreach ($headers as $index => $header) {
            $column = $this->column($index + 1);
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getColumnDimension($column)->setWidth($widths[$index]);
        }
        $this->headerStyle($sheet, 'A1:H1');
        $sheet->getRowDimension(1)->setRowHeight(32);

        $line = 2;
        foreach ($staff as $member) {
            $sheet->setCellValue("A{$line}", $member['name']);
            $sheet->setCellValue("B{$line}", $member['handled']);
            $sheet->setCellValue("C{$line}", $member['completed']);
            if ($member['within_sla_percent'] !== null) {
                $sheet->setCellValue("D{$line}", $this->fraction($member['within_sla_percent']));
            }
            if ($member['avg_resolution_minutes'] !== null) {
                $sheet->setCellValue("E{$line}", round($member['avg_resolution_minutes'] / 60, 2));
            }
            $sheet->setCellValue("F{$line}", $member['overdue']);
            $sheet->setCellValue("G{$line}", $member['false_alarms']);
            if ($member['avg_satisfaction'] !== null) {
                $sheet->setCellValue("H{$line}", $member['avg_satisfaction']);
            }
            $line++;
        }

        $last = $line - 1;
        $sheet->getStyle("D2:D{$line}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("E2:E{$line}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("H2:H{$line}")->getNumberFormat()->setFormatCode('0.00');

        if ($last >= 2) {
            $sheet->setCellValue("A{$line}", 'Total');
            foreach (['B', 'C', 'F', 'G'] as $column) {
                $sheet->setCellValue("{$column}{$line}", "=SUM({$column}2:{$column}{$last})");
            }
            $sheet->getStyle("A{$line}:H{$line}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::HEADER_FILL]]],
            ]);
        }

        $sheet->freezePane('B2');
        $sheet->setAutoFilter($last >= 2 ? "A1:H{$last}" : 'A1:H1');
    }

    // ------------------------------------------------------------------ Satisfaction

    /**
     * @param  array<string, mixed>  $satisfaction
     */
    private function satisfactionSheet(Worksheet $sheet, array $satisfaction): void
    {
        $sheet->getColumnDimension('A')->setWidth(30);
        foreach (['B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(16);
        }
        $sheet->getColumnDimension('D')->setWidth(60);

        $sheet->setCellValue('A1', 'Satisfaction survey (scale 1-5)');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::HEADER_FILL);

        $sheet->setCellValue('A3', 'Surveys received');
        $sheet->setCellValue('B3', $satisfaction['responses']);
        $sheet->setCellValue('A4', 'Response rate');
        if ($satisfaction['response_rate_percent'] !== null) {
            $sheet->setCellValue('B4', $this->fraction($satisfaction['response_rate_percent']));
        }
        $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->setCellValue('A5', 'Average score');
        if ($satisfaction['avg_satisfaction'] !== null) {
            $sheet->setCellValue('B5', $satisfaction['avg_satisfaction']);
        }
        $sheet->getStyle('B5')->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('A3:A5')->getFont()->setBold(true);

        $row = 7;
        $sheet->setCellValue("A{$row}", 'Score');
        $sheet->setCellValue("B{$row}", 'Answers');
        $this->headerStyle($sheet, "A{$row}:B{$row}");
        $first = $row + 1;
        foreach ($satisfaction['distribution'] as $bucket) {
            $row++;
            $sheet->setCellValue("A{$row}", (string) $bucket['score']);
            $sheet->setCellValue("B{$row}", $bucket['count']);
        }
        $distribution = ['first' => $first, 'last' => $row];
        $sheet->getStyle("A{$first}:A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Survey block');
        $sheet->setCellValue("B{$row}", 'Average');
        $this->headerStyle($sheet, "A{$row}:B{$row}");
        foreach ($satisfaction['by_block'] as $block) {
            $row++;
            $sheet->setCellValue("A{$row}", ucfirst(str_replace('_', ' ', $block['block'])));
            if ($block['average'] !== null) {
                $sheet->setCellValue("B{$row}", $block['average']);
            }
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('0.00');
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Requesting department');
        $sheet->setCellValue("B{$row}", 'Surveys');
        $sheet->setCellValue("C{$row}", 'Average');
        $this->headerStyle($sheet, "A{$row}:C{$row}");
        foreach ($satisfaction['by_requesting_area'] as $area) {
            $row++;
            $sheet->setCellValue("A{$row}", $area['name']);
            $sheet->setCellValue("B{$row}", $area['responses']);
            if ($area['average'] !== null) {
                $sheet->setCellValue("C{$row}", $area['average']);
            }
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('0.00');
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Disputed ticket');
        $sheet->setCellValue("B{$row}", 'Disputed on');
        $sheet->setCellValue("D{$row}", 'Reason');
        $this->headerStyle($sheet, "A{$row}:D{$row}");
        foreach ($satisfaction['disputes'] as $dispute) {
            $row++;
            $sheet->setCellValue("A{$row}", $dispute['ticket_number']);
            if ($dispute['at'] !== null) {
                $sheet->setCellValue("B{$row}", Date::PHPToExcel(Carbon::parse($dispute['at'])));
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }
            $sheet->setCellValue("D{$row}", $dispute['reason']);
            $sheet->getStyle("D{$row}")->getAlignment()->setWrapText(true);
        }

        $count = $distribution['last'] - $distribution['first'] + 1;
        $name = "'{$sheet->getTitle()}'!";
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$name}\$B\${$distribution['first']}:\$B\${$distribution['last']}", null, $count);
        $values->setFillColor(['EF4444', 'F97316', 'F59E0B', '84CC16', '22C55E']);
        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\$B\$".($distribution['first'] - 1), null, 1)],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$name}\$A\${$distribution['first']}:\$A\${$distribution['last']}", null, $count)],
            [$values],
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);
        $this->place($sheet, new Chart('ScoreDistribution', new Title('Score distribution'), null, new PlotArea(null, [$series]), true, 'gap', new Title('Score'), new Title('Answers')), 'F3', 'N20');
    }

    // ------------------------------------------------------------------ helpers

    private function headerStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    private function dateCell(Worksheet $sheet, string $cell, ?Carbon $moment): void
    {
        if ($moment !== null) {
            $sheet->setCellValue($cell, Date::PHPToExcel($moment));
        }
    }

    private function column(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function fraction(?float $percent): ?float
    {
        return $percent === null ? null : round($percent / 100, 4);
    }

    private function duration(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h ".($minutes % 60).'m';
    }
}
