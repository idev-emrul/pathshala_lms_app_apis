<?php

namespace App\Services\HwAssignment;

use App\Models\AppUser;
use Illuminate\Database\Connection;

/**
 * Who is calling, worked out from the token and the school database. Never from the request body.
 */
class HomeworkCaller
{
    /**
     * @param  array<int, int>  $studentIds  The student's current_status_info_id rows
     * @param  array<int, int>  $activeStudentIds  Those rows that are still active
     */
    public function __construct(
        public readonly AppUser $user,
        public readonly Connection $db,
        public readonly string $dbName,
        public readonly ?int $userId = null,
        public readonly ?int $teacherCode = null,
        public readonly array $studentIds = [],
        public readonly array $activeStudentIds = [],
        public readonly ?string $teacherName = null,
    ) {}

    public function idempotencyKey(): string
    {
        return 'staff:'.$this->userId;
    }
}
