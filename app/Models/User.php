<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'department_id',
        'role_id',
        'designation_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'status'
    ];

    protected $hidden = ['password'];
    
    // protected function casts(): array
    // {
    //     return [
    //         'password' => 'hashed',
    //     ];
    // }
}
