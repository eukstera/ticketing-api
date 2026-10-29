<?php

namespace Tests\Feature\Reports;

use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketConfirmation;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use App\Models\TicketSurvey;
use App\Models\User;
use Database\Factories\TicketConfirmationFactory;
use Database\Factories\TicketFactory;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\ApiTestCase;

class ConsolidatedReportTest extends ApiTestCase
{
    private const RANGE = ['from' => '2026-05-01', 'to' => '2026-05-31'];

    private function encoder(): User
    {
        return User::factory()->inDepartment($this->itDepartment())->encoder()->create();
    }

    private function statusId(string $label): int
    {
        return (int) Status::where('label', $label)->value('id');
    }

    private function ticket(string $requestedAt, array $attributes = [], ?User $handler = null, string $area = 'Pharmacy'): Ticket
    {
        $factory = TicketFactory::new()->forDepartment($this->itDepartment())->requestedAt($requestedAt);
        $ticket = ($handler ? $factory->assigned($handler) : $factory->open())->create($attributes);
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['name_of_area' => $area]);

        return $ticket;
    }

    private function complete(Ticket $ticket, string $at, string $notes = 'Replaced the faulty power supply.'): TicketConfirmation
    {
        $ticket->update(['status_id' => $this->statusId('Completed')]);
        TicketDateActivity::unguarded(fn () => TicketDateActivity::create([
            'ticket_id' => $ticket->id,
            'date_category_id' => 3,
            'user_id' => $ticket->assigned_user,
            'status' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]));

        return TicketConfirmationFactory::new()->completedAt($at)->create([
            'ticket_id' => $ticket->id,
            'completed_by' => $ticket->assigned_user,
            'completion_notes' => $notes,
            'state' => TicketConfirmation::CONFIRMED,
        ]);
    }

    private function survey(TicketConfirmation $confirmation, int $score): void
    {
        TicketSurvey::create(array_merge(
            ['ticket_confirmation_id' => $confirmation->id, 'ticket_id' => $confirmation->ticket_id, 'submitted_at' => now()],
            array_fill_keys(TicketSurvey::BLOCKS, $score),
        ));
    }

    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/reports/consolidated?'.http_build_query(self::RANGE + $query));
    }

    // ------------------------------------------------------------- access

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/reports/consolidated')->assertUnauthorized();
        $this->getJson('/api/reports/consolidated/options')->assertUnauthorized();
        $this->getJson('/api/reports/consolidated/export')->assertUnauthorized();
    }

    public function test_normal_users_are_refused(): void
    {
        $this->actingAsUser($this->itUser());

        foreach (['', '/options', '/export'] as $path) {
            $this->getJson('/api/reports/consolidated'.$path)
                ->assertForbidden()
                ->assertJsonPath('message', 'Only Encoders and Department Heads can view the consolidated report.');
        }
    }

    public function test_encoders_and_department_heads_can_read_it(): void
    {
        $this->actingAsUser($this->encoder());
        $this->report()->assertOk()->assertJsonPath('success', true);

        $this->actingAsAdmin();
        $this->report()->assertOk();
    }

    public function test_it_only_covers_the_callers_own_department(): void
    {
        $this->actingAsUser($this->encoder());
        $mine = $this->ticket('2026-05-04 09:00:00');
        TicketFactory::new()->forDepartment($this->engineeringDepartment())->requestedAt('2026-05-04 09:00:00')->open()->create();

        $response = $this->report()->assertOk()->assertJsonPath('result.summary.total', 1);

        $this->assertSame([$mine->ticket_number], array_column($response->json('result.tickets.data'), 'ticket_number'));
    }

    // ------------------------------------------------------------- data

    public function test_kpis_breakdowns_and_rows(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create(['first_name' => 'Anna', 'last_name' => 'Reyes']);
        $ben = User::factory()->inDepartment($this->itDepartment())->create(['first_name' => 'Ben', 'last_name' => 'Cruz']);

        $fast = $this->ticket('2026-05-04 09:00:00', [], $anna, 'Pharmacy');
        $first = $this->complete($fast, '2026-05-04 11:30:00', 'Fixed the printer queue.');
        $this->survey($first, 5);
        $slow = $this->ticket('2026-05-05 09:00:00', [], $ben, 'Pharmacy');
        $this->complete($slow, '2026-06-20 09:00:00');
        $this->ticket('2026-05-06 09:00:00', [], $anna, 'Laboratory')->update(['status_id' => $this->statusId('Overdue')]);
        $this->ticket('2026-05-07 09:00:00')->update(['status_id' => $this->statusId('False Alarm')]);

        $result = $this->report()->assertOk()->json('result');

        $this->assertSame(4, $result['summary']['total']);
        $this->assertSame(2, $result['summary']['completed']);
        $this->assertSame(1, $result['summary']['overdue']);
        $this->assertEquals(25.0, $result['summary']['false_alarm_rate_percent']);
        $this->assertEquals(5.0, $result['summary']['avg_satisfaction']);
        $this->assertEquals(50.0, $result['summary']['within_sla_percent']);
        $this->assertSame(0, $result['summary']['disputes']);

        $statusCounts = array_column($result['breakdowns']['by_status'], 'count', 'label');
        $this->assertEquals(['Completed' => 2, 'Overdue' => 1, 'False Alarm' => 1, 'Open' => 0], array_intersect_key($statusCounts, array_flip(['Completed', 'Overdue', 'False Alarm', 'Open'])));
        $this->assertSame(['Pharmacy' => 3, 'Laboratory' => 1], array_column($result['breakdowns']['by_requesting_area'], 'count', 'name'));

        $staff = array_column($result['breakdowns']['by_staff'], null, 'name');
        $this->assertSame(2, $staff['Anna Reyes']['handled']);
        $this->assertSame(1, $staff['Anna Reyes']['completed']);
        $this->assertSame(150, $staff['Anna Reyes']['avg_resolution_minutes']);
        $this->assertEquals(100.0, $staff['Anna Reyes']['within_sla_percent']);
        $this->assertEquals(0.0, $staff['Ben Cruz']['within_sla_percent']);

        $row = collect($result['tickets']['data'])->firstWhere('ticket_number', $fast->ticket_number);
        $this->assertSame(150, $row['resolution_minutes']);
        $this->assertTrue($row['within_sla']);
        $this->assertEquals(5.0, $row['survey_score']);
        $this->assertSame('Fixed the printer queue.', $row['action_taken']);
        $this->assertSame('Anna Reyes', $row['handled_by']);
        $this->assertSame(4, $result['tickets']['total']);
        $this->assertSame(1, $result['satisfaction']['responses']);
        $this->assertEquals(50.0, $result['satisfaction']['response_rate_percent']);
    }

    public function test_filters_narrow_the_report(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $pharmacy = $this->ticket('2026-05-04 09:00:00', [], $anna, 'Pharmacy');
        $lab = $this->ticket('2026-05-05 09:00:00', [], null, 'Laboratory');
        $this->ticket('2026-04-05 09:00:00');

        $this->report()->assertJsonPath('result.summary.total', 2);
        $this->report(['requesting_area' => ['Laboratory']])->assertJsonPath('result.summary.total', 1)
            ->assertJsonPath('result.tickets.data.0.ticket_number', $lab->ticket_number);
        $this->report(['staff_id' => [$anna->id]])->assertJsonPath('result.tickets.data.0.ticket_number', $pharmacy->ticket_number)
            ->assertJsonPath('result.summary.total', 1);
        $this->report(['status_id' => [$this->statusId('Open')]])->assertJsonPath('result.summary.total', 1);
        $this->report(['priority_id' => [$pharmacy->priority_id], 'status_id' => [$this->statusId('In-progress')]])->assertJsonPath('result.summary.total', 1);
        $this->getJson('/api/reports/consolidated?from=2026-04-01&to=2026-04-30')->assertJsonPath('result.summary.total', 1);
    }

    public function test_the_date_basis_can_be_the_completion_date(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = $this->ticket('2026-04-28 09:00:00', [], $anna);
        $this->complete($ticket, '2026-05-02 10:00:00');

        $this->report()->assertJsonPath('result.summary.total', 0);
        $this->report(['date_field' => 'completed'])->assertJsonPath('result.summary.total', 1);
    }

    public function test_disputes_and_the_latest_completion_notes_are_reported(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = $this->ticket('2026-05-04 09:00:00', [], $anna);
        TicketConfirmationFactory::new()->completedAt('2026-05-04 10:00:00')->create([
            'ticket_id' => $ticket->id,
            'completed_by' => $anna->id,
            'completion_notes' => 'First attempt',
            'state' => TicketConfirmation::DISPUTED,
            'dispute_reason' => 'Still broken',
            'responded_at' => '2026-05-04 12:00:00',
        ]);
        $this->complete($ticket, '2026-05-06 10:00:00', 'Second and final fix');

        $result = $this->report()->assertOk()->json('result');

        $this->assertSame(1, $result['summary']['disputes']);
        $this->assertSame('Second and final fix', $result['tickets']['data'][0]['action_taken']);
        $this->assertTrue($result['tickets']['data'][0]['disputed']);
        $this->assertSame('Still broken', $result['satisfaction']['disputes'][0]['reason']);
    }

    private function voidedBlank(User $owner, string $printedAt, string $voidedAt, string $reason = 'Form was spoiled'): Ticket
    {
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($owner)->create(['status_id' => $this->statusId('Void')]);
        TicketDateActivity::unguarded(function () use ($ticket, $owner, $printedAt, $voidedAt, $reason) {
            TicketDateActivity::create(['ticket_id' => $ticket->id, 'date_category_id' => 9, 'user_id' => $owner->id, 'status' => 1, 'created_at' => $printedAt, 'updated_at' => $printedAt]);
            TicketDateActivity::create(['ticket_id' => $ticket->id, 'date_category_id' => 11, 'user_id' => $owner->id, 'status' => 1, 'remarks' => $reason, 'created_at' => $voidedAt, 'updated_at' => $voidedAt]);
        });
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)->delete();

        return $ticket;
    }

    public function test_voided_blank_job_orders_count_without_diluting_the_rates(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create(['first_name' => 'Anna', 'last_name' => 'Reyes']);
        $done = $this->ticket('2026-05-04 09:00:00', [], $anna);
        $this->complete($done, '2026-05-04 11:30:00');
        $voided = $this->voidedBlank($anna, '2026-05-06 08:00:00', '2026-05-06 10:00:00', 'Spoiled form');

        $result = $this->report()->assertOk()->json('result');

        $this->assertSame(2, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['voided']);
        $this->assertSame(1, $result['summary']['completed']);
        $this->assertEquals(100.0, $result['summary']['within_sla_percent']);
        $this->assertSame(0, $result['summary']['false_alarms']);
        $this->assertEquals(0.0, $result['summary']['false_alarm_rate_percent']);

        $statusCounts = array_column($result['breakdowns']['by_status'], 'count', 'label');
        $this->assertSame(1, $statusCounts['Void']);
        $this->assertSame(8, collect($result['breakdowns']['by_status'])->firstWhere('label', 'Void')['id']);
        $this->assertSame([], array_filter(array_column($result['breakdowns']['by_requesting_area'], 'name'), fn ($n) => $n === 'Unspecified'));
        $this->assertSame(1, $result['breakdowns']['by_staff'][0]['handled']);

        $row = collect($result['tickets']['data'])->firstWhere('ticket_number', $voided->ticket_number);
        $this->assertSame('Void', $row['status']);
        $this->assertTrue($row['is_void']);
        $this->assertSame('Spoiled form', $row['void_reason']);
        $this->assertNotNull($row['voided_at']);
        $this->assertNull($row['requester']);
        $this->assertNull($row['priority']);
        $this->assertNull($row['within_sla']);
        $this->assertNull($row['resolution_minutes']);
    }

    public function test_a_voided_job_order_is_dated_by_its_print_time_or_by_its_void_time(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $this->voidedBlank($anna, '2026-04-30 08:00:00', '2026-05-02 10:00:00');

        $this->report()->assertJsonPath('result.summary.voided', 0);
        $this->report(['date_field' => 'completed'])->assertJsonPath('result.summary.voided', 1);
        $this->getJson('/api/reports/consolidated?from=2026-04-30&to=2026-04-30')->assertJsonPath('result.summary.voided', 1);
    }

    public function test_voided_forms_leave_the_report_when_a_priority_concern_or_area_filter_is_set(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $ticket = $this->voidedBlank($anna, '2026-05-06 08:00:00', '2026-05-06 10:00:00');

        $this->report(['priority_id' => [$ticket->priority_id]])->assertJsonPath('result.summary.voided', 0);
        $this->report(['requesting_area' => ['Pharmacy']])->assertJsonPath('result.summary.voided', 0);
        $this->report(['status_id' => [$this->statusId('Void')]])->assertJsonPath('result.summary.voided', 1);
        $this->report(['staff_id' => [$anna->id]])->assertJsonPath('result.summary.voided', 1);
    }

    public function test_the_workbook_has_a_voided_tile_and_the_void_reason_column(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create();
        $this->voidedBlank($anna, '2026-05-06 08:00:00', '2026-05-06 10:00:00', 'Spoiled form');

        $response = $this->get('/api/reports/consolidated/export?'.http_build_query(self::RANGE))->assertOk();
        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());

        $tickets = $spreadsheet->getSheetByName('Tickets');
        $this->assertSame('Void reason', $tickets->getCell('P1')->getValue());
        $this->assertSame('Void', $tickets->getCell('G2')->getValue());
        $this->assertSame('Spoiled form', $tickets->getCell('P2')->getValue());

        $summary = $spreadsheet->getSheetByName('Summary');
        $labels = [];
        foreach ($summary->getRowIterator(13, 20) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $labels[] = $cell->getValue();
            }
        }
        $this->assertContains('Voided', $labels);
    }

    public function test_pagination_and_validation(): void
    {
        $this->actingAsUser($this->encoder());
        foreach (range(4, 8) as $day) {
            $this->ticket("2026-05-0{$day} 09:00:00");
        }

        $page = $this->report(['per_page' => 2, 'page' => 3])->assertOk();
        $this->assertCount(1, $page->json('result.tickets.data'));
        $this->assertSame(5, $page->json('result.tickets.total'));
        $page->assertJsonPath('result.summary.total', 5);

        $this->report(['per_page' => 500])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->report(['date_field' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['date_field']);
        $this->getJson('/api/reports/consolidated?from=2026-05-10&to=2026-05-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_options_list_the_filter_choices_of_the_callers_department(): void
    {
        $this->actingAsUser($this->encoder());
        $this->ticket('2026-05-04 09:00:00', [], null, 'Pharmacy');
        $staff = User::factory()->inDepartment($this->itDepartment())->create(['first_name' => 'Anna', 'last_name' => 'Reyes']);
        User::factory()->inDepartment($this->engineeringDepartment())->create(['first_name' => 'Other', 'last_name' => 'Dept']);

        $result = $this->getJson('/api/reports/consolidated/options')->assertOk()->json('result');

        $this->assertContains('Pharmacy', array_column($result['requesting_areas'], 'id'));
        $this->assertContains($staff->id, array_column($result['staff'], 'id'));
        $this->assertNotContains('Other Dept', array_column($result['staff'], 'name'));
        $this->assertNotEmpty($result['statuses']);
        $this->assertNotEmpty($result['priorities']);
        $this->assertNotEmpty($result['concern_types']);
        $this->assertSame(['requested', 'completed'], array_column($result['date_fields'], 'id'));
    }

    // ------------------------------------------------------------- export

    public function test_export_is_a_workbook_with_the_four_sheets_and_native_charts(): void
    {
        $this->actingAsUser($this->encoder());
        $anna = User::factory()->inDepartment($this->itDepartment())->create(['first_name' => 'Anna', 'last_name' => 'Reyes']);
        $ticket = $this->ticket('2026-05-04 09:00:00', [], $anna);
        $this->survey($this->complete($ticket, '2026-05-04 11:30:00'), 4);
        $this->ticket('2026-05-05 09:00:00')->update(['status_id' => $this->statusId('Overdue')]);

        $response = $this->get('/api/reports/consolidated/export?'.http_build_query(self::RANGE))->assertOk();

        $this->assertStringContainsString('consolidation_2026-05-01_2026-05-31.xlsx', $response->headers->get('Content-Disposition'));
        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);

        $this->assertSame(['Summary', 'Tickets', 'Staff', 'Satisfaction'], $spreadsheet->getSheetNames());
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());

        $tickets = $spreadsheet->getSheetByName('Tickets');
        $this->assertSame('Ticket no.', $tickets->getCell('A1')->getValue());
        $this->assertSame('B2', $tickets->getFreezePane());
        $this->assertSame(3, $tickets->getHighestRow());
        $this->assertSame($ticket->ticket_number, $tickets->getCell('A3')->getValue());
        $this->assertSame(2.5, (float) $tickets->getCell('J3')->getValue());
        $this->assertSame('Yes', $tickets->getCell('L3')->getValue());
        $this->assertNull($tickets->getCell('J2')->getValue());

        $staff = $spreadsheet->getSheetByName('Staff');
        $this->assertSame('Anna Reyes', $staff->getCell('A2')->getValue());
        $this->assertSame('=SUM(B2:B2)', $staff->getCell('B3')->getValue());

        $zip = new \ZipArchive;
        $zip->open($path);
        foreach ([1, 2, 3, 4, 5] as $chart) {
            $this->assertNotFalse($zip->locateName("xl/charts/chart{$chart}.xml"));
        }
        $zip->close();
    }

    public function test_export_follows_the_same_access_and_filters(): void
    {
        $this->actingAsUser($this->itUser());
        $this->get('/api/reports/consolidated/export')->assertForbidden();

        $this->actingAsUser($this->encoder());
        $this->get('/api/reports/consolidated/export?from=2026-05-10&to=2026-05-01', ['Accept' => 'application/json'])->assertUnprocessable();
        $this->get('/api/reports/consolidated/export?'.http_build_query(self::RANGE))->assertOk();
    }

    public function test_an_empty_report_still_exports(): void
    {
        $this->actingAsUser($this->encoder());

        $this->get('/api/reports/consolidated/export?'.http_build_query(self::RANGE))->assertOk();
    }
}
