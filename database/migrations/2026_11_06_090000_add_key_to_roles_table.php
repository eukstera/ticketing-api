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
        Schema::table('roles', function (Blueprint $table) {
            $table->string('key')->nullable()->unique()->after('role');
        });

        foreach (['Normal User' => 'normal-user', 'Department Head' => 'department-head', 'Encoder' => 'encoder'] as $name => $key) {
            DB::table('roles')->where('role', $name)->whereNull('key')->update(['key' => $key]);
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
