<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NatureOfWork extends Model
{
    protected $table = 'nature_of_work';

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
