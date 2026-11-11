<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user role. Code finds the built-in roles by the stable key (normal-user, department-head,
 * encoder), never by the display name that Admin can edit. Roles made in Admin have a null key.
 */
class Role extends Model
{
    use HasFactory;

    public const NORMAL_USER = 'normal-user';

    public const DEPARTMENT_HEAD = 'department-head';

    public const ENCODER = 'encoder';

    protected $fillable = [
        'role',
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
