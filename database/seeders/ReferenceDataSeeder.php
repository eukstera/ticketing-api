<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
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
        ]);

        $this->seedTable('departments', [
            ['id' => 1, 'department' => 'IT'],
            ['id' => 2, 'department' => 'Engineering'],
        ]);

        $this->seedTable('designations', [
            ['id' => 1, 'department_id' => 1, 'designation' => 'Tech Support'],
            ['id' => 2, 'department_id' => 2, 'designation' => 'Electrician'],
        ]);

        $this->seedTable('status', [
            ['id' => 1, 'label' => 'Open'],
            ['id' => 2, 'label' => 'In-progress'],
            ['id' => 3, 'label' => 'Completed'],
            ['id' => 4, 'label' => 'Overdue'],
        ]);

        $this->seedTable('priority', [
            ['id' => 1, 'department_id' => 1, 'level' => 'Urgent & IHOMIS Related', 'sla_days' => 1, 'SLA' => '1'],
            ['id' => 2, 'department_id' => 1, 'level' => 'Urgent', 'sla_days' => 2, 'SLA' => '2'],
            ['id' => 3, 'department_id' => 1, 'level' => 'Important but not urgent', 'sla_days' => 3, 'SLA' => '3'],
            ['id' => 4, 'department_id' => 1, 'level' => 'Long Term', 'sla_days' => 10, 'SLA' => '10'],
            ['id' => 5, 'department_id' => 2, 'level' => 'Urgent', 'sla_days' => 1, 'SLA' => '1'],
            ['id' => 6, 'department_id' => 2, 'level' => 'Complex', 'sla_days' => 2, 'SLA' => '2'],
            ['id' => 7, 'department_id' => 2, 'level' => 'Simple', 'sla_days' => 3, 'SLA' => '3'],
        ]);

        $this->seedTable('date_categories', [
            ['id' => 1, 'category' => 'Requested Date'],
            ['id' => 2, 'category' => 'Assigned Date'],
            ['id' => 3, 'category' => 'Completed Date'],
        ]);

        $this->seedTable('type_of_concern', [
            ['id' => 1, 'label' => 'Hardware'],
            ['id' => 2, 'label' => 'Software'],
            ['id' => 3, 'label' => 'Printer'],
        ]);

        $this->seedTable('nature_of_work', [
            ['id' => 1, 'label' => 'Electrical'],
            ['id' => 2, 'label' => 'Others'],
        ]);
    }

    /**
     * Insert or update each row by id, keeping created_at on existing rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function seedTable(string $table, array $rows): void
    {
        $now = now();

        foreach ($rows as $row) {
            $values = $row + ['status' => true, 'updated_at' => $now];

            if (DB::table($table)->where('id', $row['id'])->exists()) {
                DB::table($table)->where('id', $row['id'])->update($values);
            } else {
                DB::table($table)->insert($values + ['created_at' => $now]);
            }
        }
    }
}
