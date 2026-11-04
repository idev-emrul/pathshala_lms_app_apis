<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUserLog extends Model
{
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const PIN_RESET = 'pin_reset';
    public const NUMBER_RESET = 'number_reset';

    public const UPDATED_AT = null;

    protected $connection = 'master';

    protected $table = 'app_user_log';

    protected $fillable = [
        'app_user_id',
        'action',
        'mobile_number',
        'old_mobile_number',
        'ip_address',
        'user_agent',
    ];

    public static function record(AppUser $user, string $action, ?string $oldMobileNumber = null): self
    {
        return self::create([
            'app_user_id' => $user->id,
            'action' => $action,
            'mobile_number' => $user->mobile_number,
            'old_mobile_number' => $oldMobileNumber,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }
}
