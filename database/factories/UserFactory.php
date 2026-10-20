<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        $department_id = Department::inRandomOrder()->first()->id;
        return [
            'role_id' => Role::inRandomOrder()->first()->id,
            'department_id' => Department::inRandomOrder()->first()->id,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'designation_id' => Designation::where('department_id', $department_id)->first()->id,
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('Secret@45')
        ];
    }

}
