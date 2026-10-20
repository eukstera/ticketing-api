<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Database\Factories\TicketFactory;
use Tests\ApiTestCase;

class DashboardTest extends ApiTestCase
{
    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_the_documented_shape(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['result' => [
                'overview' => ['open', 'in_progress', 'completed', 'overdue'],
                'priority_stats',
                'todays_ticket' => ['total', 'completed', 'percent_completed'],
                'top_performers',
            ]]);
    }

    public function test_bad_date_values_are_rejected(): void
    {
        $this->actingAsUser($this->itUser());

        foreach (['abc', '2026-13', '2026-1', '2026-02-15', '202602'] as $date) {
            $this->getJson('/api/dashboard?date='.$date)
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonValidationErrors(['date']);
        }
    }

    public function test_requesting_february_on_the_31st_does_not_roll_into_march(): void
    {
        $this->travelTo('2026-01-31 10:00:00');
        $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-02-10 09:00:00')->create();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-03-01 09:00:00')->create();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-01-20 09:00:00')->create();

        $this->getJson('/api/dashboard?date=2026-02')
            ->assertOk()
            ->assertJsonPath('result.overview.open', 1);

        $this->getJson('/api/dashboard?date=2026-03')
            ->assertOk()
            ->assertJsonPath('result.overview.open', 1);
    }

    public function test_default_month_on_the_31st_covers_the_whole_current_month(): void
    {
        $this->travelTo('2026-03-31 18:00:00');
        $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-03-01 00:30:00')->create();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-03-31 23:30:00')->create();
        TicketFactory::new()->forDepartment($it)->open()->requestedAt('2026-02-28 23:30:00')->create();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('result.overview.open', 2);
    }

    public function test_overview_counts_each_status_for_the_callers_department_only(): void
    {
        $this->travelTo('2026-05-15 10:00:00');
        $user = $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        TicketFactory::new()->forDepartment($it)->open()->count(2)->create();
        TicketFactory::new()->forDepartment($it)->assigned($user)->create();
        TicketFactory::new()->forDepartment($it)->assigned($user)->completed()->count(3)->create();
        TicketFactory::new()->forDepartment($it)->overdue()->create();
        TicketFactory::new()->forDepartment($this->engineeringDepartment())->open()->count(4)->create();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('result.overview.open', 2)
            ->assertJsonPath('result.overview.in_progress', 1)
            ->assertJsonPath('result.overview.completed', 3)
            ->assertJsonPath('result.overview.overdue', 1);
    }

    public function test_priority_filter_limits_the_overview(): void
    {
        $this->travelTo('2026-05-15 10:00:00');
        $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        TicketFactory::new()->forDepartment($it)->open()->state(['priority_id' => 1])->count(2)->create();
        TicketFactory::new()->forDepartment($it)->open()->state(['priority_id' => 2])->create();

        $this->getJson('/api/dashboard?priority=1')
            ->assertOk()
            ->assertJsonPath('result.overview.open', 2);

        $stats = collect($this->getJson('/api/dashboard?priority=1')->json('result.priority_stats'));
        $this->assertSame(1, $stats->firstWhere('priority_id', 2)['count']);
    }

    public function test_todays_ticket_percentage(): void
    {
        $this->travelTo('2026-05-15 10:00:00');
        $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        TicketFactory::new()->forDepartment($it)->completed()->create();
        TicketFactory::new()->forDepartment($it)->open()->count(2)->create();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('result.todays_ticket.total', 3)
            ->assertJsonPath('result.todays_ticket.completed', 1)
            ->assertJsonPath('result.todays_ticket.percent_completed', 33);
    }

    public function test_todays_ticket_percentage_is_zero_without_tickets(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('result.todays_ticket.total', 0)
            ->assertJsonPath('result.todays_ticket.percent_completed', 0);
    }

    public function test_top_performers_are_ranked_by_completed_tickets_in_the_month(): void
    {
        $this->travelTo('2026-05-15 10:00:00');
        $caller = $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();
        $busy = User::factory()->inDepartment($it)->create();
        $quiet = User::factory()->inDepartment($it)->create();

        TicketFactory::new()->forDepartment($it)->assigned($busy)->completed($busy)->count(3)->create();
        TicketFactory::new()->forDepartment($it)->assigned($quiet)->completed($quiet)->create();
        TicketFactory::new()->forDepartment($it)->assigned($busy)->create();

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('result.top_performers.0.rank', 1)
            ->assertJsonPath('result.top_performers.0.user_id', $busy->id)
            ->assertJsonPath('result.top_performers.0.completed', 3)
            ->assertJsonPath('result.top_performers.0.in_progress', 1)
            ->assertJsonPath('result.top_performers.1.user_id', $quiet->id)
            ->assertJsonPath('result.top_performers.1.completed', 1);
        $this->assertNotSame($caller->id, $response->json('result.top_performers.0.user_id'));
    }

    public function test_top_performers_are_limited_to_five(): void
    {
        $this->travelTo('2026-05-15 10:00:00');
        $this->actingAsUser($this->itUser());
        $it = $this->itDepartment();

        User::factory()->inDepartment($it)->count(7)->create()->each(
            fn (User $user) => TicketFactory::new()->forDepartment($it)->assigned($user)->completed($user)->create()
        );

        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(5, 'result.top_performers');
    }
}
