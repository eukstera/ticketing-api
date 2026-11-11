<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy pivot table between roles and users. The current role is users.role_id; nothing reads this
 * table.
 */
class RoleUser extends Model
{
    protected $fillable = [
        'role_id',
        'user_id',
        'status',
    ];
}
