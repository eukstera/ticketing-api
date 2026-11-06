<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The built-in roles are matched by their key, so only the display names change.
     */
    public function up(): void
    {
        DB::table('roles')->where('key', 'department-head')->update(['role' => 'Section Head']);
        DB::table('roles')->where('key', 'encoder')->update(['role' => 'Section Secretary']);
    }

    public function down(): void
    {
        DB::table('roles')->where('key', 'department-head')->update(['role' => 'Department Head']);
        DB::table('roles')->where('key', 'encoder')->update(['role' => 'Encoder']);
    }
};
