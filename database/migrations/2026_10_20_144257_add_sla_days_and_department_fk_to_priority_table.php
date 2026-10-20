<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('priority', function (Blueprint $table) {
            $table->unsignedInteger('sla_days')->default(0)->after('level');
        });

        DB::table('priority')->orderBy('id')->get(['id', 'SLA'])->each(function ($priority) {
            DB::table('priority')->where('id', $priority->id)->update([
                'sla_days' => max(0, (int) $priority->SLA),
            ]);
        });

        Schema::table('priority', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change();
        });

        Schema::table('priority', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('priority', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('priority', function (Blueprint $table) {
            $table->string('department_id')->nullable()->change();
            $table->dropColumn('sla_days');
        });
    }
};
