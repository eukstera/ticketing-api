<?php

namespace Tests\Feature\Admin;

use App\Models\FormCode;
use Tests\ApiTestCase;

/**
 * Admin list and edit of the form code and effectivity date printed in the Job Order header.
 */
class AdminFormCodesTest extends ApiTestCase
{
    private const URL = '/api/admin/form-codes';

    private function jobOrder(): FormCode
    {
        return FormCode::query()->where('key', FormCode::JOB_ORDER)->firstOrFail();
    }

    public function test_the_two_job_order_rows_are_seeded(): void
    {
        $rows = FormCode::query()->orderBy('id')->get(['key', 'name', 'document_code', 'effectivity_date', 'status']);

        $this->assertSame(
            [
                ['job_order', 'ICTS Job Order Form', 'GBTRGHSC-ICTS-04-0003-0000', '(TBA)', true],
                ['job_order_blank', 'ICTS Job Order Form (Blank)', 'GBTRGHSC-ICTS-04-0004-0000', '(TBA)', true],
            ],
            $rows->map(fn (FormCode $row) => [$row->key, $row->name, $row->document_code, $row->effectivity_date, $row->status])->all()
        );
    }

    public function test_a_section_head_lists_the_form_codes(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('success', true);

        $paginator = $response->json('result.form_codes');
        $this->assertSame(2, $paginator['total']);
        $this->assertSame(1, $paginator['current_page']);
        $this->assertSame(['id', 'key', 'name', 'document_code', 'effectivity_date', 'status', 'updated_at'], array_keys($paginator['data'][0]));
        $this->assertSame(['job_order', 'job_order_blank'], array_column($paginator['data'], 'key'));
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        $this->actingAsAdmin();

        $names = array_column($this->getJson(self::URL.'?search=Blank')->assertOk()->json('result.form_codes.data'), 'key');

        $this->assertSame(['job_order_blank'], $names);
    }

    public function test_a_section_head_edits_the_code_and_the_effectivity_date(): void
    {
        $this->actingAsAdmin();
        $row = $this->jobOrder();

        $this->patchJson(self::URL.'/'.$row->id, ['document_code' => 'GBTRGHSC-ICTS-04-0003-0001', 'effectivity_date' => 'December 1, 2026'])
            ->assertOk()
            ->assertJsonPath('result.form_code.document_code', 'GBTRGHSC-ICTS-04-0003-0001')
            ->assertJsonPath('result.form_code.effectivity_date', 'December 1, 2026')
            ->assertJsonPath('result.form_code.key', 'job_order');

        $this->assertSame('GBTRGHSC-ICTS-04-0003-0001', $row->fresh()->document_code);
        $this->assertSame('December 1, 2026', $row->fresh()->effectivity_date);
    }

    public function test_the_key_name_and_status_cannot_be_changed(): void
    {
        $this->actingAsAdmin();
        $row = $this->jobOrder();

        $this->patchJson(self::URL.'/'.$row->id, [
            'document_code' => 'X-1', 'effectivity_date' => '(TBA)', 'key' => 'other', 'name' => 'Renamed', 'status' => false,
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(['job_order', 'ICTS Job Order Form', true], [$fresh->key, $fresh->name, $fresh->status]);
    }

    public function test_both_fields_are_required_and_limited(): void
    {
        $this->actingAsAdmin();
        $id = $this->jobOrder()->id;

        $this->patchJson(self::URL.'/'.$id, [])->assertUnprocessable()->assertJsonValidationErrors(['document_code', 'effectivity_date']);
        $this->patchJson(self::URL.'/'.$id, ['document_code' => str_repeat('A', 61), 'effectivity_date' => '(TBA)'])
            ->assertUnprocessable()->assertJsonValidationErrors(['document_code']);
        $this->patchJson(self::URL.'/'.$id, ['document_code' => 'X', 'effectivity_date' => str_repeat('B', 41)])
            ->assertUnprocessable()->assertJsonValidationErrors(['effectivity_date']);
        $this->patchJson(self::URL.'/'.$id, ['document_code' => str_repeat('A', 60), 'effectivity_date' => str_repeat('B', 40)])->assertOk();
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->actingAsAdmin();

        $this->patchJson(self::URL.'/999', ['document_code' => 'X', 'effectivity_date' => '(TBA)'])->assertNotFound();
    }

    public function test_only_section_heads_may_use_it(): void
    {
        $id = $this->jobOrder()->id;

        $this->actingAsUser($this->itUser());
        $this->getJson(self::URL)->assertForbidden();
        $this->patchJson(self::URL.'/'.$id, ['document_code' => 'X', 'effectivity_date' => '(TBA)'])->assertForbidden();

        $this->assertSame('GBTRGHSC-ICTS-04-0003-0000', $this->jobOrder()->document_code);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_rows_cannot_be_created_or_deleted(): void
    {
        $this->actingAsAdmin();
        $id = $this->jobOrder()->id;

        $this->postJson(self::URL, ['document_code' => 'X', 'effectivity_date' => '(TBA)'])->assertStatus(405);
        $this->deleteJson(self::URL.'/'.$id)->assertStatus(405);

        $this->assertSame(2, FormCode::query()->count());
    }
}
