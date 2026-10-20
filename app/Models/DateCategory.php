<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DateCategory extends Model
{
    protected $table = 'date_categories';

    protected $fillable = [
        'category',
        'status'
    ];

    public function ticketDateActivities()
    {
        return $this->hasMany(TicketDateActivity::class);
    }

}
