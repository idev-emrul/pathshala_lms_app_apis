<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\ClassRoutine\RoutineCaller;
use App\Services\SchoolDbService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The routine is for verified students and teachers of a school. Runs after jwt.app and tenant.
 * The school database is checked on every request, so a banned teacher loses access at once.
 */
class AuthorizeRoutine
{
    public function __construct(private SchoolDbService $schools) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->attributes->get('app_user');
        $db = $request->attributes->get('tenant');

        if ($user->role !== $role || ! $user->is_verified || ! $db) {
            throw new ApiException('You do not have access to this', 403);
        }

        if ($role === AppUser::ROLE_TEACHER) {
            $account = $this->schools->findActiveTeacherLogin($db, $user->verified_id)
                ?? throw new ApiException('You do not have access to this', 403);

            $caller = new RoutineCaller(
                $user,
                $db,
                userId: (int) $account->id,
                teacherCode: $account->teacher_code ? (int) $account->teacher_code : null,
            );
        } else {
            $placements = $db->table('student_current_status_info')
                ->where('student_registration_code', $user->verified_id)
                ->get(['current_status_info_id', 'session_code', 'class_code', 'section_code', 'group_code', 'shift_code', 'institute_medium_id'])
                ->keyBy(fn ($row) => (int) $row->current_status_info_id)
                ->all();

            $caller = new RoutineCaller($user, $db, placements: $placements);
        }

        $request->attributes->set('routine_caller', $caller);

        return $next($request);
    }
}
