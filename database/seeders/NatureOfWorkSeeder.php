<?php

namespace Database\Seeders;

use App\Models\NatureOfWork;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NatureOfWorkSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['label' => 'Electrical', 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Others', 'created_at' => $now, 'updated_at' => $now]
        ];

        NatureOfWork::insert($data);
    }
}
