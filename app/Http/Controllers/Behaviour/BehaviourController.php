<?php

namespace App\Http\Controllers\Behaviour;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\Behaviour\BehaviourEntryService;
use App\Services\Behaviour\BehaviourReportService;
use App\Services\Behaviour\BehaviourSupport;
use App\Services\Behaviour\BehaviourText;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\HwAssignment\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BehaviourController extends Controller
{
    public function __construct(
        private BehaviourSupport $support,
        private BehaviourEntryService $entries,
        private BehaviourReportService $reports,
        private IdempotencyService $idempotency,
    ) {}

    public function overview(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->caller($request);
        $isStaff = in_array($caller->user->role, [AppUser::ROLE_TEACHER, AppUser::ROLE_ADMIN], true);

        if ($isStaff ? ! $this->support->scopedStudent($caller, $studentId) : ! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException($isStaff ? 'This student is not one of your students.' : 'You can only see your own behaviour profile.', 403);
        }

        $student = $this->support->student($caller->db, $studentId) ?? throw new ApiException('Student not found.', 404);

        return ApiResponse::success('Behaviour', $this->reports->overview($caller->db, $student));
    }

    public function roster(Request $request): JsonResponse
    {
        $students = $this->support->students($this->caller($request), [
            'session_code' => $this->digits($request->query('session_id')),
            'class_code' => $this->digits($request->query('class_id')),
            'section_code' => $this->digits($request->query('section_id')),
            'search' => BehaviourText::clean($request->query('search', ''), 60),
        ], 1000);

        return ApiResponse::success('Roster', $students->map(fn (object $student) => [
            'student_id' => (string) $student->student_id,
            'name' => $student->student_name,
            'roll' => (string) $student->roll_number,
            'class' => (string) $student->class_name,
            'section' => (string) $student->section_name,
        ])->all());
    }

    public function quickEntry(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        $result = $this->idempotency->once($request, $caller, 'behaviour/quick-entry', 201, function () use ($request, $caller) {
            $student = $this->support->managedStudent($caller, $request->input('student_id'));
            $ratings = $this->ratings($request, $caller);
            $subject = $this->digits($request->input('subject_id'));

            $this->entries->saveEntry(
                $caller,
                $student,
                $ratings,
                BehaviourText::clean($request->input('comment', ''), 1000),
                $subject ? (int) $subject : null,
                $this->pastDate($request, 'observed_on'),
                BehaviourText::isTrue($request->input('support_required', false)),
            );

            return [];
        });

        return $this->respond($request, 'Entry saved', $result);
    }

    public function recognition(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        $result = $this->idempotency->once($request, $caller, 'behaviour/recognition', 201, function () use ($request, $caller) {
            $student = $this->support->managedStudent($caller, $request->input('student_id'));
            $tag = trim((string) $request->input('tag', ''));

            if ($tag === '') {
                throw ValidationException::withMessages(['tag' => 'tag is required.']);
            }

            if (! in_array($tag, $this->support->tags($caller->db), true)) {
                throw ValidationException::withMessages(['tag' => 'Unknown recognition tag.']);
            }

            $this->entries->saveRecognition(
                $caller,
                $student,
                $tag,
                BehaviourText::clean($request->input('note', ''), 255),
                $this->pastDate($request, 'date'),
            );

            return [];
        });

        return $this->respond($request, 'Recognition saved', $result);
    }

    private function respond(Request $request, string $message, array $result): JsonResponse
    {
        $request->attributes->set('activity_message', $message);

        $response = ApiResponse::success($message, $result['data'] ?: null, $result['status']);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    private function caller(Request $request): HomeworkCaller
    {
        return $request->attributes->get('behaviour_caller');
    }

    /**
     * @return array<string, string> indicator => rating
     */
    private function ratings(Request $request, HomeworkCaller $caller): array
    {
        $posted = $request->input('ratings', []);

        if (is_string($posted)) {
            $posted = json_decode($posted, true);
        }

        $indicators = $this->support->indicators($caller->db);
        $ratings = [];
        $errors = [];

        foreach ((array) $posted as $indicator => $rating) {
            $rating = is_scalar($rating) ? trim((string) $rating) : '';

            if (! in_array($indicator, $indicators, true)) {
                $errors["ratings.$indicator"] = 'Unknown indicator.';
            } elseif (! isset(BehaviourSupport::RATINGS[$rating])) {
                $errors["ratings.$indicator"] = 'Use excellent, good, developing or needs_support.';
            } else {
                $ratings[$indicator] = $rating;
            }
        }

        if (! $ratings && ! $errors) {
            $errors['ratings'] = 'Rate at least one indicator.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $ratings;
    }

    /**
     * YYYY-MM-DD, today if left out, and not in the future.
     */
    private function pastDate(Request $request, string $name): string
    {
        $date = trim((string) $request->input($name, ''));
        $today = HomeworkTime::today();

        if ($date === '') {
            return $today;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > $today) {
            throw ValidationException::withMessages([$name => 'Use YYYY-MM-DD, today or earlier.']);
        }

        return $date;
    }

    private function digits(mixed $value): ?string
    {
        return is_scalar($value) && ctype_digit((string) $value) ? (string) $value : null;
    }
}
