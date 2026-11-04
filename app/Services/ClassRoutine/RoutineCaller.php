<?php

namespace App\Services\ClassRoutine;

use App\Models\AppUser;
use Illuminate\Database\Connection;

/**
 * Who is calling, worked out from the token and the school database. Never from the request body.
 */
class RoutineCaller
{
    /**
     * @param  array<int, object>  $placements  The student's student_current_status_info rows, by current_status_info_id
     */
    public function __construct(
        public readonly AppUser $user,
        public readonly Connection $db,
        public readonly ?int $userId = null,
        public readonly ?int $teacherCode = null,
        public readonly array $placements = [],
    ) {}
}
