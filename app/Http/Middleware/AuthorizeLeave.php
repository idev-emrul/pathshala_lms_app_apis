<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\GuardianChildService;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who can use leave. Runs after jwt.app and tenant, and logins are checked on every request.
 *
 * student: a student for their own id, or a guardian for a linked child.
 * staff: a teacher or an admin.
 * admin: an admin only.
 * approver: staff, or a guardian, whose school comes from the child in the controller.
 */
class AuthorizeLeave
{
    public function __construct(
        private TokenService $tokens,
        private SchoolDbService $schools,
        private GuardianChildService $children,
    ) {}

    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->attributes->get('app_user');

        if ($mode === 'approver' && $user->role === AppUser::ROLE_GUARDIAN) {
            return $next($request);
        }

        $caller = match ($mode) {
            'student' => $this->studentCaller($request, $user),
            'admin' => $user->role === AppUser::ROLE_ADMIN ? $this->staffCaller($request, $user) : $this->refuse(),
            default => $this->staffCaller($request, $user),
        };

        $request->attributes->set('leave_caller', $caller);

        return $next($request);
    }

    private function studentCaller(Request $request, AppUser $user): HomeworkCaller
    {
        $studentId = (int) $request->route('studentId');

        if ($user->role === AppUser::ROLE_GUARDIAN) {
            [$db, , $dbName] = $this->children->currentStudentById($user, $studentId, $request->integer('child_id') ?: null);

            return new HomeworkCaller($user, $db, $dbName, studentIds: [$studentId], activeStudentIds: [$studentId]);
        }

        $db = $request->attributes->get('tenant');

        if ($user->role !== AppUser::ROLE_STUDENT || ! $user->is_verified || ! $db) {
            $this->refuse();
        }

        $rows = $db->table('student_current_status_info')
            ->where('student_registration_code', $user->verified_id)
            ->get(['current_status_info_id', 'active_status']);

        return new HomeworkCaller(
            $user,
            $db,
            $this->dbName($request),
            studentIds: $rows->pluck('current_status_info_id')->map(fn ($id) => (int) $id)->all(),
            activeStudentIds: $rows->where('active_status', 1)->pluck('current_status_info_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    private function staffCaller(Request $request, AppUser $user): HomeworkCaller
    {
        $db = $request->attributes->get('tenant');

        if (! $user->is_verified || ! $db) {
            $this->refuse();
        }

        if ($user->role === AppUser::ROLE_ADMIN) {
            $account = $this->schools->findActiveAdminStaffLogin($db, $user->verified_id, $user->role) ?? $this->refuse();

            return new HomeworkCaller($user, $db, $this->dbName($request), userId: (int) $account->id, teacherName: $account->user_full_name);
        }

        if ($user->role !== AppUser::ROLE_TEACHER) {
            $this->refuse();
        }

        $account = $this->schools->findActiveTeacherLogin($db, $user->verified_id) ?? $this->refuse();

        return new HomeworkCaller(
            $user,
            $db,
            $this->dbName($request),
            userId: (int) $account->id,
            teacherCode: $account->teacher_code ? (int) $account->teacher_code : null,
            teacherName: $account->teacher_name,
        );
    }

    private function dbName(Request $request): string
    {
        return $this->tokens->decryptDbName($request->attributes->get('app_claims'));
    }

    private function refuse(): never
    {
        throw new ApiException('You do not have access to this', 403);
    }
}
