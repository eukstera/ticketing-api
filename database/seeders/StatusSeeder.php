<?php

namespace Database\Seeders;

use App\Models\Status;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['label' => 'Open', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'In-progress', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Completed', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Overdue', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'False Alarm', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Pending Confirmation', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Blank', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Void', 'created_at' => $now, 'updated_at' => $now],
        ];

        Status::insert($data);
    }
}
