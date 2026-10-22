<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketDateActivity;
use App\Models\TicketIT;
use App\Models\TypeOfConcern;
use App\Models\User;
use App\Services\JobOrderData;
use App\Services\JobOrderFormBuilder;
use App\Services\JobOrderPdfBuilder;
use Database\Factories\DesignationFactory;
use Database\Factories\DeviceFactory;
use Database\Factories\PriorityFactory;
use Database\Factories\TicketFactory;
use DOMDocument;
use RuntimeException;
use Tests\ApiTestCase;
use ZipArchive;

class JobOrderTest extends ApiTestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const SHEET_1 = 'xl/worksheets/sheet1.xml';

    private const SHEET_2 = 'xl/worksheets/sheet2.xml';

    private function itTicket(array $attributes = []): Ticket
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->open()
            ->create($attributes + ['ticket_number' => '10-003-2026']);
    }

    private function download(Ticket $ticket)
    {
        return $this->get("/api/tickets/{$ticket->id}/job-order");
    }

    private function filePath($response): string
    {
        return $response->assertOk()->baseResponse->getFile()->getPathname();
    }

    /**
     * Value of every non-empty cell of a sheet part, keyed by reference.
     *
     * @return array<string, string>
     */
    private function cells(string $path, string $part): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $strings = [];
        $shared = new DOMDocument;
        $shared->loadXML($zip->getFromName('xl/sharedStrings.xml'));
        foreach ($shared->getElementsByTagNameNS(self::NS, 'si') as $i => $item) {
            $strings[$i] = $item->textContent;
        }

        $sheet = new DOMDocument;
        $sheet->loadXML($zip->getFromName($part));
        $zip->close();

        $cells = [];
        foreach ($sheet->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            $type = $cell->getAttribute('t');
            $value = $cell->getElementsByTagNameNS(self::NS, 'v')->item(0)?->textContent;

            if ($type === 'inlineStr') {
                $cells[$cell->getAttribute('r')] = $cell->textContent;
            } elseif ($value !== null) {
                $cells[$cell->getAttribute('r')] = $type === 's' ? $strings[(int) $value] : $value;
            }
        }

        return $cells;
    }

    private function templateCells(string $part): array
    {
        return $this->cells(resource_path('templates/jo-form.xlsx'), $part);
    }

    /**
     * Cells whose value differs from the blank template.
     *
     * @return array<string, string>
     */
    private function changed(string $path, string $part): array
    {
        return array_diff_assoc($this->cells($path, $part), $this->templateCells($part));
    }

    private function setPriority(Ticket $ticket, string $level, int $slaDays = 3): void
    {
        $priority = PriorityFactory::new()->slaDays($slaDays)->create(['department_id' => 1, 'level' => $level]);
        $ticket->update(['priority_id' => $priority->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/tickets/{$this->itTicket()->id}/job-order")->assertUnauthorized();
    }

    public function test_other_department_staff_are_forbidden(): void
    {
        $this->actingAsUser($this->engineeringUser());

        $this->getJson('/api/tickets/'.$this->itTicket()->id.'/job-order')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only members of the handling department can download a job order.');
    }

    public function test_unknown_ticket_is_not_found(): void
    {
        $this->actingAsUser($this->itUser());

        $this->getJson('/api/tickets/9999/job-order')->assertNotFound();
    }

    public function test_department_staff_and_heads_can_download_with_the_right_headers(): void
    {
        $ticket = $this->itTicket();

        foreach ([$this->itUser(), User::factory()->inDepartment($this->engineeringDepartment())->departmentHead()->create()] as $user) {
            $this->actingAsUser($user);
            $response = $this->download($ticket)->assertOk();

            $this->assertSame(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $response->headers->get('Content-Type')
            );
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('JO 10-003-26.xlsx', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_false_alarm_and_engineering_tickets_are_conflicts(): void
    {
        $this->actingAsUser($this->itUser());
        $falseAlarm = $this->itTicket();
        $this->postJson("/api/tickets/{$falseAlarm->id}/false-alarm", ['reason' => 'Duplicate request'])->assertOk();

        $this->getJson("/api/tickets/{$falseAlarm->id}/job-order")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A false-alarm ticket has no job order.');

        $this->actingAsUser($this->engineeringUser());
        $engineering = TicketFactory::new()->forDepartment($this->engineeringDepartment())->open()->create();

        $this->getJson("/api/tickets/{$engineering->id}/job-order")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Job order forms are only available for IT tickets.');
    }

    public function test_open_in_progress_overdue_and_completed_tickets_are_allowed(): void
    {
        $this->actingAsUser($this->itUser());
        $assignee = $this->itUser();
        $department = $this->itDepartment();

        foreach ([
            TicketFactory::new()->forDepartment($department)->open()->create(),
            TicketFactory::new()->forDepartment($department)->assigned($assignee)->create(),
            TicketFactory::new()->forDepartment($department)->overdue()->create(),
            TicketFactory::new()->forDepartment($department)->completed($assignee)->create(),
        ] as $ticket) {
            $this->download($ticket)->assertOk();
        }
    }

    public function test_ticket_number_is_set_on_both_pages_with_a_two_digit_year(): void
    {
        $this->actingAsUser($this->itUser());
        $path = $this->filePath($this->download($this->itTicket()));

        $this->assertSame('10-003-26', $this->cells($path, self::SHEET_1)['E4']);
        $this->assertSame('10-003-26', $this->cells($path, self::SHEET_2)['C2']);
        $this->assertSame('10-100-26', $this->templateCells(self::SHEET_1)['E4']);
    }

    public function test_page_two_only_changes_the_job_order_number(): void
    {
        $this->actingAsUser($this->itUser());
        $path = $this->filePath($this->download($this->itTicket()));

        $this->assertSame(['C2' => '10-003-26'], $this->changed($path, self::SHEET_2));
    }

    public function test_an_open_ticket_fills_the_request_but_leaves_performance_blank(): void
    {
        $this->actingAsUser($this->itUser());
        $requester = User::factory()->inDepartment($this->engineeringDepartment())->create([
            'first_name' => 'Maria', 'last_name' => 'Santos',
            'designation_id' => DesignationFactory::forDepartmentId($this->engineeringDepartment()->id)->id,
        ]);
        $ticket = $this->itTicket();
        $this->setPriority($ticket, 'Urgent');
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['name_of_area' => 'Pharmacy', 'remarks' => 'Printer will not turn on']);
        TypeOfConcern::query()->whereKey(TicketIT::query()->where('ticket_id', $ticket->id)->value('type_of_concern'))->update(['label' => 'Hardware']);
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 1)
            ->update(['user_id' => $requester->id, 'requestor' => null, 'created_at' => '2026-10-20 08:00:00']);
        $ticket->devices()->delete();
        DeviceFactory::new()->state(['ticket_id' => $ticket->id, 'device' => 'Printer', 'qty' => 1])->create();

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        $this->assertSame('Pharmacy', $changed['E5']);
        $this->assertSame('Printer', $changed['E6']);
        $this->assertSame('Printer will not turn on', $changed['C13']);
        $this->assertSame('X', $changed['M6']);
        $this->assertArrayNotHasKey('M5', $changed);
        $this->assertArrayNotHasKey('M7', $changed);
        $this->assertArrayNotHasKey('M10', $changed);
        $this->assertSame('X', $changed['K25']);
        $this->assertSame('Maria Santos', $changed['A18']);
        $this->assertSame($requester->designation->designation, $changed['C20']);
        $this->assertSame('Oct 20, 2026', $changed['C21']);
        $this->assertArrayHasKey('N9', $changed);
        $this->assertArrayNotHasKey('N12', $changed);
        foreach (['C35', 'J35', 'J38'] as $blank) {
            $this->assertArrayNotHasKey($blank, $changed);
        }
    }

    public function test_a_taken_ticket_fills_start_and_performed_by(): void
    {
        $this->actingAsUser($this->itUser());
        $taker = $this->itUser();
        $taker->update(['first_name' => 'Leo', 'last_name' => 'Reyes']);
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($taker)->create();
        $assigned = TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 2)->firstOrFail();
        $assigned->forceFill(['created_at' => '2026-10-21 09:15:00', 'updated_at' => '2026-10-21 09:15:00'])->save();

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        $this->assertSame('Oct 21, 2026 9:15 AM', $changed['C35']);
        $this->assertSame('Leo Reyes', $changed['J35']);
        $this->assertSame($taker->designation->designation, $changed['J38']);
    }

    public function test_each_priority_ticks_its_box_and_the_expected_date_line(): void
    {
        $this->actingAsUser($this->itUser());

        foreach ([
            ['Urgent & IHOMIS Related', 'M5', 'N9'],
            ['Urgent', 'M6', 'N9'],
            ['Important but not urgent', 'M7', 'N9'],
            ['Long Term', 'M10', 'N12'],
        ] as [$level, $box, $dateCell]) {
            $ticket = $this->itTicket(['ticket_number' => '10-'.str_pad((string) random_int(10, 999), 3, '0', STR_PAD_LEFT).'-2026']);
            $this->setPriority($ticket, $level, 5);

            $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

            $this->assertSame('X', $changed[$box], $level);
            $this->assertSame(1, count(array_intersect_key($changed, array_flip(['M5', 'M6', 'M7', 'M10']))), $level);
            $this->assertArrayHasKey($dateCell, $changed, $level);
            $this->assertSame($ticket->fresh()->dueAt()->format('M j, Y'), $changed[$dateCell]);
        }
    }

    public function test_an_unknown_priority_ticks_nothing(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $this->setPriority($ticket, 'Whenever');

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        $this->assertSame([], array_intersect_key($changed, array_flip(['M5', 'M6', 'M7', 'M10', 'N9', 'N12'])));
    }

    public function test_concern_types_tick_their_category_or_others(): void
    {
        $this->actingAsUser($this->itUser());

        foreach (['Software' => 'K26', 'Network Connection' => 'K27', 'Audio/Video' => 'K29'] as $label => $box) {
            $ticket = $this->itTicket(['ticket_number' => '10-'.str_pad((string) random_int(10, 999), 3, '0', STR_PAD_LEFT).'-2026']);
            TypeOfConcern::query()->whereKey(TicketIT::query()->where('ticket_id', $ticket->id)->value('type_of_concern'))->update(['label' => $label]);

            $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

            $this->assertSame('X', $changed[$box], $label);
            $this->assertArrayNotHasKey('K31', $changed, $label);
            $this->assertArrayNotHasKey('L31', $changed, $label);
        }

        $ticket = $this->itTicket(['ticket_number' => '10-777-2026']);
        TypeOfConcern::query()->whereKey(TicketIT::query()->where('ticket_id', $ticket->id)->value('type_of_concern'))->update(['label' => 'Printer']);

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        $this->assertSame('X', $changed['K31']);
        $this->assertSame('Others (Pls. Specify) Printer', $changed['L31']);
    }

    public function test_long_text_is_wrapped_over_three_lines_then_truncated(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update([
            'remarks' => implode(' ', array_fill(0, 120, 'screen')),
            'name_of_area' => 'Department of Information and Communications Technology Section',
        ]);

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        foreach (['C13', 'C14', 'C15'] as $line) {
            $this->assertLessThanOrEqual(92, mb_strlen($changed[$line]), $line);
        }
        $this->assertStringEndsWith('…', $changed['C15']);
        $this->assertArrayNotHasKey('C16', $changed);
        $this->assertLessThanOrEqual(26, mb_strlen($changed['E5']));
        $this->assertStringEndsWith('…', $changed['E5']);
    }

    public function test_short_remarks_stay_on_the_first_line(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => 'Cannot log in']);

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        $this->assertSame('Cannot log in', $changed['C13']);
        $this->assertArrayNotHasKey('C14', $changed);
    }

    public function test_the_workbook_keeps_every_template_part_and_stays_well_formed(): void
    {
        $this->actingAsUser($this->itUser());
        $path = $this->filePath($this->download($this->itTicket()));

        $template = new ZipArchive;
        $output = new ZipArchive;
        $this->assertTrue($template->open(resource_path('templates/jo-form.xlsx')) === true);
        $this->assertTrue($output->open($path) === true);

        $names = fn (ZipArchive $zip) => collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
        $this->assertSame($names($template), $names($output));

        $edited = ['xl/sharedStrings.xml', 'xl/styles.xml', self::SHEET_1, self::SHEET_2, 'xl/workbook.xml'];
        foreach ($names($template) as $name) {
            if (in_array($name, $edited, true)) {
                continue;
            }
            $this->assertSame($template->getFromName($name), $output->getFromName($name), "{$name} changed");
        }

        foreach ($names($output) as $name) {
            if (preg_match('/\.(xml|rels|vml)$/', $name)) {
                $doc = new DOMDocument;
                $this->assertTrue(@$doc->loadXML($output->getFromName($name)), "{$name} is not well-formed");
            }
        }

        foreach (['xl/worksheets/_rels/sheet1.xml.rels', 'xl/worksheets/_rels/sheet2.xml.rels', 'xl/drawings/_rels/drawing1.xml.rels', 'xl/drawings/_rels/vmlDrawing1.vml.rels', 'xl/drawings/_rels/vmlDrawing2.vml.rels'] as $rels) {
            preg_match_all('/Target="([^"]+)"/', $output->getFromName($rels), $targets);
            foreach ($targets[1] as $target) {
                $resolved = 'xl/'.ltrim(str_replace('../', '', $target), '/');
                $this->assertNotFalse($output->locateName($resolved), "{$rels} points to missing {$resolved}");
            }
        }

        $this->assertStringContainsString('<legacyDrawingHF', $output->getFromName(self::SHEET_1));
        $this->assertStringContainsString('<drawing ', $output->getFromName(self::SHEET_2));
        $this->assertStringContainsString('activeTab="0"', $output->getFromName('xl/workbook.xml'));
        $this->assertStringNotContainsString('tabSelected', $output->getFromName(self::SHEET_2));
        $this->assertStringContainsString('tabSelected="1"', $output->getFromName(self::SHEET_1));
        $this->assertStringContainsString('Print_Area', $output->getFromName('xl/workbook.xml'));
    }

    public function test_only_intended_cells_of_page_one_change(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $this->setPriority($ticket, 'Long Term', 10);

        $path = $this->filePath($this->download($ticket->fresh()));
        $before = $this->templateCells(self::SHEET_1);
        $after = $this->cells($path, self::SHEET_1);

        $allowed = ['E4', 'E5', 'E6', 'C13', 'C14', 'C15', 'M5', 'M6', 'M7', 'M10', 'N9', 'N12', 'A18', 'C20', 'C21', 'K25', 'K26', 'K27', 'K28', 'K29', 'K30', 'K31', 'L31', 'C35', 'J35', 'J38'];
        foreach (array_keys(array_diff_assoc($after, $before)) as $ref) {
            $this->assertContains($ref, $allowed, "{$ref} was changed unexpectedly");
        }

        $this->assertSame([], array_diff_key($before, $after), 'a template cell value disappeared');
        foreach (['A2', 'N4', 'N5', 'A12', 'A23', 'C26', 'C28', 'C30', 'A33', 'K34'] as $label) {
            $this->assertSame($before[$label], $after[$label], "{$label} label changed");
        }
        $this->assertSame('X', $after['M10']);
        $this->assertArrayNotHasKey('N9', array_diff_assoc($after, $before));
    }

    public function test_generation_is_deterministic_and_changes_nothing(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $activities = TicketDateActivity::query()->count();
        $updatedAt = $ticket->fresh()->updated_at;

        $first = $this->cells($this->filePath($this->download($ticket)), self::SHEET_1);
        $second = $this->cells($this->filePath($this->download($ticket)), self::SHEET_1);

        $this->assertSame($first, $second);
        $this->assertSame($activities, TicketDateActivity::query()->count());
        $this->assertEquals($updatedAt, $ticket->fresh()->updated_at);
        $this->assertSame('Open', $ticket->fresh()->ticketStatus->label);
    }

    private function pdf(Ticket $ticket)
    {
        return $this->get("/api/tickets/{$ticket->id}/job-order?format=pdf");
    }

    /**
     * The HTML the PDF is rendered from, for a ticket.
     */
    private function replicaHtml(Ticket $ticket): string
    {
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

        return view('job-order.pdf', [
            'd' => JobOrderData::fromTicket($ticket->fresh()),
            'img' => ['header_job_order' => $pixel, 'header_survey' => $pixel, 'form_code_survey' => $pixel, 'smiley' => array_fill(0, 5, $pixel)],
        ])->render();
    }

    public function test_pdf_is_a_two_page_a4_download_with_the_right_headers(): void
    {
        $this->actingAsUser($this->itUser());
        $response = $this->pdf($this->itTicket())->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('JO 10-003-26.pdf', $response->headers->get('Content-Disposition'));

        $bytes = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page(?![s\w])/', $bytes));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595\.\d+\s+841\.\d+\s*\]/', $bytes);
    }

    public function test_xlsx_stays_the_default_and_can_be_asked_for_explicitly(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $spreadsheet = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $this->assertSame($spreadsheet, $this->download($ticket)->assertOk()->headers->get('Content-Type'));
        $this->assertSame($spreadsheet, $this->get("/api/tickets/{$ticket->id}/job-order?format=xlsx")->assertOk()->headers->get('Content-Type'));
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();

        foreach (['docx', 'PDF', 'xls', ''] as $format) {
            $response = $this->getJson("/api/tickets/{$ticket->id}/job-order?format={$format}");

            if ($format === '') {
                $response->assertOk();

                continue;
            }

            $response->assertUnprocessable()->assertJsonValidationErrors(['format']);
        }
    }

    public function test_pdf_permissions_and_statuses_match_the_workbook(): void
    {
        $ticket = $this->itTicket();
        $this->getJson("/api/tickets/{$ticket->id}/job-order?format=pdf")->assertUnauthorized();

        $this->actingAsUser($this->engineeringUser());
        $this->getJson("/api/tickets/{$ticket->id}/job-order?format=pdf")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only members of the handling department can download a job order.');

        $this->actingAsUser($this->itUser());
        $this->postJson("/api/tickets/{$ticket->id}/false-alarm", ['reason' => 'Duplicate request'])->assertOk();
        $this->getJson("/api/tickets/{$ticket->id}/job-order?format=pdf")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A false-alarm ticket has no job order.');

        $this->actingAsUser($this->engineeringUser());
        $engineering = TicketFactory::new()->forDepartment($this->engineeringDepartment())->open()->create();
        $this->getJson("/api/tickets/{$engineering->id}/job-order?format=pdf")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Job order forms are only available for IT tickets.');

        $this->actingAsAdmin();
        $open = $this->itTicket(['ticket_number' => '10-004-2026']);
        $this->pdf($open)->assertOk();
    }

    public function test_the_replica_shows_the_same_values_as_the_workbook(): void
    {
        $this->actingAsUser($this->itUser());
        $taker = $this->itUser();
        $taker->update(['first_name' => 'Leo', 'last_name' => 'Reyes']);
        $ticket = TicketFactory::new()->forDepartment($this->itDepartment())->assigned($taker)->create(['ticket_number' => '10-083-2026']);
        $this->setPriority($ticket, 'Urgent', 2);
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['name_of_area' => 'Pharmacy', 'remarks' => 'Printer will not turn on']);
        TypeOfConcern::query()->whereKey(TicketIT::query()->where('ticket_id', $ticket->id)->value('type_of_concern'))->update(['label' => 'Hardware']);
        TicketDateActivity::query()->where('ticket_id', $ticket->id)->where('date_category_id', 2)
            ->update(['created_at' => '2026-10-21 09:15:00', 'updated_at' => '2026-10-21 09:15:00']);

        $html = $this->replicaHtml($ticket);
        $cells = $this->cells($this->filePath($this->download($ticket->fresh())), self::SHEET_1);

        foreach (['E4', 'E5', 'C13', 'C35', 'J35', 'J38'] as $ref) {
            $this->assertStringContainsString(e($cells[$ref]), $html, "{$ref} is missing from the replica");
        }
        $this->assertStringContainsString('10-083-26', $html);
        $this->assertSame(2, substr_count($html, '>X</div>'), 'one priority box and one category box should be ticked');
        $this->assertSame(2, substr_count($html, '>10-083-26<'), 'the number appears on both pages');
    }

    public function test_the_replica_leaves_an_untaken_ticket_blank_where_the_workbook_does(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update(['remarks' => 'Cannot log in']);

        $changed = $this->changed($this->filePath($this->download($ticket->fresh())), self::SHEET_1);
        $html = $this->replicaHtml($ticket);

        foreach (['C35', 'J35', 'J38'] as $blank) {
            $this->assertArrayNotHasKey($blank, $changed);
        }
        $this->assertStringNotContainsString('Leo', $html);
        $this->assertStringContainsString('Cannot log in', $html);
    }

    public function test_the_replica_applies_the_same_truncation_as_the_workbook(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        TicketIT::query()->where('ticket_id', $ticket->id)->update([
            'remarks' => implode(' ', array_fill(0, 120, 'screen')),
            'name_of_area' => 'Department of Information and Communications Technology Section',
        ]);

        $data = JobOrderData::fromTicket($ticket->fresh());
        $html = $this->replicaHtml($ticket);

        $this->assertCount(3, $data->detailLines);
        $this->assertStringEndsWith('…', $data->detailLines[2]);
        $this->assertStringContainsString($data->division, $html);
        $this->assertStringNotContainsString('Technology Section', $html);

        $cells = $this->cells($this->filePath($this->download($ticket->fresh())), self::SHEET_1);
        $this->assertSame($data->division, $cells['E5']);
        $this->assertSame($data->detailLines, [$cells['C13'], $cells['C14'], $cells['C15']]);
    }

    public function test_a_pdf_failure_returns_a_json_500(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $this->app->bind(JobOrderPdfBuilder::class, fn () => new class extends JobOrderPdfBuilder
        {
            public function build(Ticket $ticket): string
            {
                throw new RuntimeException('boom');
            }
        });

        $this->getJson("/api/tickets/{$ticket->id}/job-order?format=pdf")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to generate the job order.');
    }

    public function test_a_failure_returns_a_json_500(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->itTicket();
        $this->app->bind(JobOrderFormBuilder::class, fn () => new class extends JobOrderFormBuilder
        {
            public function build(Ticket $ticket): string
            {
                throw new RuntimeException('boom');
            }
        });

        $this->getJson("/api/tickets/{$ticket->id}/job-order")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to generate the job order.');
    }
}
