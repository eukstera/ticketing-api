<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with reference data only.
     * Demo users and tickets live in DemoDataSeeder.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
