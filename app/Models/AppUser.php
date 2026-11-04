<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUser extends Model
{
    public const ROLE_STUDENT = 'student';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_GUARDIAN = 'guardian';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';

    // The app has its own names for these roles. Stored rows and tokens keep the names above.
    private const APP_ROLE_NAMES = [
        self::ROLE_ADMIN => 'schoolAdmin',
        self::ROLE_STAFF => 'accounts',
    ];

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

    public static function appRoleName(string $role): string
    {
        return self::APP_ROLE_NAMES[$role] ?? $role;
    }

    public static function roleFromAppName(string $name): string
    {
        return array_flip(self::APP_ROLE_NAMES)[$name] ?? $name;
    }

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
