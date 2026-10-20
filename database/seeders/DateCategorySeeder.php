<?php

namespace Database\Seeders;

use App\Models\DateCategory;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DateCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['category' => 'Requested Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Assigned Date', 'created_at' => $now, 'updated_at' => $now],
            ['category' => 'Completed Date', 'created_at' => $now, 'updated_at' => $now]
        ];

        DateCategory::insert($data);
    }
}
