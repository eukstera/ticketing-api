<?php

namespace Database\Seeders;

use App\Models\ReportSignatory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the lookup tables with fixed ids so the frontend's assumptions
 * (e.g. IT = 1, Department Head = 2) hold. Safe to run repeatedly.
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedTable('roles', [
            ['id' => 1, 'role' => 'Normal User'],
            ['id' => 2, 'role' => 'Department Head'],
            ['id' => 3, 'role' => 'Encoder'],
        ], ['role']);

        $this->seedTable('departments', [
            ['id' => 1, 'department' => 'IT'],
            ['id' => 2, 'department' => 'Engineering'],
        ], ['department']);

        $this->seedTable('designations', [
            ['id' => 1, 'department_id' => 1, 'designation' => 'Tech Support'],
            ['id' => 2, 'department_id' => 2, 'designation' => 'Electrician'],
        ], ['department_id', 'designation']);

        $this->seedTable('status', [
            ['id' => 1, 'label' => 'Open'],
            ['id' => 2, 'label' => 'In-progress'],
            ['id' => 3, 'label' => 'Completed'],
            ['id' => 4, 'label' => 'Overdue'],
            ['id' => 5, 'label' => 'False Alarm'],
            ['id' => 6, 'label' => 'Pending Confirmation'],
            ['id' => 7, 'label' => 'Blank'],
            ['id' => 8, 'label' => 'Void'],
        ], ['label']);

        $this->seedTable('priority', [
            ['id' => 1, 'department_id' => 1, 'level' => 'Urgent & IHOMIS Related', 'sla_days' => 3, 'SLA' => '3'],
            ['id' => 2, 'department_id' => 1, 'level' => 'Urgent', 'sla_days' => 3, 'SLA' => '3'],
            ['id' => 3, 'department_id' => 1, 'level' => 'Important but not urgent', 'sla_days' => 3, 'SLA' => '3'],
            ['id' => 4, 'department_id' => 1, 'level' => 'Long Term', 'sla_days' => 20, 'SLA' => '20'],
            ['id' => 5, 'department_id' => 2, 'level' => 'Urgent', 'sla_days' => 1, 'SLA' => '1'],
            ['id' => 6, 'department_id' => 2, 'level' => 'Complex', 'sla_days' => 2, 'SLA' => '2'],
            ['id' => 7, 'department_id' => 2, 'level' => 'Simple', 'sla_days' => 3, 'SLA' => '3'],
        ], ['department_id', 'level']);

        $this->seedTable('date_categories', [
            ['id' => 1, 'category' => 'Requested Date'],
            ['id' => 2, 'category' => 'Assigned Date'],
            ['id' => 3, 'category' => 'Completed Date'],
            ['id' => 4, 'category' => 'False Alarm Date'],
            ['id' => 5, 'category' => 'Reopened Date'],
            ['id' => 6, 'category' => 'Confirmed Date'],
            ['id' => 7, 'category' => 'Disputed Date'],
            ['id' => 8, 'category' => 'Auto-closed Date'],
            ['id' => 9, 'category' => 'Blank JO Printed Date'],
            ['id' => 10, 'category' => 'Filled In Date'],
            ['id' => 11, 'category' => 'Void Date'],
        ], ['category']);

        $this->seedTable('type_of_concern', [
            ['id' => 1, 'label' => 'Hardware'],
            ['id' => 2, 'label' => 'Software'],
            ['id' => 3, 'label' => 'Printer'],
        ], ['label']);

        $this->seedTable('nature_of_work', [
            ['id' => 1, 'label' => 'Electrical'],
            ['id' => 2, 'label' => 'Others'],
        ], ['label']);

        $this->seedDefaultReportSignatory();
    }

    /**
     * Adds the original "Approved by" signatory, and makes it the default
     * only when none exists yet, so reruns never duplicate it or undo an
     * admin's later edits.
     */
    private function seedDefaultReportSignatory(): void
    {
        $signatory = ReportSignatory::query()->firstOrCreate(
            ['name' => 'JOSEFA SOCORRO MOLON PATAWARAN', 'title' => 'CMT II – ICTS'],
            ['is_default' => false, 'status' => true],
        );

        if ($signatory->wasRecentlyCreated && ! ReportSignatory::query()->where('is_default', true)->exists()) {
            $signatory->update(['is_default' => true]);
        }
    }

    /**
     * Adds the rows that are missing and never touches an existing one, so anything an
     * admin edited stays as it is. A row counts as present when its id or its natural key exists.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $naturalKey  the columns that identify a row besides its id
     */
    private function seedTable(string $table, array $rows, array $naturalKey): void
    {
        $now = now();

        foreach ($rows as $row) {
            $exists = DB::table($table)->where('id', $row['id'])->exists()
                || DB::table($table)->where(Arr::only($row, $naturalKey))->exists();

            if (! $exists) {
                DB::table($table)->insert($row + ['status' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
