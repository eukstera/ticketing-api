<?php

namespace Tests\Feature\Harness;

use App\Models\Designation;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\User;
use Database\Factories\CommentFactory;
use Database\Factories\DepartmentFactory;
use Database\Factories\TicketFactory;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class FactoriesTest extends TestCase
{
    use SeedsReferenceData;

    public function test_reference_data_is_seeded_with_deterministic_ids(): void
    {
        $this->seedReferenceData();

        $this->assertSame(1, $this->itDepartment()->id);
        $this->assertSame(2, $this->engineeringDepartment()->id);
        $this->assertDatabaseHas('roles', ['role' => 'Department Head']);
        $this->assertDatabaseHas('status', ['label' => 'Overdue']);
    }

    public function test_user_designation_matches_department_and_role_states_work(): void
    {
        $this->seedReferenceData();

        $engineer = User::factory()->inDepartment($this->engineeringDepartment())->create();
        $head = User::factory()->departmentHead()->create();
        $normal = User::factory()->normalUser()->create();

        $this->assertSame($engineer->department_id, Designation::find($engineer->designation_id)->department_id);
        $this->assertSame('Department Head', Role::find($head->role_id)->role);
        $this->assertSame('Normal User', Role::find($normal->role_id)->role);
    }

    public function test_ticket_factory_is_self_contained_without_seeded_data(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertDatabaseHas('status', ['label' => 'Open']);
        $this->assertDatabaseCount('tickets_it', 1);
        $this->assertDatabaseCount('devices', 1);
        $this->assertSame(1, TicketDateActivity::where('ticket_id', $ticket->id)->count());
    }

    public function test_ticket_states(): void
    {
        $this->seedReferenceData();
        $user = User::factory()->inDepartment($this->engineeringDepartment())->create();

        $assigned = Ticket::factory()->assigned($user)->create();
        $this->assertSame($user->id, $assigned->assigned_user);
        $this->assertSame(2, $assigned->department);
        $this->assertDatabaseHas('tickets_engineering', ['ticket_id' => $assigned->id]);
        $this->assertDatabaseHas('ticket_date_activities', ['ticket_id' => $assigned->id, 'user_id' => $user->id]);

        $completed = Ticket::factory()->assigned($user)->completed()->create();
        $this->assertSame(3, $completed->status_id);
        $this->assertSame(3, TicketDateActivity::where('ticket_id', $completed->id)->count());

        $completedAlone = Ticket::factory()->forDepartment(1)->completed()->create();
        $this->assertNotNull($completedAlone->assigned_user);

        $overdue = Ticket::factory()->overdue()->create();
        $this->assertSame(4, $overdue->status_id);

        $open = Ticket::factory()->open()->create();
        $this->assertNull($open->assigned_user);
        $this->assertSame($this->itDepartment()->id, $open->department);
    }

    public function test_requested_at_and_without_details(): void
    {
        $this->seedReferenceData();

        $ticket = Ticket::factory()->requestedAt('2026-03-05 10:00:00')->create();
        $activity = TicketDateActivity::where('ticket_id', $ticket->id)->first();
        $this->assertSame('2026-03-05 10:00:00', $activity->created_at->format('Y-m-d H:i:s'));

        $bare = TicketFactory::new()->withoutDetails()->create();
        $this->assertDatabaseMissing('tickets_it', ['ticket_id' => $bare->id]);
        $this->assertDatabaseMissing('ticket_date_activities', ['ticket_id' => $bare->id]);
    }

    public function test_comment_and_department_factories(): void
    {
        $comment = CommentFactory::new()->create();

        $this->assertNotNull($comment->ticket_id);
        $this->assertNotNull(DepartmentFactory::named('Records')->id);
    }
}
