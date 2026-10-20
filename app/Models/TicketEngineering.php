<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketEngineering extends Model
{
    use HasFactory;

    protected $table = 'tickets_engineering';

    protected $fillable = [
        'ticket_id',
        'nature_of_work',
        'scope_of_work',
        'other_nature_of_work',
        'name_of_area',
        'area_supervisor',
        'status'
    ];
}
