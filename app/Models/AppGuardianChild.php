<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A child linked to a guardian's account. Name and class are read live from the school database.
 */
class AppGuardianChild extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'master';

    protected $table = 'app_guardian_child';

    protected $fillable = [
        'app_user_id',
        'schl_id',
        'student_id',
    ];
}
