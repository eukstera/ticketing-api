<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\ApiTestCase;

class RoleKeyMigrationTest extends ApiTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_06_090000_add_key_to_roles_table.php');
    }

    public function test_it_stops_before_changing_anything_when_no_department_head_role_exists(): void
    {
        DB::table('roles')->where('key', Role::DEPARTMENT_HEAD)->update(['role' => 'Boss']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No "Department Head" role');

        $this->migration()->up();
    }

    public function test_the_guard_does_not_trip_on_a_database_without_roles(): void
    {
        DB::table('roles')->update(['key' => null]);
        DB::table('roles')->delete();
        $migration = $this->migration();

        // Only the guard is under test: the column already exists here, so the schema step is not reached.
        try {
            $migration->up();
        } catch (QueryException) {
            // the duplicate column error after the guard is expected
        } catch (RuntimeException $e) {
            $this->fail('The guard must not stop a fresh install: '.$e->getMessage());
        }

        $this->assertTrue(true);
    }
}
