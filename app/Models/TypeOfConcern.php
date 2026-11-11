<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The type-of-concern options of an IT ticket (Hardware, Software, Network ...).
 */
class TypeOfConcern extends Model
{
    use HasFactory;

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
