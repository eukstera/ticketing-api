<?php

namespace Tests\Feature\Services;

use App\Models\Ticket;
use App\Services\TicketNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TicketNumberGeneratorTest extends TestCase
{
    private function suffix(): string
    {
        return now()->format('m').'-%s-'.now()->format('Y');
    }

    public function test_first_number_of_the_month_is_001(): void
    {
        $this->assertSame(sprintf($this->suffix(), '001'), app(TicketNumberGenerator::class)->generate());
    }

    public function test_numbers_increase_and_are_shared_between_departments(): void
    {
        $generator = app(TicketNumberGenerator::class);

        $this->assertSame(sprintf($this->suffix(), '001'), $generator->generate(1));
        $this->assertSame(sprintf($this->suffix(), '002'), $generator->generate(2));
        $this->assertSame(sprintf($this->suffix(), '003'), $generator->generate(1));
    }

    public function test_counter_restarts_each_month(): void
    {
        $generator = app(TicketNumberGenerator::class);

        $this->travelTo(now()->setDate(2026, 3, 15));
        $generator->generate();
        $this->assertSame('03-002-2026', $generator->generate());

        $this->travelTo(now()->setDate(2026, 4, 1));
        $this->assertSame('04-001-2026', $generator->generate());
    }

    public function test_a_new_counter_continues_after_existing_tickets(): void
    {
        Ticket::factory()->withoutDetails()->create(['ticket_number' => sprintf($this->suffix(), '041')]);

        $this->assertSame(sprintf($this->suffix(), '042'), app(TicketNumberGenerator::class)->generate());
    }

    public function test_a_rolled_back_transaction_gives_the_number_back(): void
    {
        $generator = app(TicketNumberGenerator::class);

        try {
            DB::transaction(function () use ($generator) {
                $generator->generate();

                throw new RuntimeException('ticket insert failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(sprintf($this->suffix(), '001'), $generator->generate());
    }

    public function test_ticket_number_is_unique_in_the_database(): void
    {
        $number = sprintf($this->suffix(), '005');
        Ticket::factory()->withoutDetails()->create(['ticket_number' => $number]);

        $this->expectException(UniqueConstraintViolationException::class);

        Ticket::factory()->withoutDetails()->create(['ticket_number' => $number]);
    }
}
