<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\Behaviour\BehaviourSupport;
use App\Services\GuardianChildService;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who can use behaviour. Runs after jwt.app and tenant, and logins are checked on every request.
 *
 * viewer: a student for their own id, a guardian for a linked child, or staff for a student in their scope.
 * staff: a teacher or an admin.
 * admin: an admin only.
 */
class AuthorizeBehaviour
{
    public function __construct(
        private TokenService $tokens,
        private SchoolDbService $schools,
        private GuardianChildService $children,
        private BehaviourSupport $support,
    ) {}

    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->attributes->get('app_user');

        $caller = match (true) {
            $mode === 'viewer' && $user->role === AppUser::ROLE_GUARDIAN => $this->guardianCaller($request, $user),
            $mode === 'viewer' && $user->role === AppUser::ROLE_STUDENT => $this->studentCaller($request, $user),
            $mode === 'admin' && $user->role !== AppUser::ROLE_ADMIN => $this->refuse('Only school admins can use this endpoint.'),
            default => $this->staffCaller($request, $user),
        };

        $this->support->ensureSetUp($caller->db);

        $request->attributes->set('behaviour_caller', $caller);

        return $next($request);
    }

    private function guardianCaller(Request $request, AppUser $user): HomeworkCaller
    {
        $studentId = (int) $request->route('studentId');

        [$db, , $dbName] = $this->children->currentStudentById($user, $studentId, $request->integer('child_id') ?: null);

        return new HomeworkCaller($user, $db, $dbName, studentIds: [$studentId]);
    }

    private function studentCaller(Request $request, AppUser $user): HomeworkCaller
    {
        $db = $request->attributes->get('tenant');

        if (! $user->is_verified || ! $db) {
            $this->refuse();
        }

        $ids = $db->table('student_current_status_info')
            ->where('student_registration_code', $user->verified_id)
            ->pluck('current_status_info_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new HomeworkCaller($user, $db, $this->dbName($request), studentIds: $ids);
    }

    private function staffCaller(Request $request, AppUser $user): HomeworkCaller
    {
        $db = $request->attributes->get('tenant');

        if (! $user->is_verified || ! $db) {
            $this->refuse();
        }

        if ($user->role === AppUser::ROLE_ADMIN) {
            $account = $this->schools->findActiveAdminStaffLogin($db, $user->verified_id, $user->role) ?? $this->refuse();

            return new HomeworkCaller($user, $db, $this->dbName($request), userId: (int) $account->id);
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

    private function refuse(string $message = 'You do not have access to this'): never
    {
        throw new ApiException($message, 403);
    }
}
