<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;
use Tests\ApiTestCase;

/**
 * Shared checks for every admin lookup resource (departments, roles,
 * designations, priorities, concern types, nature of work): authorization,
 * list conventions, update, soft deactivate and reactivate.
 */
#[Group('phase2')]
abstract class AdminResourceTestCase extends ApiTestCase
{
    /** Marker used to isolate rows created by a test from seeded rows. */
    protected const MARKER = 'Qzmarker';

    /** Path below /api, e.g. "/admin/departments". */
    abstract protected function path(): string;

    /** Key under result that holds the paginator, e.g. "departments". */
    abstract protected function listKey(): string;

    /** Response field holding the display text: name, label, level or designation. */
    abstract protected function nameField(): string;

    abstract protected function table(): string;

    /**
     * Creates a row named $name in the database.
     */
    abstract protected function newRow(string $name, bool $active = true): Model;

    /**
     * Payload for a PATCH that renames a row.
     *
     * @return array<string, mixed>
     */
    abstract protected function renamePayload(string $name): array;

    protected function url(string $query = ''): string
    {
        return '/api'.$this->path().($query === '' ? '' : '?'.$query);
    }

    protected function rowUrl(Model $row): string
    {
        return '/api'.$this->path().'/'.$row->getKey();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listRows(string $query = ''): array
    {
        return $this->getJson($this->url($query))->assertOk()->json('result.'.$this->listKey().'.data');
    }

    /**
     * @return list<string>
     */
    protected function listNames(string $query = ''): array
    {
        return array_column($this->listRows($query), $this->nameField());
    }

    public function test_every_route_requires_authentication(): void
    {
        $row = $this->newRow(self::MARKER.' Row');

        $this->getJson($this->url())->assertUnauthorized();
        $this->patchJson($this->rowUrl($row), [])->assertUnauthorized();
        $this->deleteJson($this->rowUrl($row))->assertUnauthorized();
    }

    public function test_non_admins_are_forbidden_on_every_route(): void
    {
        $this->actingAsUser();
        $row = $this->newRow(self::MARKER.' Row');

        $this->getJson($this->url())->assertForbidden()->assertJsonPath('success', false);
        $this->patchJson($this->rowUrl($row), $this->renamePayload('Changed'))->assertForbidden();
        $this->deleteJson($this->rowUrl($row))->assertForbidden();

        $this->assertDatabaseHas($this->table(), ['id' => $row->getKey(), 'status' => 1]);
    }

    public function test_list_is_a_paginator_under_the_documented_key(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson($this->url());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['result' => [$this->listKey() => [
                'data', 'current_page', 'last_page', 'per_page', 'total',
            ]]])
            ->assertJsonPath('result.'.$this->listKey().'.per_page', 15)
            ->assertJsonPath('result.'.$this->listKey().'.current_page', 1);
    }

    public function test_rows_expose_id_text_and_boolean_status(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Shape');

        $found = collect($this->listRows('search='.self::MARKER))->firstWhere('id', $row->getKey());

        $this->assertNotNull($found);
        $this->assertSame(self::MARKER.' Shape', $found[$this->nameField()]);
        $this->assertTrue($found['status']);
        $this->assertIsInt($found['id']);
    }

    public function test_per_page_paginates_and_is_bounded(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 20) as $i) {
            $this->newRow(self::MARKER." Bulk {$i}");
        }

        $this->getJson($this->url('search='.self::MARKER))
            ->assertOk()
            ->assertJsonCount(15, 'result.'.$this->listKey().'.data')
            ->assertJsonPath('result.'.$this->listKey().'.total', 20)
            ->assertJsonPath('result.'.$this->listKey().'.last_page', 2);

        $this->getJson($this->url('search='.self::MARKER.'&per_page=5&page=4'))
            ->assertOk()
            ->assertJsonCount(5, 'result.'.$this->listKey().'.data')
            ->assertJsonPath('result.'.$this->listKey().'.current_page', 4);

        $this->getJson($this->url('per_page=100'))->assertOk();
        $this->getJson($this->url('per_page=0'))->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson($this->url('per_page=101'))->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->getJson($this->url('per_page=abc'))->assertUnprocessable();
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->actingAsAdmin();
        $this->newRow(self::MARKER.' Alpha');
        $this->newRow('Plain Beta');

        $names = $this->listNames('search='.strtoupper(self::MARKER));

        $this->assertSame([self::MARKER.' Alpha'], $names);
    }

    public function test_status_filter_supports_active_inactive_and_all(): void
    {
        $this->actingAsAdmin();
        $this->newRow(self::MARKER.' Active', true);
        $this->newRow(self::MARKER.' Inactive', false);
        $search = 'search='.self::MARKER;

        $this->assertSame([self::MARKER.' Active'], $this->listNames($search.'&status=active'));
        $this->assertSame([self::MARKER.' Inactive'], $this->listNames($search.'&status=inactive'));
        $this->assertEqualsCanonicalizing(
            [self::MARKER.' Active', self::MARKER.' Inactive'],
            $this->listNames($search.'&status=all')
        );
        $this->assertEqualsCanonicalizing(
            [self::MARKER.' Active', self::MARKER.' Inactive'],
            $this->listNames($search),
            'Omitting status behaves like status=all'
        );
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->actingAsAdmin();
        $this->newRow(self::MARKER.' 50% off');
        $this->newRow(self::MARKER.' 50x off');
        $this->newRow(self::MARKER.' a_b');
        $this->newRow(self::MARKER.' aXb');

        $this->assertSame([self::MARKER.' 50% off'], $this->listNames('search='.rawurlencode(self::MARKER.' 50%')));
        $this->assertSame([self::MARKER.' a_b'], $this->listNames('search='.rawurlencode(self::MARKER.' a_b')));
        $this->assertSame([self::MARKER.' 50% off'], $this->listNames('search='.rawurlencode('%')));
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson($this->url('status=archived'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_row_can_be_renamed(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow('Old name');

        $this->patchJson($this->rowUrl($row), $this->renamePayload('New name'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertContains('New name', $this->listNames('search=New%20name'));
    }

    public function test_patch_validates_input(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow('Valid');

        $this->patchJson($this->rowUrl($row), ['status' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_delete_soft_deactivates_and_patch_reactivates(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Cycle');

        $this->deleteJson($this->rowUrl($row))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas($this->table(), ['id' => $row->getKey(), 'status' => 0]);
        $this->assertSame([self::MARKER.' Cycle'], $this->listNames('search='.self::MARKER.'&status=inactive'));
        $this->assertSame([], $this->listNames('search='.self::MARKER.'&status=active'));

        $this->patchJson($this->rowUrl($row), ['status' => true])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas($this->table(), ['id' => $row->getKey(), 'status' => 1]);
        $this->assertSame([self::MARKER.' Cycle'], $this->listNames('search='.self::MARKER.'&status=active'));
    }

    public function test_status_can_be_set_to_false_through_patch(): void
    {
        $this->actingAsAdmin();
        $row = $this->newRow(self::MARKER.' Toggle');

        $this->patchJson($this->rowUrl($row), ['status' => false])->assertOk();

        $this->assertDatabaseHas($this->table(), ['id' => $row->getKey(), 'status' => 0]);
    }

    public function test_unknown_rows_return_404(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api'.$this->path().'/999999', ['status' => true])
            ->assertNotFound()
            ->assertJsonPath('success', false);
        $this->deleteJson('/api'.$this->path().'/999999')->assertNotFound();
    }
}
