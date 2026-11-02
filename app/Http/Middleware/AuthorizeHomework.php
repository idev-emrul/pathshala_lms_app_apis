<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Homework is for verified students and teachers of a school. Runs after jwt.app and tenant.
 * The school database is checked on every request, so a banned teacher loses access at once.
 */
class AuthorizeHomework
{
    public function __construct(
        private TokenService $tokens,
        private SchoolDbService $schools,
    ) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->attributes->get('app_user');
        $db = $request->attributes->get('tenant');

        if ($user->role !== $role || ! $user->is_verified || ! $db) {
            throw new ApiException('You do not have access to this', 403);
        }

        $dbName = $this->tokens->decryptDbName($request->attributes->get('app_claims'));

        if ($role === AppUser::ROLE_TEACHER) {
            $account = $this->schools->findActiveTeacherLogin($db, $user->verified_id)
                ?? throw new ApiException('You do not have access to this', 403);

            $caller = new HomeworkCaller(
                $user,
                $db,
                $dbName,
                userId: (int) $account->id,
                teacherCode: $account->teacher_code ? (int) $account->teacher_code : null,
                teacherName: $account->teacher_name,
            );
        } else {
            $rows = $db->table('student_current_status_info')
                ->where('student_registration_code', $user->verified_id)
                ->get(['current_status_info_id', 'active_status']);

            $caller = new HomeworkCaller(
                $user,
                $db,
                $dbName,
                studentIds: $rows->pluck('current_status_info_id')->map(fn ($id) => (int) $id)->all(),
                activeStudentIds: $rows->where('active_status', 1)->pluck('current_status_info_id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        $request->attributes->set('homework_caller', $caller);

        return $next($request);
    }
}
