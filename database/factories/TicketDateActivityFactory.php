<?php

namespace Database\Factories;

use App\Models\TicketDateActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketDateActivity>
 */
class TicketDateActivityFactory extends Factory
{
    protected $model = TicketDateActivity::class;

    /**
     * Defaults to a requested-date activity whose requester is plain text.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => TicketFactory::new()->withoutDetails(),
            'date_category_id' => fn () => DateCategoryFactory::named(DateCategoryFactory::REQUESTED)->id,
            'user_id' => null,
            'requestor' => fake()->name(),
            'requestorId' => null,
            'status' => true,
        ];
    }

    public function requested(?User $requester = null): static
    {
        return $this->state([
            'date_category_id' => fn () => DateCategoryFactory::named(DateCategoryFactory::REQUESTED)->id,
            'user_id' => $requester?->id,
            'requestor' => $requester ? null : fake()->name(),
        ]);
    }

    public function assigned(?User $assignee = null): static
    {
        return $this->state([
            'date_category_id' => fn () => DateCategoryFactory::named(DateCategoryFactory::ASSIGNED)->id,
            'user_id' => $assignee?->id ?? UserFactory::new(),
            'requestor' => null,
        ]);
    }

    public function completed(?User $completedBy = null): static
    {
        return $this->state([
            'date_category_id' => fn () => DateCategoryFactory::named(DateCategoryFactory::COMPLETED)->id,
            'user_id' => $completedBy?->id ?? UserFactory::new(),
            'requestor' => null,
        ]);
    }
}
