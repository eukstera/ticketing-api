<?php

namespace App\Models;

use Database\Factories\ReportSignatoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person who can sign the Accomplishment Report. At most one active row is the default.
 */
class ReportSignatory extends Model
{
    /** @use HasFactory<ReportSignatoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'title',
        'is_default',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }
}
