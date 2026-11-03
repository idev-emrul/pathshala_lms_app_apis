<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\Notice\NoticeCaller;
use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notices are for verified students and teachers of a school. Runs after jwt.app and tenant.
 * notice:teacher allows teachers only; plain "notice" allows both roles.
 * The school database is checked on every request, so a banned teacher loses access at once.
 */
class AuthorizeNotice
{
    public function __construct(
        private TokenService $tokens,
        private SchoolDbService $schools,
    ) {}

    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->attributes->get('app_user');
        $db = $request->attributes->get('tenant');

        if (($role && $user->role !== $role) || ! $user->is_verified || ! $db) {
            throw new ApiException('You do not have access to this', 403);
        }

        $dbName = $this->tokens->decryptDbName($request->attributes->get('app_claims'));

        if ($user->role === AppUser::ROLE_TEACHER) {
            $account = $this->schools->findActiveTeacherLogin($db, $user->verified_id)
                ?? throw new ApiException('You do not have access to this', 403);

            $teacherCode = $account->teacher_code ? (int) $account->teacher_code : null;

            // teacher_info.employee_type: 1 teacher, 2 staff.
            $isStaff = $teacherCode && (int) $db->table('teacher_info')->where('teacher_code', $teacherCode)->value('employee_type') === 2;

            $caller = new NoticeCaller(
                $user,
                $db,
                $dbName,
                readerType: $isStaff ? 'staff' : 'teacher',
                readerId: $teacherCode,
                audienceRole: $isStaff ? 'staff' : 'teachers',
                teacherCode: $teacherCode,
                teacherClasses: $this->teacherClasses($db, $teacherCode),
            );
        } else {
            $caller = new NoticeCaller(
                $user,
                $db,
                $dbName,
                readerType: 'student',
                readerId: (int) $user->verified_id,
                audienceRole: 'students',
                placement: $this->placement($db, $user->verified_id),
            );
        }

        $request->attributes->set('notice_caller', $caller);

        return $next($request);
    }

    /**
     * The student's current class: the active row in a running session, the highest session first.
     */
    private function placement(Connection $db, string $studentId): ?object
    {
        return $db->table('student_current_status_info as s')
            ->join('session_info as session', 'session.session_code', '=', 's.session_code')
            ->where('s.student_registration_code', $studentId)
            ->where('s.active_status', 1)
            ->where('session.running_session', 1)
            ->orderByDesc('s.session_code')
            ->first(['s.class_code', 's.section_code', 's.group_code']);
    }

    /**
     * class_code => section codes the teacher teaches, as class or subject teacher, in running sessions.
     */
    private function teacherClasses(Connection $db, ?int $teacherCode): array
    {
        if (! $teacherCode) {
            return [];
        }

        $classes = [];

        foreach (['class_teacher_info', 'subject_teacher_info'] as $table) {
            $rows = $db->table("$table as t")
                ->join('session_info as session', function ($join) {
                    $join->on('session.session_code', '=', 't.session_code')->where('session.running_session', 1);
                })
                ->where('t.teacher_code', $teacherCode)
                ->get(['t.class_code', 't.section_code']);

            foreach ($rows as $row) {
                $classes[(int) $row->class_code][] = (int) $row->section_code;
            }
        }

        return array_map(fn ($sections) => array_values(array_unique($sections)), $classes);
    }
}
