<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\School;
use Illuminate\Database\Connection;
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
