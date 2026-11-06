<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

class RoleRenameMigrationTest extends ApiTestCase
{
    public function test_the_rename_changes_only_the_display_names_of_the_two_built_in_roles(): void
    {
        DB::table('roles')->where('key', Role::DEPARTMENT_HEAD)->update(['role' => 'Department Head']);
        DB::table('roles')->where('key', Role::ENCODER)->update(['role' => 'Encoder']);
        $custom = Role::factory()->create(['role' => 'Trainer']);

        (require database_path('migrations/2026_11_06_090100_rename_head_and_encoder_roles.php'))->up();

        $this->assertSame('Section Head', Role::query()->where('key', Role::DEPARTMENT_HEAD)->value('role'));
        $this->assertSame('Section Secretary', Role::query()->where('key', Role::ENCODER)->value('role'));
        $this->assertSame('Normal User', Role::query()->where('key', Role::NORMAL_USER)->value('role'));
        $this->assertSame('Trainer', $custom->fresh()->role);
    }
}
