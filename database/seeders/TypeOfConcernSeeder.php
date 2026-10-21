<?php

namespace Database\Seeders;

use App\Models\TypeOfConcern;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TypeOfConcernSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['label' => 'Hardware', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Software', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Printer', 'created_at' => $now, 'updated_at' => $now],
        ];

        TypeOfConcern::insert($data);
    }
}
