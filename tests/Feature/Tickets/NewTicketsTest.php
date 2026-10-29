<?php

namespace Tests\Feature\Tickets;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use App\Models\User;
use Database\Factories\TicketFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\ApiTestCase;

class NewTicketsTest extends ApiTestCase
{
    private function itTicket(): Ticket
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->create();
    }

    private function engineeringTicket(): Ticket
    {
        return TicketFactory::new()->forDepartment($this->engineeringDepartment())->create();
    }

    public function test_the_feed_still_works_when_the_blank_and_void_statuses_are_not_seeded(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        Status::query()->whereIn('label', ['Blank', 'Void'])->delete();

        $response = $this->getJson('/api/tickets/new?after_id=0')->assertOk();

        $this->assertSame([$ticket->id], array_column($response->json('result.tickets'), 'id'));
    }

    public function test_a_failing_query_is_logged_and_answers_nothing_new_instead_of_a_500(): void
    {
        $this->actingAsUser($this->itUser());
        $this->itTicket();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'tickets_it')) {
                throw new \RuntimeException('simulated database failure');
            }
        });
        Log::spy();

        $this->getJson('/api/tickets/new?after_id=5')
            ->assertOk()
            ->assertJsonPath('result.tickets', [])
            ->assertJsonPath('result.latest_id', 5);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_the_subject_falls_back_to_the_concern_type_when_there_is_no_request_text(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => '']);

        $row = $this->getJson('/api/tickets/new?after_id=0')->assertOk()->json('result.tickets.0');

        $this->assertNotEmpty($row['subject']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/tickets/new')->assertUnauthorized();
    }

    public function test_engineering_staff_who_are_not_heads_are_forbidden(): void
    {
        $this->actingAsUser($this->engineeringUser());

        $this->getJson('/api/tickets/new')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only IT staff and Department Heads can see new tickets.');
    }

    public function test_call_without_after_id_returns_only_the_baseline(): void
    {
        $this->actingAsUser($this->itUser());
        $this->itTicket();
        $latest = $this->itTicket();

        $this->getJson('/api/tickets/new')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.tickets', [])
            ->assertJsonPath('result.latest_id', $latest->id)
            ->assertJsonPath('result.has_more', false);
    }

    public function test_baseline_on_an_empty_system_is_zero(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/tickets/new')
            ->assertOk()
            ->assertJsonPath('result.latest_id', 0)
            ->assertJsonPath('result.tickets', []);
    }

    public function test_returns_only_tickets_after_the_cursor_oldest_first(): void
    {
        $this->actingAsUser($this->itUser());
        $old = $this->itTicket();
        $first = $this->itTicket();
        $second = $this->itTicket();

        $response = $this->getJson('/api/tickets/new?after_id='.$old->id)->assertOk();

        $this->assertSame([$first->id, $second->id], array_column($response->json('result.tickets'), 'id'));
        $response->assertJsonPath('result.latest_id', $second->id)->assertJsonPath('result.has_more', false);
    }

    public function test_nothing_new_echoes_the_cursor(): void
    {
        $this->actingAsUser($this->itUser());
        $latest = $this->itTicket();

        $this->getJson('/api/tickets/new?after_id='.$latest->id)
            ->assertOk()
            ->assertJsonPath('result.tickets', [])
            ->assertJsonPath('result.latest_id', $latest->id)
            ->assertJsonPath('result.has_more', false);
    }

    public function test_after_id_zero_returns_existing_tickets_not_the_baseline(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();

        $this->getJson('/api/tickets/new?after_id=0')
            ->assertOk()
            ->assertJsonPath('result.tickets.0.id', $ticket->id);
    }

    public function test_ticket_fields_for_an_it_ticket(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => str_repeat('x', 200), 'name_of_area' => 'Pharmacy']);
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->update(['requestor' => 'Juan Dela Cruz']);

        $item = $this->getJson('/api/tickets/new?after_id=0')->assertOk()->json('result.tickets.0');

        $this->assertSame($ticket->id, $item['id']);
        $this->assertSame($ticket->ticket_number, $item['ticket_number']);
        $this->assertSame($this->itDepartment()->id, $item['department_id']);
        $this->assertSame('IT', $item['department']);
        $this->assertSame('Pharmacy', $item['name_of_area']);
        $this->assertSame('Juan Dela Cruz', $item['requester']);
        $this->assertSame($ticket->priority->level, $item['priority']);
        $this->assertSame(120, mb_strlen($item['subject']));
        $this->assertNotEmpty($item['created_at']);
    }

    public function test_subject_falls_back_to_the_concern_type_when_remarks_are_empty(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => '']);

        $subject = $this->getJson('/api/tickets/new?after_id=0')->json('result.tickets.0.subject');

        $this->assertSame($ticket->itDetails->concernType->label, $subject);
    }

    public function test_requester_is_null_when_no_requested_activity_exists(): void
    {
        $this->actingAsUser($this->itUser());
        TicketFactory::new()->forDepartment($this->itDepartment())->withoutDetails()->create();

        $this->getJson('/api/tickets/new?after_id=0')
            ->assertOk()
            ->assertJsonPath('result.tickets.0.requester', null)
            ->assertJsonPath('result.tickets.0.subject', null);
    }

    public function test_results_are_capped_at_twenty_with_has_more(): void
    {
        $this->actingAsUser($this->itUser());
        $tickets = collect(range(1, 22))->map(fn () => $this->itTicket());

        $first = $this->getJson('/api/tickets/new?after_id=0')->assertOk();

        $this->assertCount(20, $first->json('result.tickets'));
        $first->assertJsonPath('result.has_more', true)->assertJsonPath('result.latest_id', $tickets[19]->id);

        $second = $this->getJson('/api/tickets/new?after_id='.$tickets[19]->id)->assertOk();

        $this->assertSame([$tickets[20]->id, $tickets[21]->id], array_column($second->json('result.tickets'), 'id'));
        $second->assertJsonPath('result.has_more', false)->assertJsonPath('result.latest_id', $tickets[21]->id);
    }

    public function test_it_staff_do_not_see_engineering_tickets(): void
    {
        $this->actingAsUser($this->itUser());
        $this->engineeringTicket();
        $it = $this->itTicket();

        $response = $this->getJson('/api/tickets/new?after_id=0')->assertOk();

        $this->assertSame([$it->id], array_column($response->json('result.tickets'), 'id'));
    }

    public function test_engineering_head_sees_only_engineering_tickets(): void
    {
        $head = User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create();
        $this->actingAsAdmin($head);
        $this->itTicket();
        $engineering = $this->engineeringTicket();

        $response = $this->getJson('/api/tickets/new?after_id=0')->assertOk();

        $this->assertSame([$engineering->id], array_column($response->json('result.tickets'), 'id'));
        $response->assertJsonPath('result.tickets.0.department', 'Engineering');
        $this->assertNotNull($response->json('result.tickets.0.subject'));
    }

    public function test_it_head_can_poll(): void
    {
        $head = User::factory()->inDepartment($this->itDepartment())->departmentHead()->create();
        $this->actingAsAdmin($head);
        $ticket = $this->itTicket();

        $this->getJson('/api/tickets/new?after_id=0')
            ->assertOk()
            ->assertJsonPath('result.tickets.0.id', $ticket->id);
    }

    public function test_after_id_is_validated(): void
    {
        $this->actingAsUser($this->itUser());

        foreach (['abc', '-1', '1.5'] as $value) {
            $this->getJson('/api/tickets/new?after_id='.$value)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['after_id']);
        }
    }

    public function test_new_does_not_collide_with_the_ticket_detail_route(): void
    {
        $user = $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();

        $this->getJson('/api/tickets/new')->assertOk()->assertJsonStructure(['result' => ['tickets', 'latest_id', 'has_more']]);
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->assertSame($user->department_id, $this->itDepartment()->id);
    }
}
