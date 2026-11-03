<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\School;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDOException;

class SchoolDbService
{
    public function schools(): Collection
    {
        return School::available()
            ->orderBy('schl_name')
            ->get(['schl_id', 'schl_name', 'schl_code']);
    }

    public function findSchool(int $schlId): School
    {
        return School::available()->find($schlId)
            ?? throw new ApiException('School not found', 404);
    }

    public function connectToSchool(School $school): Connection
    {
        return $this->connect($school->db_name);
    }

    /**
     * Opens the school's database in read-only mode, so this API cannot write to it.
     */
    public function connect(string $dbName): Connection
    {
        config(['database.connections.tenant.database' => $dbName]);
        DB::purge('tenant');

        try {
            $connection = DB::connection('tenant');
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
        } catch (QueryException|PDOException $e) {
            report($e);

            throw new ApiException('School data is not available right now. Please try again later.', 503);
        }

        return $connection;
    }

    /**
     * Writes to a school's database go through one connection per module, and each module writes
     * only to its own tables: homework to hw_ tables, profile to app_stu_profile_change_request,
     * results to exam_overall_result.remarks_note (the teacher's comment) only.
     * Everything else stays on the read-only connection above.
     */
    public function connectForHomework(string $dbName): Connection
    {
        config(['database.connections.tenant_homework.database' => $dbName]);
        DB::purge('tenant_homework');

        return DB::connection('tenant_homework');
    }

    public function connectForProfile(string $dbName): Connection
    {
        config(['database.connections.tenant_profile.database' => $dbName]);
        DB::purge('tenant_profile');

        return DB::connection('tenant_profile');
    }

    public function connectForResult(string $dbName): Connection
    {
        config(['database.connections.tenant_result.database' => $dbName]);
        DB::purge('tenant_result');

        return DB::connection('tenant_result');
    }

    public function classes(School $school): Collection
    {
        return $this->connectToSchool($school)->table('class_info')
            ->where('class_activation_status', 1)
            ->orderBy('class_position')
            ->get(['class_code', 'class_name', 'class_name_bn']);
    }

    public function findClass(School $school, int $classCode): ?object
    {
        return $this->classes($school)->firstWhere('class_code', $classCode);
    }

    public function findStudent(Connection $tenant, string $studentId): ?object
    {
        return $tenant->table('student_info')
            ->where('student_registration_code', $studentId)
            ->first(['student_registration_code', 'student_name', 'student_auth', 'contact_number']);
    }

    /**
     * Only a login with the Teacher user type can be used. Some have no teacher_info row,
     * but one that does must be an active teacher.
     */
    public function findTeacherAccount(Connection $tenant, string $username): ?object
    {
        return $this->teacherLogins($tenant)
            ->where('user.username', $username)
            ->where('user.user_type', 'Teacher')
            ->where('user.activated', 1)
            ->where('user.banned', 0)
            ->where(fn ($query) => $query->whereNull('teacher.teacher_code')->orWhere('teacher.teacher_active_status', 1))
            ->first(['user.id', 'user.password', 'teacher.teacher_code', $tenant->raw('COALESCE(teacher.teacher_name, user.user_full_name) as teacher_name')]);
    }

    public function findTeacher(Connection $tenant, string $userId): ?object
    {
        return $this->teacherLogins($tenant)
            ->where('user.id', $userId)
            ->first(['teacher.teacher_code', $tenant->raw('COALESCE(teacher.teacher_name, user.user_full_name) as teacher_name')]);
    }

    public function findActiveTeacherLogin(Connection $tenant, string $userId): ?object
    {
        return $this->teacherLogins($tenant)
            ->where('user.id', $userId)
            ->where('user.user_type', 'Teacher')
            ->where('user.activated', 1)
            ->where('user.banned', 0)
            ->where(fn ($query) => $query->whereNull('teacher.teacher_code')->orWhere('teacher.teacher_active_status', 1))
            ->first(['user.id', 'teacher.teacher_code', $tenant->raw('COALESCE(teacher.teacher_name, user.user_full_name) as teacher_name')]);
    }

    private function teacherLogins(Connection $tenant): Builder
    {
        return $tenant->table('users as user')
            ->leftJoin('teacher_info as teacher', 'teacher.teacher_code', '=', 'user.teacher_code');
    }

    /**
     * The active row in a running session. If there are several, the highest session wins.
     */
    public function currentPlacement(Connection $tenant, string $studentId): ?object
    {
        return $tenant->table('student_current_status_info as status')
            ->join('session_info as session', 'session.session_code', '=', 'status.session_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'status.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'status.section_code')
            ->leftJoin('shift_info as shift', 'shift.shift_code', '=', 'status.shift_code')
            ->leftJoin('group_info as group', 'group.group_code', '=', 'status.group_code')
            ->where('status.student_registration_code', $studentId)
            ->where('status.active_status', 1)
            ->where('session.running_session', 1)
            ->orderByDesc('status.session_code')
            ->first([
                'status.current_status_info_id',
                'status.class_code',
                'status.roll_number',
                'session.session_name',
                'class.class_name',
                'section.section_name',
                'shift.shift_name',
                'group.group_name',
            ]);
    }
}
