<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUser extends Model
{
    public const ROLE_STUDENT = 'student';
    public const ROLE_TEACHER = 'teacher';

    protected $connection = 'master';

    protected $table = 'app_user';

    protected $fillable = [
        'mobile_number',
        'role',
        'verified_id',
        'is_verified',
        'verified_by',
        'schl_id',
        'school_name',
        'class_code',
        'class_name',
        'entered_student_id',
        'pin',
        'auth_token',
        'language_mode',
        'last_login_at',
    ];

    protected $hidden = ['pin', 'auth_token'];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
