<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One row per completion; the audit record of how the
     * requester answered. Only the SHA-256 of the emailed token is stored.
     */
    public function up(): void
    {
        Schema::create('ticket_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->text('completion_notes');
            $table->timestamp('completed_at');
            $table->timestamp('expires_at');
            $table->string('requester_email')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('state', 20)->default('pending');
            $table->string('channel', 10)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('responder_ip', 45)->nullable();
            $table->string('responder_user_agent', 255)->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_confirmations');
    }
};
