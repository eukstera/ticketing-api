<?php

namespace Tests\Feature\Admin;

/**
 * Create checks for admin resources that expose POST /admin/<resource>.
 * Using classes must extend AdminResourceTestCase and provide the payload.
 */
trait ChecksAdminCreate
{
    /**
     * Valid POST body whose display text is $name.
     *
     * @return array<string, mixed>
     */
    abstract protected function createPayload(string $name): array;

    public function test_admin_can_create_a_row(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson($this->url(), $this->createPayload(self::MARKER.' Created'));

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertSame([self::MARKER.' Created'], $this->listNames('search='.self::MARKER));
    }

    public function test_created_rows_are_active(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(), $this->createPayload(self::MARKER.' Created'))->assertCreated();

        $this->assertSame([self::MARKER.' Created'], $this->listNames('search='.self::MARKER.'&status=active'));
    }

    public function test_create_validates_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_non_admins_cannot_create(): void
    {
        $this->actingAsUser();

        $this->postJson($this->url(), $this->createPayload(self::MARKER.' Created'))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
