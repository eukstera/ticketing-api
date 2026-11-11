<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An account. Login is by email. status = false means the account is deactivated and cannot sign in.
 * isEncoder() is true for the Section Secretary role (read-only access).
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'department_id',
        'role_id',
        'designation_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'status',
    ];

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'role_id' => 'integer',
            'designation_id' => 'integer',
            'status' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_user');
    }

    /**
     * Section Secretaries have read-only access to their own department, plus exports.
     */
    public function isEncoder(): bool
    {
        return $this->role?->key === Role::ENCODER;
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
