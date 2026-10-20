<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Database\Factories\NatureOfWorkFactory;
use Database\Factories\StatusFactory;
use Database\Factories\TicketDateActivityFactory;
use Database\Factories\TicketEngineeringFactory;
use Database\Factories\TicketFactory;
use Database\Factories\TicketITFactory;
use Database\Factories\TypeOfConcernFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

#[Group('phase2')]
class TicketListTest extends ApiTestCase
{
    private const URL = '/api/getDepartmentTickets';

    /**
     * Builds an IT ticket with explicit searchable details.
     *
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $details
     */
    private function itTicket(
        array $ticket = [],
        array $details = [],
        ?string $requestor = null,
        string $requestedAt = '2026-05-10 09:00:00',
    ): Ticket {
        $record = TicketFactory::new()->withoutDetails()->forDepartment($this->itDepartment())
            ->requestedAt($requestedAt)->create($ticket);

        TicketITFactory::new()->state(['ticket_id' => $record->id])->create($details);
        TicketDateActivityFactory::new()->for($record, 'ticket')->requested()
            ->create(['requestor' => $requestor ?? 'Some Requester', 'created_at' => $requestedAt]);

        return $record;
    }

    /**
     * @return list<int>
     */
    private function listedIds(string $query = ''): array
    {
        return array_column(
            $this->getJson(self::URL.($query === '' ? '' : '?'.$query))->assertOk()->json('result.ticket.data'),
            'id'
        );
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson('/api/getMyTickets')->assertUnauthorized();
    }

    public function test_rows_carry_ids_next_to_labels_and_only_the_callers_department(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketFactory::new()->forDepartment($this->engineeringDepartment())->create();

        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('success', true);

        $this->assertSame([$ticket->id], array_column($response->json('result.ticket.data'), 'id'));
        $row = $response->json('result.ticket.data.0');
        $this->assertSame($this->itDepartment()->id, $row['department_id']);
        $this->assertSame((int) $ticket->status_id, $row['status_id']);
        $this->assertSame((int) $ticket->priority_id, $row['priority_id']);
        $this->assertSame('Open', $row['status']);
        $this->assertIsString($row['priority']);
        $this->assertArrayHasKey('priority_sla', $row);
    }

    public function test_per_page_defaults_to_ten_and_is_bounded(): void
    {
        $this->actingAsUser($this->itUser());
        foreach (range(1, 12) as $i) {
            $this->itTicket();
        }

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('result.ticket.per_page', 10)
            ->assertJsonCount(10, 'result.ticket.data')
            ->assertJsonPath('result.ticket.total', 12);
        $this->getJson(self::URL.'?per_page=5&page=3')->assertOk()->assertJsonCount(2, 'result.ticket.data');
        $this->getJson(self::URL.'?per_page=100')->assertOk();
        $this->getJson(self::URL.'?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson(self::URL.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_search_matches_ticket_number_and_area_case_insensitively(): void
    {
        $this->actingAsUser($this->itUser());
        $byNumber = $this->itTicket(['ticket_number' => '05-777-2026']);
        $byArea = $this->itTicket([], ['name_of_area' => 'Radiology Wing']);
        $this->itTicket();

        $this->assertSame([$byNumber->id], $this->listedIds('search=777'));
        $this->assertSame([$byArea->id], $this->listedIds('search=RADIOLOGY'));
    }

    public function test_search_matches_the_requester_name(): void
    {
        $this->actingAsUser($this->itUser());
        $text = $this->itTicket([], [], 'Zelda Fitzgerald');
        $this->itTicket();

        $this->assertSame([$text->id], $this->listedIds('search=fitzgerald'));

        $requester = User::factory()->create(['first_name' => 'Quentin', 'last_name' => 'Blake']);
        $linked = $this->itTicket();
        TicketDateActivityFactory::new()->for($linked, 'ticket')->requested($requester)->create();

        $this->assertContains($linked->id, $this->listedIds('search=Quentin%20Blake'));
    }

    public function test_search_matches_the_concern_type_or_nature_of_work(): void
    {
        $this->actingAsUser($this->itUser());
        $concern = TypeOfConcernFactory::new()->create(['label' => 'Biometrics']);
        $byConcern = $this->itTicket([], ['type_of_concern' => $concern->id]);
        $this->itTicket();

        $this->assertSame([$byConcern->id], $this->listedIds('search=biometric'));

        $head = $this->actingAsUser($this->engineeringUser());
        $nature = NatureOfWorkFactory::new()->create(['label' => 'Plumbing']);
        $engineering = TicketFactory::new()->withoutDetails()->forDepartment($this->engineeringDepartment())->create();
        TicketEngineeringFactory::new()->state(['ticket_id' => $engineering->id])->create(['nature_of_work' => $nature->id]);

        $ids = array_column(
            $this->getJson(self::URL.'?search=plumb')->assertOk()->json('result.ticket.data'),
            'id'
        );
        $this->assertSame([$engineering->id], $ids);
        $this->assertNotNull($head);
    }

    public function test_status_and_priority_can_be_filtered_by_id(): void
    {
        $this->actingAsUser($this->itUser());
        $overdueId = StatusFactory::labelled('Overdue')->id;
        $open = $this->itTicket(['priority_id' => 1]);
        $overdue = $this->itTicket(['priority_id' => 2, 'status_id' => $overdueId]);

        $this->assertSame([$overdue->id], $this->listedIds("status_id={$overdueId}"));
        $this->assertSame([$open->id], $this->listedIds('priority_id=1'));
        $this->assertSame([], $this->listedIds("status_id={$overdueId}&priority_id=1"));
    }

    public function test_legacy_label_filters_still_work_and_ids_take_precedence(): void
    {
        $this->actingAsUser($this->itUser());
        $overdueId = StatusFactory::labelled('Overdue')->id;
        $open = $this->itTicket();
        $overdue = $this->itTicket(['status_id' => $overdueId]);

        $this->assertSame([$overdue->id], $this->listedIds('status=Overdue'));
        $this->assertSame([$open->id], $this->listedIds('status=Overdue&status_id=1'));
        $this->assertSame([$open->id], $this->listedIds('priority=Urgent%20%26%20IHOMIS%20Related&status=Open'));
    }

    public function test_date_range_is_inclusive_on_the_requested_date(): void
    {
        $this->actingAsUser($this->itUser());
        $before = $this->itTicket([], [], null, '2026-05-09 23:59:59');
        $first = $this->itTicket([], [], null, '2026-05-10 00:00:00');
        $last = $this->itTicket([], [], null, '2026-05-12 23:59:59');
        $after = $this->itTicket([], [], null, '2026-05-13 00:00:00');

        $ids = $this->listedIds('date_from=2026-05-10&date_to=2026-05-12');

        $this->assertEqualsCanonicalizing([$first->id, $last->id], $ids);
        $this->assertContains($after->id, $this->listedIds('date_from=2026-05-13'));
        $this->assertNotContains($before->id, $this->listedIds('date_from=2026-05-10'));
        $this->assertContains($before->id, $this->listedIds('date_to=2026-05-09'));
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson(self::URL.'?date_from=2026-05-12&date_to=2026-05-10')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->getJson(self::URL.'?date_from=12/05/2026')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_from']);
        $this->getJson(self::URL.'?date_from=2026-05-10&date_to=2026-05-10')->assertOk();
    }

    public function test_sorting(): void
    {
        $this->actingAsUser($this->itUser());
        $a = $this->itTicket(['ticket_number' => '05-001-2026'], [], null, '2026-05-01 08:00:00');
        $b = $this->itTicket(['ticket_number' => '05-002-2026'], [], null, '2026-05-03 08:00:00');
        $c = $this->itTicket(['ticket_number' => '05-003-2026'], [], null, '2026-05-02 08:00:00');

        $this->assertSame([$b->id, $c->id, $a->id], $this->listedIds(), 'default is requested_at desc');
        $this->assertSame([$a->id, $c->id, $b->id], $this->listedIds('sort=requested_at&dir=asc'));
        $this->assertSame([$a->id, $b->id, $c->id], $this->listedIds('sort=ticket_number&dir=asc'));
        $this->assertSame([$c->id, $b->id, $a->id], $this->listedIds('sort=ticket_number&dir=desc'));
        $this->assertCount(3, $this->listedIds('sort=priority'));
        $this->assertCount(3, $this->listedIds('sort=status&dir=asc'));
    }

    public function test_invalid_sort_values_are_rejected(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson(self::URL.'?sort=password')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->getJson(self::URL.'?dir=sideways')->assertUnprocessable()->assertJsonValidationErrors(['dir']);
    }

    public function test_status_counts_are_an_array_of_id_label_count_with_all_first(): void
    {
        $this->actingAsUser($this->itUser());
        $this->itTicket();
        $this->itTicket();
        $this->itTicket(['status_id' => StatusFactory::labelled('Completed')->id]);

        $counts = $this->getJson(self::URL)->assertOk()->json('result.status_counts');

        $this->assertSame(['id' => null, 'label' => 'All', 'count' => 3], $counts[0]);
        $byLabel = collect($counts)->keyBy('label');
        $this->assertSame(['All', 'Open', 'In-progress', 'Completed', 'Overdue'], $byLabel->keys()->all());
        $this->assertSame(2, $byLabel['Open']['count']);
        $this->assertSame(0, $byLabel['In-progress']['count']);
        $this->assertSame(1, $byLabel['Completed']['count']);
        $this->assertSame(0, $byLabel['Overdue']['count']);
        $this->assertIsInt($byLabel['Open']['id']);
    }

    public function test_priority_counts_are_an_array_with_sla_days(): void
    {
        $this->actingAsUser($this->itUser());
        $this->itTicket(['priority_id' => 1]);
        $this->itTicket(['priority_id' => 1]);

        $counts = $this->getJson(self::URL)->assertOk()->json('result.priority_counts');

        $this->assertCount(4, $counts);
        $this->assertSame(['id', 'level', 'sla_days', 'count'], array_keys($counts[0]));
        $first = collect($counts)->firstWhere('id', 1);
        $this->assertSame(2, $first['count']);
        $this->assertIsInt($first['sla_days']);
        $this->assertSame(0, collect($counts)->firstWhere('id', 2)['count']);
    }

    public function test_counts_ignore_the_status_filter_but_respect_search_priority_and_dates(): void
    {
        $this->actingAsUser($this->itUser());
        $completedId = StatusFactory::labelled('Completed')->id;
        $this->itTicket(['priority_id' => 1], ['name_of_area' => 'Lab'], null, '2026-05-10 08:00:00');
        $this->itTicket(['priority_id' => 1, 'status_id' => $completedId], ['name_of_area' => 'Lab'], null, '2026-05-10 08:00:00');
        $this->itTicket(['priority_id' => 2], ['name_of_area' => 'Lab'], null, '2026-05-10 08:00:00');
        $this->itTicket(['priority_id' => 1], ['name_of_area' => 'Pharmacy'], null, '2026-05-10 08:00:00');
        $this->itTicket(['priority_id' => 1], ['name_of_area' => 'Lab'], null, '2026-06-20 08:00:00');

        $response = $this->getJson(self::URL.'?status_id='.$completedId.'&priority_id=1&search=Lab&date_from=2026-05-01&date_to=2026-05-31');

        $response->assertOk()->assertJsonCount(1, 'result.ticket.data');
        $statusCounts = collect($response->json('result.status_counts'))->keyBy('label');
        $this->assertSame(2, $statusCounts['All']['count']);
        $this->assertSame(1, $statusCounts['Open']['count']);
        $this->assertSame(1, $statusCounts['Completed']['count']);
    }

    public function test_my_tickets_only_lists_tickets_the_caller_is_part_of_and_counts_them(): void
    {
        $me = $this->actingAsUser($this->itUser());
        $mine = $this->itTicket();
        TicketDateActivityFactory::new()->for($mine, 'ticket')->assigned($me)->create();
        $notMine = $this->itTicket();

        $response = $this->getJson('/api/getMyTickets')->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('result.ticket.data'), 'id'));
        $this->assertSame(['id' => null, 'label' => 'All', 'count' => 1], $response->json('result.status_counts.0'));
        $this->assertNotContains($notMine->id, array_column($response->json('result.ticket.data'), 'id'));
    }

    public function test_my_tickets_supports_the_same_filters(): void
    {
        $me = $this->actingAsUser($this->itUser());
        $match = $this->itTicket([], ['name_of_area' => 'Radiology']);
        $other = $this->itTicket([], ['name_of_area' => 'Pharmacy']);
        foreach ([$match, $other] as $ticket) {
            TicketDateActivityFactory::new()->for($ticket, 'ticket')->assigned($me)->create();
        }

        $ids = array_column(
            $this->getJson('/api/getMyTickets?search=radio&per_page=5&sort=ticket_number&dir=asc')
                ->assertOk()->json('result.ticket.data'),
            'id'
        );

        $this->assertSame([$match->id], $ids);
    }
}
