<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The Customer Satisfaction Survey, 1 (Strongly
     * Disagree) to 5 (Strongly Agree) per block, once per confirmation.
     */
    public function up(): void
    {
        Schema::create('ticket_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_confirmation_id')->unique()->constrained('ticket_confirmations');
            $table->foreignId('ticket_id')->constrained('tickets');
            $table->unsignedTinyInteger('service');
            $table->unsignedTinyInteger('assurance');
            $table->unsignedTinyInteger('reliability');
            $table->unsignedTinyInteger('communication');
            $table->unsignedTinyInteger('access_facilities');
            $table->unsignedTinyInteger('integrity');
            $table->unsignedTinyInteger('outcome');
            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
            $table->string('submitter_ip', 45)->nullable();
            $table->string('submitter_user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_surveys');
    }
};
