<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed everything the system needs to function: reference data (roles,
     * departments, designations, statuses, priorities, date categories,
     * concerns, nature of work, report signatory) and the default account.
     * Demo users and tickets live in DemoDataSeeder.
     */
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            DefaultAccountSeeder::class,
        ]);
    }
}
