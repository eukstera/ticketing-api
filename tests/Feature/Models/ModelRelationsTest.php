<?php

namespace Tests\Feature\Models;

use App\Models\Comment;
use App\Models\Department;
use App\Models\Device;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use SeedsReferenceData;

    public function test_user_relations_resolve(): void
    {
        $this->seedReferenceData();
        $user = User::factory()->departmentHead()->inDepartment($this->engineeringDepartment())->create();

        $this->assertSame('Department Head', $user->role->role);
        $this->assertSame('Engineering', $user->department->department);
        $this->assertSame($user->department_id, $user->designation->department_id);
    }

    public function test_user_casts_status_to_boolean_and_hashes_passwords_once(): void
    {
        $this->seedReferenceData();
        $user = User::factory()->create(['password' => 'Secret@45']);
        $stored = $user->fresh();

        $this->assertTrue($stored->status);
        $this->assertTrue(Hash::check('Secret@45', $stored->password));

        $stored->update(['password' => $stored->password]);
        $this->assertTrue(Hash::check('Secret@45', $stored->fresh()->password));
        $this->assertArrayNotHasKey('password', $stored->toArray());
    }

    public function test_ticket_relations_and_casts(): void
    {
        $this->seedReferenceData();
        $user = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = Ticket::factory()->assigned($user)->create();
        $ticket = Ticket::find($ticket->id);

        $this->assertSame('In-progress', $ticket->ticketStatus->label);
        $this->assertSame('IT', $ticket->handlingDepartment->department);
        $this->assertTrue($ticket->assignedUser->is($user));
        $this->assertInstanceOf(Priority::class, $ticket->priority);
        $this->assertNotNull($ticket->itDetails);
        $this->assertNull($ticket->engineeringDetails);
        $this->assertIsInt($ticket->status_id);
        $this->assertIsInt($ticket->assigned_user);
        $this->assertIsInt($ticket->department);
        $this->assertIsBool($ticket->status);
    }

    public function test_engineering_ticket_exposes_engineering_details(): void
    {
        $this->seedReferenceData();
        $ticket = Ticket::factory()->forDepartment($this->engineeringDepartment())->create();

        $this->assertNotNull($ticket->fresh()->engineeringDetails);
        $this->assertNull($ticket->fresh()->itDetails);
    }

    public function test_priority_casts_and_department_relation(): void
    {
        $this->seedReferenceData();
        $priority = Priority::query()->where('department_id', 1)->where('level', 'Long Term')->firstOrFail();

        $this->assertSame(10, $priority->sla_days);
        $this->assertSame(1, $priority->department_id);
        $this->assertSame('IT', $priority->department->department);
        $this->assertTrue($priority->status);
    }

    public function test_device_quantity_is_mass_assignable(): void
    {
        $ticket = Ticket::factory()->withoutDetails()->create();

        $device = Device::create(['ticket_id' => $ticket->id, 'device' => 'Printer', 'qty' => 3]);

        $this->assertSame(3, $device->fresh()->qty);
        $this->assertTrue($device->ticket->is($ticket));
    }

    public function test_comment_relations(): void
    {
        $ticket = Ticket::factory()->create();
        $author = User::factory()->create();
        $comment = Comment::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $author->id]);

        $this->assertTrue($comment->ticket->is($ticket));
        $this->assertTrue($comment->user->is($author));
        $this->assertTrue($author->comments->first()->is($comment));
    }

    public function test_department_lists_its_users_and_designations(): void
    {
        $this->seedReferenceData();
        $department = Department::find(1);
        User::factory()->inDepartment($department)->count(2)->create();

        $this->assertCount(2, $department->users);
        $this->assertTrue($department->designations->isNotEmpty());
        $this->assertTrue($department->priorities->isNotEmpty());
    }
}
