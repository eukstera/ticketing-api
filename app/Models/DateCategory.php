<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DateCategory extends Model
{
    use HasFactory;

    protected $table = 'date_categories';

    protected $fillable = [
        'category',
        'status',
    ];

    public function ticketDateActivities()
    {
        return $this->hasMany(TicketDateActivity::class);
    }

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
