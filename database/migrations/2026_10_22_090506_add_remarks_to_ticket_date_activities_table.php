<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ticket_date_activities', function (Blueprint $table) {
            $table->text('remarks')->nullable()->after('requestorId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_date_activities', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
