<?php

namespace App\Http\Controllers\Quiz;

use App\Http\Controllers\Controller;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\IdempotencyService;
use App\Services\Quiz\QuizLifecycle;
use App\Services\Quiz\QuizReader;
use App\Services\Quiz\QuizTeacherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherQuizController extends Controller
{
    public function __construct(
        private QuizReader $reader,
        private QuizLifecycle $lifecycle,
        private QuizTeacherService $quizzes,
        private IdempotencyService $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        $input = $request->validate([
            'class_id' => ['nullable', 'string', 'regex:/^\d+(-\d+)?$/'],
            'subject_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,published,released,closed,cancelled'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $this->lifecycle->sync($caller->dbName, $this->reader->visibleQuizzes($caller)->pluck('quiz.id')->all());

        return ApiResponse::success('Quizzes', $this->reader->teacherList($caller, $input));
    }

    public function roster(Request $request, int $quizId): JsonResponse
    {
        $caller = $this->caller($request);

        return ApiResponse::success('Roster', $this->reader->roster($caller->db, $this->freshQuiz($caller, $quizId)));
    }

    public function store(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        $result = $this->idempotency->once($request, $caller, 'teacher/quizzes', 201, fn () => ['id' => (string) $this->quizzes->create($caller, $request)]);

        $quiz = $this->reader->findVisible($caller, (int) $result['data']['id']);

        $request->attributes->set('activity_details', ['quizId' => (string) $quiz->id]);
        $this->logMessage($request, $caller, 'created quiz: '.$quiz->title);

        return $this->respond('Quiz created', $this->reader->teacherQuiz($caller->db, $quiz), $result);
    }

    public function saveMarks(Request $request, int $quizId): JsonResponse
    {
        $caller = $this->caller($request);
        $quiz = $this->freshQuiz($caller, $quizId);

        $result = $this->idempotency->once($request, $caller, "teacher/quizzes/$quizId/marks", 200, function () use ($caller, $quiz, $request) {
            $this->quizzes->saveMarks($caller, $quiz, $request->input('marks'));

            return ['id' => (string) $quiz->id];
        });

        $this->logMessage($request, $caller, 'saved marks on quiz: '.$quiz->title);

        return $this->respond('Marks saved', $this->reader->roster($caller->db, $this->reader->findVisible($caller, $quizId)), $result);
    }

    public function release(Request $request, int $quizId): JsonResponse
    {
        $caller = $this->caller($request);
        $quiz = $this->freshQuiz($caller, $quizId);

        $result = $this->idempotency->once($request, $caller, "teacher/quizzes/$quizId/release", 200, function () use ($caller, $quiz) {
            $this->quizzes->release($caller, $quiz);

            return ['id' => (string) $quiz->id];
        });

        $this->logMessage($request, $caller, 'released results of quiz: '.$quiz->title);

        return $this->respond('Results released', $this->reader->roster($caller->db, $this->reader->findVisible($caller, $quizId)), $result);
    }

    /**
     * The answer is built fresh, so a repeated key shows the current state and a large
     * class's roster does not have to fit in the idempotency record.
     */
    private function respond(string $message, array $data, array $result): JsonResponse
    {
        $response = ApiResponse::success($message, $data, $result['status']);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    private function freshQuiz(HomeworkCaller $caller, int $quizId): object
    {
        $this->reader->findVisible($caller, $quizId);
        $this->lifecycle->sync($caller->dbName, [$quizId]);

        return $this->reader->findVisible($caller, $quizId);
    }

    private function logMessage(Request $request, HomeworkCaller $caller, string $text): void
    {
        $request->attributes->set('activity_message', ($caller->teacherName ?? 'A teacher').' '.$text);
    }

    private function caller(Request $request): HomeworkCaller
    {
        $caller = $request->attributes->get('homework_caller');

        $this->reader->ensureSetUp($caller->db);

        return $caller;
    }
}
