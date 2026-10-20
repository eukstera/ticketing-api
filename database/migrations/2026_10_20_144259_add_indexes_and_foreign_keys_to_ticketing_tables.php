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
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('department')->nullable()->change();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('department')->references('id')->on('departments');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('ticket_date_activities', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
            $table->index('requestorId');
            $table->index(['ticket_id', 'date_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ticket_date_activities', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'date_category_id']);
            $table->dropIndex(['requestorId']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['department']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->integer('department')->nullable()->change();
        });
    }
};
