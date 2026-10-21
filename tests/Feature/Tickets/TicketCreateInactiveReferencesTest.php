<?php

namespace Tests\Feature\Tickets;

use App\Models\Department;
use App\Models\Priority;
use App\Models\TypeOfConcern;
use App\Models\User;
use Tests\ApiTestCase;

class TicketCreateInactiveReferencesTest extends ApiTestCase
{
    public function test_inactive_department_priority_concern_and_assignee_are_rejected(): void
    {
        $this->actingAsUser($this->itUser());
        $assignee = $this->itUser();
        $payload = $this->requestTicketPayload($assignee, ['type_of_concern' => 'Software']);

        Department::query()->whereKey($payload['service_department'])->update(['status' => false]);
        Priority::query()->whereKey($payload['priority'])->update(['status' => false]);
        TypeOfConcern::firstOrCreate(['label' => 'Software'])->update(['status' => false]);
        User::query()->whereKey($assignee->id)->update(['status' => false]);

        $this->postJson('/api/saveRequestTicketIT', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_department', 'priority', 'type_of_concern', 'assigned_user']);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_active_references_are_still_accepted(): void
    {
        $this->actingAsUser($this->itUser());

        $this->postJson('/api/saveRequestTicketIT', $this->requestTicketPayload($this->itUser()))
            ->assertCreated();
    }

    public function test_public_ticket_routes_still_accept_inactive_priorities(): void
    {
        Priority::query()->whereKey(1)->update(['status' => false]);

        $this->postJson('/api/saveTicketIT', [
            'service_department' => $this->itDepartment()->id,
            'area_supervisor' => 'Jane Supervisor',
            'type_of_concern' => 'Software',
            'name_of_area' => 'Pharmacy',
            'remarks' => 'Cannot log in',
            'priority' => 1,
            'requestor' => 'Juan Dela Cruz',
            'requestor_meta' => ['id' => 77],
        ])->assertCreated();
    }
}
