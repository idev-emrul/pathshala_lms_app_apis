<?php

namespace App\Services\Notice;

use App\Models\AppUser;
use Illuminate\Database\Connection;

/**
 * Who is calling, worked out from the token and the school database. Never from the request body.
 *
 * readerType / readerId identify the reader for read marks: a student by student_registration_code,
 * a teacher or staff member by teacher_code. audienceRole is the notice audience they belong to.
 */
class NoticeCaller
{
    /**
     * @param  ?object  $placement  The student's current class, section and group, or null
     * @param  array<int, array<int, int>>  $teacherClasses  class_code => section codes the teacher teaches (0 = all)
     */
    public function __construct(
        public readonly AppUser $user,
        public readonly Connection $db,
        public readonly string $dbName,
        public readonly string $readerType,
        public readonly ?int $readerId,
        public readonly string $audienceRole,
        public readonly ?object $placement = null,
        public readonly ?int $teacherCode = null,
        public readonly array $teacherClasses = [],
    ) {}

    public function isTeacher(): bool
    {
        return $this->user->role === AppUser::ROLE_TEACHER;
    }
}
