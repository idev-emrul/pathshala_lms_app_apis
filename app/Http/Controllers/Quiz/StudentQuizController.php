<?php

namespace App\Http\Controllers\Quiz;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\IdempotencyService;
use App\Services\Quiz\QuizAttemptService;
use App\Services\Quiz\QuizLifecycle;
use App\Services\Quiz\QuizReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentQuizController extends Controller
{
    public function __construct(
        private QuizReader $reader,
        private QuizLifecycle $lifecycle,
        private QuizAttemptService $attempts,
        private IdempotencyService $idempotency,
    ) {}

    public function index(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->caller($request, $studentId);

        $input = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'status' => ['nullable', 'in:pending,accepted,not_accepted'],
        ]);

        $this->lifecycle->sync($caller->dbName, $this->reader->studentQuizIds($caller->db, $studentId));

        return ApiResponse::success('Quizzes', $this->reader->report($caller->db, $studentId, $input));
    }

    public function show(Request $request, int $studentId, int $quizId): JsonResponse
    {
        $caller = $this->caller($request, $studentId);

        return ApiResponse::success('Quiz', $this->reader->studentQuiz($caller->db, $this->freshRow($caller, $studentId, $quizId)));
    }

    public function accept(Request $request, int $studentId, int $quizId): JsonResponse
    {
        $caller = $this->activeCaller($request, $studentId);
        $row = $this->freshRow($caller, $studentId, $quizId);

        $result = $this->idempotency->once($request, $caller, "students/quizzes/$quizId/accept", 200, function () use ($caller, $row, $request) {
            $this->attempts->accept($caller, $row, $request);

            return ['id' => (string) $row->id];
        });

        return $this->quizResponse($request, $caller, $studentId, $quizId, 'Quiz accepted', 'accepted quiz', $result);
    }

    public function start(Request $request, int $studentId, int $quizId): JsonResponse
    {
        $caller = $this->activeCaller($request, $studentId);
        $row = $this->freshRow($caller, $studentId, $quizId);

        $this->setActivity($request, $caller, $studentId, 'started quiz: '.$row->title);

        return ApiResponse::success('Attempt started', $this->attempts->start($caller, $row, $request));
    }

    public function submit(Request $request, int $studentId, int $quizId): JsonResponse
    {
        $caller = $this->activeCaller($request, $studentId);
        $row = $this->freshRow($caller, $studentId, $quizId);

        $result = $this->idempotency->once($request, $caller, "students/quizzes/$quizId/attempt/submit", 200, function () use ($caller, $row, $request) {
            $this->attempts->submit($caller, $row, $request->input('answers'));

            return ['id' => (string) $row->id];
        });

        return $this->quizResponse($request, $caller, $studentId, $quizId, 'Quiz submitted', 'submitted quiz', $result);
    }

    /**
     * The quiz as it is now, so a repeated key also shows the current state.
     */
    private function quizResponse(Request $request, HomeworkCaller $caller, int $studentId, int $quizId, string $message, string $action, array $result): JsonResponse
    {
        $row = $this->reader->findForStudent($caller->db, $studentId, $quizId);

        $this->setActivity($request, $caller, $studentId, $action.': '.$row->title);

        $response = ApiResponse::success($message, $this->reader->studentQuiz($caller->db, $row), $result['status']);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    private function freshRow(HomeworkCaller $caller, int $studentId, int $quizId): object
    {
        $this->reader->findForStudent($caller->db, $studentId, $quizId);
        $this->lifecycle->sync($caller->dbName, [$quizId]);

        return $this->reader->findForStudent($caller->db, $studentId, $quizId);
    }

    private function setActivity(Request $request, HomeworkCaller $caller, int $studentId, string $text): void
    {
        $name = $caller->db->table('student_current_status_info as current')
            ->join('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->where('current.current_status_info_id', $studentId)
            ->value('student.student_name');

        $request->attributes->set('activity_message', ($name ?? 'A student').' '.$text);
    }

    /**
     * The student in the URL must be the caller. A body or path id never decides who they are.
     */
    private function caller(Request $request, int $studentId): HomeworkCaller
    {
        $caller = $request->attributes->get('homework_caller');

        if (! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        $this->reader->ensureSetUp($caller->db);

        return $caller;
    }

    private function activeCaller(Request $request, int $studentId): HomeworkCaller
    {
        $caller = $this->caller($request, $studentId);

        if (! in_array($studentId, $caller->activeStudentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        return $caller;
    }
}
