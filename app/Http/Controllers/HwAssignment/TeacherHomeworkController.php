<?php

namespace App\Http\Controllers\HwAssignment;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\HwAssignment\AssessmentService;
use App\Services\HwAssignment\AssignmentService;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkReader;
use App\Services\HwAssignment\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherHomeworkController extends Controller
{
    public function __construct(
        private HomeworkReader $reader,
        private AssignmentService $assignments,
        private AssessmentService $assessments,
        private IdempotencyService $idempotency,
    ) {}

    public function filterOptions(Request $request): JsonResponse
    {
        return ApiResponse::success('Filter options', $this->reader->filterOptions($this->caller($request)->db));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Dashboard', $this->reader->dashboard($caller, $this->filters($request, $caller)));
    }

    public function index(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Homework', $this->reader->teacherList($caller, $this->filters($request, $caller)));
    }

    public function teachingOptions(Request $request): JsonResponse
    {
        return ApiResponse::success('Teaching options', $this->reader->teachingOptions($this->caller($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $caller = $this->caller($request);
        $create = fn () => ['id' => (string) $this->assignments->create($caller, $request)];
        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            $data = $create();
            $this->logCreate($request, $caller, $data['id']);

            return ApiResponse::success('Homework created', $data, 201);
        }

        $result = $this->idempotency->remember($caller, $key, 'teacher/homework', 201, $create);
        $this->logCreate($request, $caller, $result['data']['id']);

        return ApiResponse::success('Homework created', $result['data'], $result['status'])
            ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function show(Request $request, int $homeworkId): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Homework', $this->reader->teacherDetail($caller->db, $this->reader->findVisible($caller, $homeworkId)));
    }

    public function update(Request $request, int $homeworkId): JsonResponse
    {
        $caller = $this->caller($request);

        $this->assignments->update($caller, $this->reader->findVisible($caller, $homeworkId), $request);

        $homework = $this->reader->findVisible($caller, $homeworkId);
        $this->logMessage($request, $caller, 'updated homework: '.$homework->title);

        return ApiResponse::success('Homework updated', $this->reader->teacherDetail($caller->db, $homework));
    }

    public function destroy(Request $request, int $homeworkId): JsonResponse
    {
        $caller = $this->caller($request);

        $homework = $this->reader->findVisible($caller, $homeworkId);

        $this->assignments->delete($caller, $homework);
        $this->logMessage($request, $caller, 'deleted homework: '.$homework->title);

        return ApiResponse::success('Homework deleted', ['id' => (string) $homeworkId, 'deleted' => true]);
    }

    public function roster(Request $request, int $homeworkId): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Submissions', $this->reader->roster($caller->db, $this->reader->findVisible($caller, $homeworkId)));
    }

    public function work(Request $request, int $homeworkId, int $studentId): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Submission', $this->reader->studentWork($caller->db, $this->reader->findVisible($caller, $homeworkId), $studentId));
    }

    public function assess(Request $request, int $homeworkId, int $studentId): JsonResponse
    {
        $caller = $this->caller($request);
        $homework = $this->reader->findVisible($caller, $homeworkId);

        $this->assessments->assess($caller, $homework, $studentId, $request->all());

        $work = $this->reader->studentWork($caller->db, $homework, $studentId);
        $this->logMessage($request, $caller, 'assessed '.($work['student_name'] ?? 'a student').' on homework: '.$homework->title);

        return ApiResponse::success('Assessment saved', $work);
    }

    public function requestResubmission(Request $request, int $homeworkId, int $studentId): JsonResponse
    {
        $caller = $this->caller($request);
        $homework = $this->reader->findVisible($caller, $homeworkId);

        $this->assessments->requestResubmission($caller, $homework, $studentId, $request->all());

        $work = $this->reader->studentWork($caller->db, $homework, $studentId);
        $this->logMessage($request, $caller, 'requested resubmission from '.($work['student_name'] ?? 'a student').' on homework: '.$homework->title);

        return ApiResponse::success('Resubmission requested', $work);
    }

    private function logCreate(Request $request, HomeworkCaller $caller, string $homeworkId): void
    {
        $title = $caller->db->table('hw_assignments')->where('id', $homeworkId)->value('title');

        $request->attributes->set('activity_details', ['homeworkId' => $homeworkId]);
        $this->logMessage($request, $caller, 'created homework: '.$title);
    }

    private function logMessage(Request $request, HomeworkCaller $caller, string $text): void
    {
        $request->attributes->set('activity_message', ($caller->teacherName ?? 'A teacher').' '.$text);
    }

    private function caller(Request $request): HomeworkCaller
    {
        return $request->attributes->get('homework_caller');
    }

    private function filters(Request $request, HomeworkCaller $caller): array
    {
        // Filtering by teacher is only for admins, and admins cannot log in to the app yet.
        if ($request->has('teacher_id')) {
            throw new ApiException('Only an admin can filter by teacher', 403);
        }

        $input = $request->validate([
            'session_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'subject_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,closed'],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        if (! blank($input['type'] ?? null)) {
            $type = $this->reader->activeTypes($caller->db)
                ->first(fn ($row) => (string) $row->id === $input['type'] || $this->reader->slug($row->type_name) === $input['type']);

            if (! $type) {
                throw ValidationException::withMessages(['type' => 'Unknown type.']);
            }

            $input['type_id'] = $type->id;
        }

        return $input;
    }
}
