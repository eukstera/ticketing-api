<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ISO form code and effectivity date printed in a form's header (form_codes table).
 * Today only the Job Order uses it: key job_order for the filled form, job_order_blank for
 * the blank one. Rows are never created or deleted through the API, only edited.
 */
class FormCode extends Model
{
    public const JOB_ORDER = 'job_order';

    public const JOB_ORDER_BLANK = 'job_order_blank';

    /**
     * What is printed when the row is missing (the seeder has not run yet).
     *
     * @var array<string, array{document_code: string, effectivity_date: string}>
     */
    public const DEFAULTS = [
        self::JOB_ORDER => ['document_code' => 'GBTRGHSC-ICTS-04-0003-0000', 'effectivity_date' => '(TBA)'],
        self::JOB_ORDER_BLANK => ['document_code' => 'GBTRGHSC-ICTS-04-0004-0000', 'effectivity_date' => '(TBA)'],
    ];

    protected $fillable = [
        'document_code',
        'effectivity_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }
}
