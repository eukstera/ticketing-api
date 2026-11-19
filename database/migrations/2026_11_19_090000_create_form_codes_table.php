<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ISO form code and effectivity date printed in the Job Order header. A Section Head
     * edits them in Admin; the printouts read them on every download. The two rows are added
     * here (and by ReferenceDataSeeder) so production has them after migrate --force.
     */
    public function up(): void
    {
        Schema::create('form_codes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('document_code', 60);
            $table->string('effectivity_date', 40);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['key' => 'job_order', 'name' => 'ICTS Job Order Form', 'document_code' => 'GBTRGHSC-ICTS-04-0003-0000'],
            ['key' => 'job_order_blank', 'name' => 'ICTS Job Order Form (Blank)', 'document_code' => 'GBTRGHSC-ICTS-04-0004-0000'],
        ] as $row) {
            DB::table('form_codes')->insert($row + ['effectivity_date' => '(TBA)', 'status' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_codes');
    }
};
