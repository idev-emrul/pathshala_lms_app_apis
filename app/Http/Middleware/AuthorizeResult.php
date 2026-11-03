<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\ResultReport\ResultCaller;
use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Results are for verified students and teachers of a school. Runs after jwt.app and tenant.
 * result:teacher allows teachers only; plain "result" allows both roles.
 * The school database is checked on every request, so a banned teacher loses access at once.
 */
class AuthorizeResult
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

            $caller = new ResultCaller(
                $user,
                $db,
                $dbName,
                userId: (int) $account->id,
                teacherCode: $account->teacher_code ? (int) $account->teacher_code : null,
            );
        } else {
            $caller = new ResultCaller(
                $user,
                $db,
                $dbName,
                studentIds: $db->table('student_current_status_info')
                    ->where('student_registration_code', $user->verified_id)
                    ->pluck('current_status_info_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            );
        }

        $request->attributes->set('result_caller', $caller);

        return $next($request);
    }
}
