<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketIT extends Model
{
    use HasFactory;
    
    protected $table = 'tickets_it';

    protected $fillable = [
        'ticket_id',
        // 'type_of_tech_support',
        'type_of_concern',
        'name_of_area',
        'remarks',
        'area_supervisor',
        'status'
    ];
}
