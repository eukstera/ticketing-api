<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Priority extends Model
{
    protected $table = 'priority';

    protected $fillable = [
        'department_id',
        'level',
        'sla',
        'status'
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'priority_id');
    }
}
