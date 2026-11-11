<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A ticket status: Open 1, In-progress 2, Completed 3, Overdue 4, False Alarm 5, Pending Confirmation
 * 6, Blank 7, Void 8.
 */
class Status extends Model
{
    use HasFactory;

    protected $table = 'status';

    protected $fillable = [
        'label',
        'status',
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
