<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\CommentFactory;
use Database\Factories\DeviceFactory;
use Database\Factories\NatureOfWorkFactory;
use Database\Factories\PriorityFactory;
use Database\Factories\StatusFactory;
use Database\Factories\TicketDateActivityFactory;
use Database\Factories\TicketEngineeringFactory;
use Database\Factories\TicketFactory;
use Database\Factories\TicketITFactory;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class TicketDetailTest extends ApiTestCase
{
    private function itTicketWith(?User $assignee = null, ?User $requester = null, string $requestedAt = '2026-05-10 09:00:00'): Ticket
    {
        $builder = TicketFactory::new()->withoutDetails()->forDepartment($this->itDepartment())
            ->requestedAt($requestedAt);
        $ticket = $assignee ? $builder->assigned($assignee)->create() : $builder->create();

        TicketITFactory::new()->state(['ticket_id' => $ticket->id])
            ->create(['name_of_area' => 'Pharmacy', 'remarks' => 'Printer jam']);
        DeviceFactory::new()->state(['ticket_id' => $ticket->id])->create(['device' => 'Printer', 'qty' => 3]);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested($requester)
            ->create(['requestor' => $requester ? null : 'Walk-in Requester', 'created_at' => $requestedAt]);

        return $ticket;
    }

    public function test_department_member_sees_the_full_it_detail_shape(): void
    {
        $assignee = $this->itUser();
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicketWith($assignee);
        $comment = CommentFactory::new()->create(['ticket_id' => $ticket->id, 'user_id' => $assignee->id, 'comment' => 'On it']);

        $response = $this->getJson("/api/tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.ticket.id', $ticket->id)
            ->assertJsonPath('result.ticket.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('result.ticket.type', 'IT')
            ->assertJsonPath('result.ticket.department.name', 'IT')
            ->assertJsonPath('result.ticket.status.label', 'In-progress')
            ->assertJsonPath('result.ticket.assignee.id', $assignee->id)
            ->assertJsonPath('result.ticket.requester.name', 'Walk-in Requester')
            ->assertJsonPath('result.ticket.details.remarks', 'Printer jam')
            ->assertJsonPath('result.ticket.details.devices.0.name', 'Printer')
            ->assertJsonPath('result.ticket.details.devices.0.qty', 3)
            ->assertJsonPath('result.ticket.comments.0.id', $comment->id)
            ->assertJsonPath('result.ticket.comments.0.user.id', $assignee->id)
            ->assertJsonStructure(['result' => ['ticket' => [
                'id', 'ticket_number', 'department' => ['id', 'name'], 'status' => ['id', 'label'],
                'priority' => ['id', 'level', 'sla_days'], 'type', 'details', 'requester', 'assignee',
                'is_overdue', 'due_at', 'timeline', 'false_alarm', 'comments', 'created_at', 'updated_at',
            ]]]);
        $this->assertIsInt($response->json('result.ticket.priority.sla_days'));
        $this->assertSame(
            ['id', 'first_name', 'last_name'],
            array_keys($response->json('result.ticket.assignee'))
        );
        $this->assertSame(
            ['id', 'first_name', 'last_name'],
            array_keys($response->json('result.ticket.comments.0.user'))
        );
        $this->assertArrayHasKey('type_of_concern', $response->json('result.ticket.details'));
    }

    public function test_engineering_tickets_expose_engineering_details(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $nature = NatureOfWorkFactory::new()->create(['label' => 'Carpentry']);
        $ticket = TicketFactory::new()->withoutDetails()->forDepartment($this->engineeringDepartment())->create();
        TicketEngineeringFactory::new()->state(['ticket_id' => $ticket->id])->create([
            'nature_of_work' => $nature->id,
            'scope_of_work' => 'Fix door',
            'name_of_area' => 'ICU',
        ]);
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->requested()->create();

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.type', 'Engineering')
            ->assertJsonPath('result.ticket.department.name', 'Engineering')
            ->assertJsonPath('result.ticket.details.nature_of_work', 'Carpentry')
            ->assertJsonPath('result.ticket.details.name_of_area', 'ICU')
            ->assertJsonPath('result.ticket.assignee', null)
            ->assertJsonStructure(['result' => ['ticket' => ['details' => ['other_nature_of_work']]]]);
    }

    public function test_a_registered_requester_is_returned_with_an_id(): void
    {
        $requester = $this->engineeringUser();
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicketWith(null, $requester);

        $response = $this->getJson("/api/tickets/{$ticket->id}")->assertOk();

        $this->assertSame($requester->id, $response->json('result.ticket.requester.id'));
        $this->assertSame($requester->first_name, $response->json('result.ticket.requester.first_name'));
    }

    public function test_timeline_lists_activities_in_order_with_category_user_and_time(): void
    {
        $assignee = $this->itUser();
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicketWith($assignee, null, '2026-05-10 09:00:00');
        TicketDateActivityFactory::new()->for($ticket, 'ticket')->completed($assignee)
            ->create(['created_at' => '2026-05-11 09:00:00']);

        $timeline = $this->getJson("/api/tickets/{$ticket->id}")->assertOk()->json('result.ticket.timeline');

        $this->assertSame(
            ['Requested Date', 'Assigned Date', 'Completed Date'],
            array_column($timeline, 'category')
        );
        $this->assertSame('Walk-in Requester', $timeline[0]['user']);
        $this->assertSame(trim("{$assignee->first_name} {$assignee->last_name}"), $timeline[1]['user']);
        $this->assertSame(['id', 'category', 'user', 'remarks', 'created_at'], array_keys($timeline[0]));
    }

    public function test_requester_from_another_department_can_view_their_ticket(): void
    {
        $requester = $this->actingAsUser($this->engineeringUser());
        $ticket = $this->itTicketWith(null, $requester);

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
    }

    public function test_admin_from_another_department_can_view_any_ticket(): void
    {
        $this->actingAsAdmin(User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create());
        $ticket = $this->itTicketWith();

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
    }

    public function test_other_department_users_are_forbidden(): void
    {
        $this->actingAsUser($this->engineeringUser());
        $ticket = $this->itTicketWith();

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_unknown_ticket_returns_404(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/tickets/999999')->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_detail_requires_authentication(): void
    {
        $ticket = $this->itTicketWith();

        $this->getJson("/api/tickets/{$ticket->id}")->assertUnauthorized();
    }

    public function test_is_overdue_and_due_at_follow_the_priority_sla(): void
    {
        $this->travelTo('2026-05-20 12:00:00');
        $this->actingAsUser($this->itUser());
        $priority = PriorityFactory::new()->slaDays(2)->create(['department_id' => $this->itDepartment()->id]);
        $late = $this->itTicketWith(null, null, '2026-05-17 09:00:00');
        $late->update(['priority_id' => $priority->id]);
        $fresh = $this->itTicketWith(null, null, '2026-05-19 09:00:00');
        $fresh->update(['priority_id' => $priority->id]);

        $lateTicket = $this->getJson("/api/tickets/{$late->id}")->assertOk()->json('result.ticket');
        $freshTicket = $this->getJson("/api/tickets/{$fresh->id}")->assertOk()->json('result.ticket');

        $this->assertTrue($lateTicket['is_overdue']);
        $this->assertSame('2026-05-19', Carbon::parse($lateTicket['due_at'])->format('Y-m-d'));
        $this->assertFalse($freshTicket['is_overdue']);
        $this->assertSame('2026-05-21', Carbon::parse($freshTicket['due_at'])->format('Y-m-d'));
        $this->assertSame(2, $freshTicket['priority']['sla_days']);
    }

    public function test_in_progress_tickets_past_due_are_overdue_before_the_status_is_flipped(): void
    {
        $this->travelTo('2026-05-20 12:00:00');
        $assignee = $this->itUser();
        $this->actingAsUser($this->itUser());
        $priority = PriorityFactory::new()->slaDays(2)->create(['department_id' => $this->itDepartment()->id]);
        $ticket = $this->itTicketWith($assignee, null, '2026-05-17 09:00:00');
        $ticket->update(['priority_id' => $priority->id]);

        $response = $this->getJson("/api/tickets/{$ticket->id}")->assertOk();

        $response->assertJsonPath('result.ticket.status.label', 'In-progress')
            ->assertJsonPath('result.ticket.is_overdue', true);
    }

    public function test_completed_tickets_are_never_overdue(): void
    {
        $this->travelTo('2026-05-20 12:00:00');
        $this->actingAsUser($this->itUser());
        $priority = PriorityFactory::new()->slaDays(1)->create(['department_id' => $this->itDepartment()->id]);
        $ticket = $this->itTicketWith(null, null, '2026-05-01 09:00:00');
        $ticket->update(['priority_id' => $priority->id, 'status_id' => StatusFactory::labelled('Completed')->id]);

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.is_overdue', false);
    }

    public function test_status_overdue_tickets_report_is_overdue(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicketWith();
        $ticket->update(['status_id' => StatusFactory::labelled('Overdue')->id]);

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.is_overdue', true);
    }

    public function test_due_at_is_null_without_a_requested_date(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = TicketFactory::new()->withoutDetails()->forDepartment($this->itDepartment())->create();
        TicketITFactory::new()->state(['ticket_id' => $ticket->id])->create();

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('result.ticket.due_at', null)
            ->assertJsonPath('result.ticket.is_overdue', false);
    }
}
