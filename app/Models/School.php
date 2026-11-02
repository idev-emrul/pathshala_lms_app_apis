<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Existing table, read-only for this project.
 */
class School extends Model
{
    public $timestamps = false;

    protected $connection = 'master';

    protected $table = 'school_information';

    protected $primaryKey = 'schl_id';

    protected $guarded = ['*'];

    protected $hidden = ['api_code', 'school_secret', 'db_name'];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNotNull('db_name')->where('db_name', '<>', '');
    }
}
