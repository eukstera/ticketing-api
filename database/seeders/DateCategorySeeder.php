<?php

namespace Database\Seeders;

use App\Models\DateCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DateCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['category' => 'Requested Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Assigned Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Completed Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'False Alarm Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Reopened Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Confirmed Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Disputed Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Auto-closed Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Blank JO Printed Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Filled In Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Void Date', 'created_at' => $now, 'updated_at' => $now],
        ];

        DateCategory::insert($data);
    }
}
