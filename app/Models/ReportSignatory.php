<?php

namespace App\Models;

use Database\Factories\ReportSignatoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
