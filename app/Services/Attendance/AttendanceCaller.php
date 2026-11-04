<?php

namespace App\Services\Attendance;

use App\Models\AppUser;
use Illuminate\Database\Connection;

/**
 * Who is calling, worked out from the token and the school database. Never from the request body.
 */
class AttendanceCaller
{
    /**
     * @param  array<int, int>  $studentIds  The student's own current_status_info_id rows
     */
    public function __construct(
        public readonly AppUser $user,
        public readonly Connection $db,
        public readonly string $dbName,
        public readonly ?int $userId = null,
        public readonly ?int $teacherCode = null,
        public readonly array $studentIds = [],
    ) {}
}
