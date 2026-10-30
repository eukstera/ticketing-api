<?php

namespace Tests\Feature\Harness;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    public function test_a_fresh_database_gets_every_default_row(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(8, DB::table('status')->count());
        $this->assertSame(11, DB::table('date_categories')->count());
        $this->assertSame(7, DB::table('priority')->count());
        $this->assertSame([3, 3, 3, 20], DB::table('priority')->where('department_id', 1)->orderBy('id')->pluck('sla_days')->map(fn ($days) => (int) $days)->all());
    }

    public function test_rows_edited_by_an_admin_survive_a_second_run(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        DB::table('priority')->where('id', 1)->update(['level' => 'Critical', 'sla_days' => 9, 'SLA' => '9']);
        DB::table('priority')->where('id', 2)->update(['status' => false]);
        DB::table('departments')->where('id', 1)->update(['department' => 'Information Technology']);
        DB::table('type_of_concern')->where('id', 1)->update(['label' => 'Equipment']);
        DB::table('designations')->where('id', 1)->update(['status' => false]);

        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(['Critical', 9], [DB::table('priority')->where('id', 1)->value('level'), (int) DB::table('priority')->where('id', 1)->value('sla_days')]);
        $this->assertSame(0, (int) DB::table('priority')->where('id', 2)->value('status'));
        $this->assertSame('Information Technology', DB::table('departments')->where('id', 1)->value('department'));
        $this->assertSame('Equipment', DB::table('type_of_concern')->where('id', 1)->value('label'));
        $this->assertSame(0, (int) DB::table('designations')->where('id', 1)->value('status'));
        $this->assertSame(7, DB::table('priority')->count());
    }

    public function test_a_missing_row_is_added_without_touching_the_others(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        DB::table('priority')->where('id', 3)->update(['sla_days' => 12]);
        DB::table('status')->where('label', 'Void')->delete();
        DB::table('date_categories')->where('category', 'Void Date')->delete();

        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(1, DB::table('status')->where('label', 'Void')->count());
        $this->assertSame(1, DB::table('date_categories')->where('category', 'Void Date')->count());
        $this->assertSame(12, (int) DB::table('priority')->where('id', 3)->value('sla_days'));
    }

    public function test_a_row_that_exists_under_another_id_is_not_duplicated(): void
    {
        DB::table('status')->insert(['id' => 40, 'label' => 'Blank', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(1, DB::table('status')->where('label', 'Blank')->count());
        $this->assertSame(40, (int) DB::table('status')->where('label', 'Blank')->value('id'));
    }
}
