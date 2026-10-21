<?php

namespace Database\Seeders;

use App\Models\Priority;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PrioritySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // $data = [
        //     ['level' => 'Low', 'created_at' => $now, 'updated_at' => $now],
        //     ['level' => 'Moderate', 'created_at' => $now, 'updated_at' => $now],
        //     ['level' => 'High', 'created_at' => $now, 'updated_at' => $now]
        // ];

        $data = [
            ['department_id' => 1, 'level' => 'Urgent & IHOMIS Related', 'SLA' => '1', 'sla_days' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 1, 'level' => 'Urgent', 'SLA' => '2', 'sla_days' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 1, 'level' => 'Important but not urgent', 'SLA' => '3', 'sla_days' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 1, 'level' => 'Long Term', 'SLA' => '10', 'sla_days' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 2, 'level' => 'Urgent', 'SLA' => '1', 'sla_days' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 2, 'level' => 'Complex', 'SLA' => '2', 'sla_days' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 2, 'level' => 'Simple', 'SLA' => '3', 'sla_days' => 3, 'created_at' => $now, 'updated_at' => $now],
        ];

        Priority::insert($data);
    }
}
