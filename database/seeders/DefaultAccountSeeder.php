<?php

namespace Database\Seeders;

use App\Models\Designation;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the default Department Head account in IT so the system can be
 * logged into after a fresh install. Requires ReferenceDataSeeder to have
 * run first. Safe to run repeatedly: an existing account is left untouched,
 * so a changed password is never reset.
 *
 * Override the credentials with DEFAULT_ACCOUNT_EMAIL and
 * DEFAULT_ACCOUNT_PASSWORD in .env, and change the password after first login.
 */
class DefaultAccountSeeder extends Seeder
{
    public const DEPARTMENT_HEAD_ROLE_ID = 2;

    public const IT_DEPARTMENT_ID = 1;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $designationId = Designation::query()
            ->where(['department_id' => self::IT_DEPARTMENT_ID, 'designation' => 'Tech Support'])
            ->value('id');

        User::query()->firstOrCreate(
            ['email' => env('DEFAULT_ACCOUNT_EMAIL', 'admin@gmail.com')],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'password' => env('DEFAULT_ACCOUNT_PASSWORD', 'Admin'),
                'role_id' => self::DEPARTMENT_HEAD_ROLE_ID,
                'department_id' => self::IT_DEPARTMENT_ID,
                'designation_id' => $designationId,
                'status' => true,
            ],
        );
    }
}
