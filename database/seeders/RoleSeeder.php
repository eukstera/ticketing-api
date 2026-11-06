<?php

namespace Database\Seeders;

use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $data = [
            ['role' => 'Normal User', 'key' => Role::NORMAL_USER, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'Section Head', 'key' => Role::DEPARTMENT_HEAD, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'Section Secretary', 'key' => Role::ENCODER, 'created_at' => $now, 'updated_at' => $now],
        ];

        Role::insert($data);
    }
}
