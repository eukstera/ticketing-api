<?php

namespace Database\Seeders;

use App\Models\Designation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        
        $this->call([
            DepartmentSeeder::class,
            PrioritySeeder::class,
            StatusSeeder::class,
            DesignationSeeder::class,
            RoleSeeder::class,
            NatureOfWorkSeeder::class,
            TypeOfConcernSeeder::class,
            DateCategorySeeder::class
        ]);

        User::factory()->create([
            'first_name' => 'Leandro',
            'last_name' => 'Ojeda',
            'designation_id' => Designation::where(['department_id' => 1, 'designation' => 'Tech Support'])->first()->id,
            'email' => 'leandroojeda@gmail.com',
            'password' => Hash::make('Secret@45'),
            'role_id' => 1,
            'department_id' => 1,
        ]);

        User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'designation_id' => Designation::where(['department_id' => 1, 'designation' => 'Tech Support'])->first()->id,
            'email' => 'johndoe@gmail.com',
            'password' => Hash::make('Secret@45'),
            'role_id' => 1,
            'department_id' => 1,
        ]);

        User::factory(10)->create();
        Ticket::factory(50)->create();

    }
}
