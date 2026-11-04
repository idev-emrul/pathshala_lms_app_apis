<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Models\School;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * What a guardian sees. Children can be in different schools, so each one opens its own
 * school database, read-only. The guardian's token holds no school.
 */
class GuardianChildService
{
    public function __construct(private SchoolDbService $schools) {}

    /**
     * Oldest first, so the first child stays the same as more are added.
     */
    public function children(AppUser $user): array
    {
        return AppGuardianChild::where('app_user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AppGuardianChild $child) => $this->info($child))
            ->all();
    }

    /**
     * The first child that can be shown, and the full list for switching.
     */
    public function dashboard(AppUser $user): array
    {
        $children = $this->children($user);

        return [
            'child' => collect($children)->firstWhere('is_available', true) ?? ($children[0] ?? null),
            'children' => $children,
        ];
    }

    public function child(AppUser $user, int $childId): array
    {
        return $this->info($this->find($user, $childId));
    }

    public function remove(AppUser $user, int $childId): void
    {
        $this->find($user, $childId)->delete();
    }

    /**
     * Finds the guardian's child by their student id. These ids are per school, so two children
     * in different schools can share one. The app then says which with child_id.
     *
     * @return array{0: Connection, 1: int, 2: string}
     */
    public function currentStudentById(AppUser $user, int $studentId, ?int $childId): array
    {
        $matches = AppGuardianChild::where('app_user_id', $user->id)
            ->when($childId, fn ($query, $value) => $query->where('id', $value))
            ->orderBy('id')
            ->get()
            ->filter(function (AppGuardianChild $child) use ($user, $studentId) {
                try {
                    return $this->currentStudent($user, $child->id)[1] === $studentId;
                } catch (ApiException|QueryException) {
                    return false;
                }
            })
            ->values();

        if ($matches->isEmpty()) {
            throw new ApiException('You do not have access to this', 403, errorCode: 'not_linked');
        }

        if ($matches->count() > 1) {
            throw new ApiException('Two of your children have this id. Send child_id to choose one.', 409, [
                'child_ids' => $matches->map(fn ($child) => (string) $child->id)->all(),
            ]);
        }

        // Looking through the children moved the connection, so it goes back to this child's school.
        return $this->currentStudent($user, $matches[0]->id);
    }

    /**
     * The child's school database, read-only, their current class row, which is the student
     * id in the homework and quiz tables, and the database name for the quiz status updates.
     *
     * @return array{0: Connection, 1: int, 2: string}
     */
    public function currentStudent(AppUser $user, int $childId): array
    {
        $child = $this->find($user, $childId);
        $school = $this->schools->findSchool($child->schl_id);
        $tenant = $this->schools->connectToSchool($school);

        $placement = $this->schools->currentPlacement($tenant, $child->student_id)
            ?? throw new ApiException('This child has no current class', 404);

        return [$tenant, (int) $placement->current_status_info_id, $school->db_name];
    }

    /**
     * One school being down, or a student who has left, must not hide the other children.
     */
    public function info(AppGuardianChild $child): array
    {
        $school = School::find($child->schl_id);

        $info = [
            'id' => (string) $child->id,
            'student_id' => $child->student_id,
            'homework_student_id' => null,
            'school_name' => $school?->schl_name,
            'student_name' => null,
            'session_name' => null,
            'class_name' => null,
            'section_name' => null,
            'shift_name' => null,
            'group_name' => null,
            'roll_number' => null,
            'is_available' => false,
        ];

        if (! $school?->db_name) {
            return $info;
        }

        try {
            $tenant = $this->schools->connectToSchool($school);
            $student = $this->schools->findStudent($tenant, $child->student_id);
            $placement = $student ? $this->schools->currentPlacement($tenant, $child->student_id) : null;
        } catch (ApiException|QueryException $e) {
            report($e);

            return $info;
        }

        if (! $placement) {
            return $info;
        }

        return [
            'homework_student_id' => (string) $placement->current_status_info_id,
            'student_name' => $student->student_name,
            'session_name' => $placement->session_name,
            'class_name' => $placement->class_name,
            'section_name' => $placement->section_name,
            'shift_name' => $placement->shift_name,
            'group_name' => $placement->group_name,
            'roll_number' => $placement->roll_number,
            'is_available' => true,
        ] + $info;
    }

    private function find(AppUser $user, int $childId): AppGuardianChild
    {
        return AppGuardianChild::where('app_user_id', $user->id)->find($childId)
            ?? throw new ApiException('Child not found', 404);
    }
}
