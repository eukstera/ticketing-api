<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NatureOfWork extends Model
{
    protected $table = 'nature_of_work';

    protected $fillable = [
        'label',
        'status'
    ];
}
