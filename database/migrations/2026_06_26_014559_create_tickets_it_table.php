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
        Schema::create('tickets_it', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets');
            $table->string('type_of_tech_support')->nullable();
            $table->string('type_of_concern');
            $table->string('name_of_area');
            $table->longText('remarks');
            $table->string('area_supervisor')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets_it');
    }
};
