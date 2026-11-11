<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A department that handles tickets (IT = 1, Engineering = 2) and that users belong to.
 */
class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'department',
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

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    public function priorities(): HasMany
    {
        return $this->hasMany(Priority::class);
    }
}
