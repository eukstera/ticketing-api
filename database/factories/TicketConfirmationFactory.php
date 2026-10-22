<?php

namespace Database\Factories;

use App\Models\TicketConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketConfirmation>
 */
class TicketConfirmationFactory extends Factory
{
    protected $model = TicketConfirmation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => TicketFactory::new(),
            'completed_by' => null,
            'completion_notes' => fake()->sentence(10),
            'completed_at' => now(),
            'expires_at' => now()->addDays(30),
            'requester_email' => fake()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(48)),
            'state' => TicketConfirmation::PENDING,
        ];
    }

    public function completedAt(string $moment): static
    {
        return $this->state(fn () => ['completed_at' => $moment, 'expires_at' => now()->parse($moment)->addDays(30)]);
    }
}
