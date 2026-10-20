<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\DateCategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\NatureOfWorkSeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatusSeeder;
use Database\Seeders\TypeOfConcernSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Seeds the reference rows the controllers look up by label (statuses, date
 * categories, roles, departments, designations, priorities, concern types
 * and natures of work). Seeded ids are deterministic on a fresh database:
 * IT = 1, Engineering = 2.
 */
trait SeedsReferenceData
{
    protected function seedReferenceData(): void
    {
        $this->seed([
            DepartmentSeeder::class,
            StatusSeeder::class,
            DateCategorySeeder::class,
            RoleSeeder::class,
            DesignationSeeder::class,
            PrioritySeeder::class,
            TypeOfConcernSeeder::class,
            NatureOfWorkSeeder::class,
        ]);
    }

    protected function itDepartment(): Department
    {
        return Department::query()->where('department', 'IT')->firstOrFail();
    }

    protected function engineeringDepartment(): Department
    {
        return Department::query()->where('department', 'Engineering')->firstOrFail();
    }

    /**
     * Authenticates as a normal user (or the given one) via Sanctum.
     */
    protected function actingAsUser(?User $user = null): User
    {
        $user ??= User::factory()->normalUser()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        return $this->actingAsUser($user ?? User::factory()->departmentHead()->create());
    }
}
