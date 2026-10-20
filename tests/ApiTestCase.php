<?php

namespace Tests;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\TicketFactory;
use Tests\Concerns\SeedsReferenceData;

/**
 * Base class for API feature tests: seeds the reference rows and offers
 * small builders for the common IT / Engineering actors.
 */
abstract class ApiTestCase extends TestCase
{
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    protected function itUser(): User
    {
        return User::factory()->inDepartment($this->itDepartment())->create();
    }

    protected function engineeringUser(): User
    {
        return User::factory()->inDepartment($this->engineeringDepartment())->create();
    }

    protected function openItTicket(): Ticket
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->open()->create();
    }

    /**
     * Standard saveRequestTicketIT payload for the given assignee.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function requestTicketPayload(User $assignee, array $overrides = []): array
    {
        return array_merge([
            'service_department' => $this->itDepartment()->id,
            'area_supervisor' => 'Jane Supervisor',
            'type_of_concern' => 'Hardware',
            'devices' => [['type' => 'Monitor', 'quantity' => 2]],
            'name_of_area' => 'Pharmacy',
            'remarks' => 'Screen flickers',
            'assigned_user' => $assignee->id,
            'priority' => 1,
            'requestor' => 'Juan Dela Cruz',
        ], $overrides);
    }
}
