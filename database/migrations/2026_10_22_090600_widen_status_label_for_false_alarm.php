<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The label was an enum of the four original
     * statuses; a plain string lets reference data add "False Alarm".
     */
    public function up(): void
    {
        Schema::table('status', function (Blueprint $table) {
            $table->string('label', 50)->change();
        });
    }

    /**
     * Reverse the migrations. Fails if a status outside the original four exists.
     */
    public function down(): void
    {
        Schema::table('status', function (Blueprint $table) {
            $table->enum('label', ['Open', 'In-progress', 'Completed', 'Overdue'])->change();
        });
    }
};
