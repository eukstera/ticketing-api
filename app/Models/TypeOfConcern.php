<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeOfConcern extends Model
{
    protected $table = 'type_of_concern';

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
