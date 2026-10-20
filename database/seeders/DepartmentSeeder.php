<?php

namespace Database\Seeders;

use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        
        $data = [
            ['department' => 'IT', 'created_at' => $now, 'updated_at' => $now],
            ['department' => 'Engineering', 'created_at' => $now, 'updated_at' => $now]
        ];

        Department::insert($data);

    }
}
