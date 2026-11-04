<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLoginAttempt extends Model
{
    protected $connection = 'master';

    protected $table = 'app_login_attempt';

    protected $fillable = [
        'mobile_number',
        'purpose',
        'role',
        'otp',
        'wrong_attempts',
        'send_count',
        'locked_at',
        'expires_at',
    ];

    protected $hidden = ['otp'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
