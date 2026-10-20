<?php

namespace Database\Seeders;

use App\Models\Designation;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DesignationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['department_id' => 1, 'designation' => 'Tech Support', 'created_at' => $now, 'updated_at' => $now],
            ['department_id' => 2, 'designation' => 'Electrician', 'created_at' => $now, 'updated_at' => $now],
        ];

        Designation::insert($data);
    }
}
