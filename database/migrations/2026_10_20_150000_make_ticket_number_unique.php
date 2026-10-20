<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numbers whose duplicates were approved for renumbering. Any other
     * duplicate aborts the migration untouched.
     *
     * @var array<int, string>
     */
    private array $approvedDuplicates = ['10-906-2026', '10-637-2026'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicates = DB::table('tickets')
            ->select('ticket_number')
            ->groupBy('ticket_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ticket_number')
            ->all();

        $unexpected = array_diff($duplicates, $this->approvedDuplicates);

        if ($unexpected !== []) {
            throw new RuntimeException('Unexpected duplicate ticket numbers, nothing changed: '.implode(', ', $unexpected));
        }

        DB::transaction(function () use ($duplicates) {
            foreach ($duplicates as $number) {
                [$month, , $year] = explode('-', $number);

                $next = DB::table('tickets')
                    ->where('ticket_number', 'like', "{$month}-%-{$year}")
                    ->pluck('ticket_number')
                    ->map(fn (string $existing) => (int) explode('-', $existing)[1])
                    ->max() + 1;

                $laterIds = DB::table('tickets')
                    ->where('ticket_number', $number)
                    ->orderBy('id')
                    ->pluck('id')
                    ->slice(1);

                foreach ($laterIds as $id) {
                    $new = "{$month}-".str_pad((string) $next, 3, '0', STR_PAD_LEFT)."-{$year}";

                    DB::table('tickets')->where('id', $id)->update(['ticket_number' => $new]);
                    echo "  ticket {$id}: {$number} -> {$new}".PHP_EOL;

                    $next++;
                }
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('ticket_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['ticket_number']);
        });
    }
};
