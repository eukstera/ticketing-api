<?php

namespace Database\Seeders;

use App\Models\Designation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo users and tickets for local development. Never runs by default:
 * php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $techSupportId = Designation::where(['department_id' => 1, 'designation' => 'Tech Support'])->value('id');

        User::factory()->create([
            'first_name' => 'Leandro',
            'last_name' => 'Ojeda',
            'designation_id' => $techSupportId,
            'email' => 'leandroojeda@gmail.com',
            'role_id' => 1,
            'department_id' => 1,
        ]);

        User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'designation_id' => $techSupportId,
            'email' => 'johndoe@gmail.com',
            'role_id' => 1,
            'department_id' => 1,
        ]);

        User::factory(10)->create();
        Ticket::factory(50)->create();
    }
}
