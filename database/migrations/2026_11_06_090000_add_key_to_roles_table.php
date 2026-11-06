<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles get a stable key so the code and the front-end no longer depend on the
     * display name, which Admin can edit. Roles made in Admin have no key.
     */
    public function up(): void
    {
        $builtIn = ['normal user' => 'normal-user', 'department head' => 'department-head', 'encoder' => 'encoder'];

        // Without a Department Head row nobody would keep admin access, so stop before
        // anything changes. A database that has no roles yet (a fresh install) is fine.
        $names = DB::table('roles')->pluck('role')->map(fn ($role) => strtolower(trim($role)));

        if ($names->isNotEmpty() && ! $names->contains('department head')) {
            throw new RuntimeException('No "Department Head" role was found in the roles table, so the admin role cannot be given its key. Rename the admin role to "Department Head" and run the migration again.');
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->string('key')->nullable()->unique()->after('role');
        });

        foreach (DB::table('roles')->get(['id', 'role']) as $role) {
            $key = $builtIn[strtolower(trim($role->role))] ?? null;

            if ($key !== null && ! DB::table('roles')->where('key', $key)->exists()) {
                DB::table('roles')->where('id', $role->id)->update(['key' => $key]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn('key');
        });
    }
};
