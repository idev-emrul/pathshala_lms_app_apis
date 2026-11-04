<?php

namespace App\Services\Quiz;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a student does with a quiz. The server clock decides every deadline, and scoring only
 * happens here. The answer key never goes to the student before release.
 */
class QuizAttemptService
{
    public function __construct(
        private SchoolDbService $schools,
        private QuizReader $reader,
        private QuizLifecycle $lifecycle,
    ) {}

    /**
     * Accepting twice changes nothing.
     */
    public function accept(HomeworkCaller $caller, object $row, Request $request): void
    {
        $status = $this->reader->acceptance($row->row_status);

        if ($status === 'accepted') {
            return;
        }

        if ($status === 'not_accepted') {
            throw new ApiException('The acceptance deadline has passed.');
        }

        if ($row->status === 'closed') {
            throw new ApiException('This quiz is closed.');
        }

        $this->schools->connectForQuiz($caller->dbName)->table('qz_quiz_students')
            ->where('id', $row->row_id)
            ->whereIn('status', ['pending', 'reopened'])
            ->update([
                'status' => 'accepted',
                'accepted_at' => HomeworkTime::now(),
                'accept_ip' => $request->ip(),
                'accepted_via' => 'app',
            ]);
    }

    /**
     * Calling this again during an attempt returns the same attempt, with the same end time.
     */
    public function start(HomeworkCaller $caller, object $row, Request $request): array
    {
        $db = $this->schools->connectForQuiz($caller->dbName);

        try {
            $attempt = $db->transaction(function () use ($db, $row, $request) {
                $db->table('qz_quiz_students')->where('id', $row->row_id)->lockForUpdate()->first();

                $current = $db->table('qz_attempts')->where('quiz_student_id', $row->row_id)->where('status', 'in_progress')->first();

                if ($current) {
                    return $current;
                }

                $this->ensureCanStart($db, $row);

                return $this->createAttempt($db, $row, $request);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('The same request is still running', 409);
        }

        return $this->attemptData($caller->db, $attempt, $row);
    }

    /**
     * A submit after the time ran out closes the attempt with what was saved, and is refused.
     * Submitting again after a submit changes nothing.
     */
    public function submit(HomeworkCaller $caller, object $row, mixed $answers): void
    {
        $db = $this->schools->connectForQuiz($caller->dbName);

        // Refusals are thrown after the transaction, so a closed attempt stays closed.
        $outcome = $db->transaction(function () use ($db, $row, $answers) {
            $db->table('qz_quiz_students')->where('id', $row->row_id)->lockForUpdate()->first();

            $attempt = $db->table('qz_attempts')->where('quiz_student_id', $row->row_id)->where('status', 'in_progress')->orderByDesc('attempt_no')->first();

            if (! $attempt) {
                // The status update before this request may already have closed a timed-out attempt.
                $latest = $db->table('qz_attempts')->where('quiz_student_id', $row->row_id)->orderByDesc('attempt_no')->value('status');

                return match ($latest) {
                    null => 'not_started',
                    'auto_submitted' => 'timed_out',
                    default => 'done',
                };
            }

            $limit = Carbon::parse($attempt->expires_at)->addSeconds(QuizLifecycle::SUBMIT_GRACE_SECONDS)->toDateTimeString();

            if (HomeworkTime::now() > $limit) {
                $this->lifecycle->closeAttempt($db, $attempt, 'auto_submitted', $attempt->expires_at);

                return 'timed_out';
            }

            $this->saveAnswers($db, $attempt, $row, $answers);
            $this->lifecycle->closeAttempt($db, $attempt, 'submitted', HomeworkTime::now());

            return 'submitted';
        });

        if ($outcome === 'not_started') {
            throw new ApiException('Start the quiz first.');
        }

        if ($outcome === 'timed_out') {
            throw new ApiException('The time for this attempt has ended. Your answers were not saved.');
        }
    }

    private function ensureCanStart(Connection $db, object $row): void
    {
        if ($row->conduct_mode !== 'in_app') {
            throw new ApiException('This quiz is held in class.');
        }

        if ($this->reader->acceptance($row->row_status) !== 'accepted') {
            throw new ApiException('Accept the quiz first.');
        }

        if ($row->status === 'closed' || $row->is_released) {
            throw new ApiException('This quiz is closed.');
        }

        if ($row->start_at && ! HomeworkTime::isPast($row->start_at)) {
            throw new ApiException('This quiz has not started yet.');
        }

        if (HomeworkTime::isPast($this->reader->closesAt($row))) {
            throw new ApiException('This quiz is closed.');
        }

        $used = $db->table('qz_attempts')->where('quiz_student_id', $row->row_id)->count();

        if ($used >= (int) $row->max_attempts) {
            throw new ApiException('All '.$row->max_attempts.' attempt(s) are used.');
        }
    }

    private function createAttempt(Connection $db, object $row, Request $request): object
    {
        $now = HomeworkTime::now();
        $closes = $this->reader->closesAt($row);
        $expires = $row->duration_minutes ? Carbon::parse($now)->addMinutes((int) $row->duration_minutes)->toDateTimeString() : $closes;

        $id = $db->table('qz_attempts')->insertGetId([
            'quiz_student_id' => $row->row_id,
            'attempt_no' => $db->table('qz_attempts')->where('quiz_student_id', $row->row_id)->max('attempt_no') + 1,
            'started_at' => $now,
            'expires_at' => min($expires, $closes),
            'status' => 'in_progress',
            'question_order_json' => json_encode($this->questionOrder($db, $row)),
            'via' => 'app',
            'client_ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);

        return $db->table('qz_attempts')->where('id', $id)->first();
    }

    /**
     * Saved with the attempt, so a shuffled order does not change on a resume.
     */
    private function questionOrder(Connection $db, object $row): array
    {
        $questions = $db->table('qz_quiz_questions')->where('quiz_id', $row->id)->orderBy('sort_order')->get();
        $questionIds = $questions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $options = [];

        if ($row->shuffle_questions) {
            shuffle($questionIds);
        }

        foreach ($questions as $question) {
            $keys = array_column(json_decode($question->snapshot_json, true)['options'] ?? [], 'key');

            if ($row->shuffle_options) {
                shuffle($keys);
            }

            $options[$question->id] = $keys;
        }

        return ['q' => $questionIds, 'o' => $options];
    }

    private function attemptData(Connection $db, object $attempt, object $row): array
    {
        $order = json_decode($attempt->question_order_json, true);
        $questions = $db->table('qz_quiz_questions')->where('quiz_id', $row->id)->get()->keyBy('id');
        $answers = $db->table('qz_attempt_answers')->where('attempt_id', $attempt->id)->pluck('selected_option_ids', 'quiz_question_id');

        return [
            'quiz_id' => (string) $row->id,
            'ends_at' => HomeworkTime::toApi($attempt->expires_at),
            'attempt_no' => (int) $attempt->attempt_no,
            'seconds_left' => max(0, (int) Carbon::parse(HomeworkTime::now())->diffInSeconds(Carbon::parse($attempt->expires_at), false)),
            'questions' => collect($order['q'])->map(function ($id) use ($questions, $answers, $order) {
                $question = $questions[$id];
                $snapshot = json_decode($question->snapshot_json, true);
                $texts = array_column($snapshot['options'] ?? [], 'text', 'key');
                $keys = $order['o'][$id] ?? [];
                $selected = isset($answers[$id]) ? array_values(array_intersect($keys, explode(',', (string) $answers[$id]))) : [];
                $indexes = array_map(fn ($key) => array_search($key, $keys, true), $selected);

                return [
                    'id' => (string) $question->id,
                    'text' => $snapshot['stem'] ?? null,
                    'options' => array_map(fn ($key) => $texts[$key] ?? null, $keys),
                    'marks' => $this->reader->number($question->marks),
                    'type' => $question->question_type,
                    'negative_marks' => $this->reader->number($question->negative_marks),
                    'selected' => $question->question_type === 'mcq_multi' ? $indexes : ($indexes[0] ?? null),
                ] + (empty($snapshot['stimulus']) ? [] : ['stimulus' => $snapshot['stimulus']]);
            })->all(),
        ];
    }

    /**
     * Indexes are positions in the options as this attempt showed them, which may be shuffled.
     * One bad answer means nothing is saved.
     */
    private function saveAnswers(Connection $db, object $attempt, object $row, mixed $answers): void
    {
        if ($answers !== null && ! is_array($answers)) {
            throw ValidationException::withMessages(['answers' => 'Send answers as question id: option index.']);
        }

        $order = json_decode($attempt->question_order_json, true);
        $types = $db->table('qz_quiz_questions')->where('quiz_id', $row->id)->pluck('question_type', 'id');
        $selected = [];
        $errors = [];

        foreach ($answers ?? [] as $questionId => $answer) {
            if (! isset($types[$questionId])) {
                $errors["answers.$questionId"] = 'Unknown question.';

                continue;
            }

            $keys = $order['o'][$questionId] ?? [];
            $multi = $types[$questionId] === 'mcq_multi';
            $indexes = is_array($answer) ? array_values($answer) : [$answer];
            $valid = ($multi || ! is_array($answer)) && $indexes !== [];

            foreach ($indexes as $index) {
                $valid = $valid && (is_int($index) || (is_string($index) && ctype_digit($index))) && isset($keys[(int) $index]);
            }

            if (! $valid) {
                $errors["answers.$questionId"] = 'Option index is out of range.';

                continue;
            }

            $picked = array_unique(array_map(fn ($index) => $keys[(int) $index], $indexes));
            sort($picked);
            $selected[$questionId] = implode(',', $picked);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($selected as $questionId => $value) {
            $db->table('qz_attempt_answers')->updateOrInsert(
                ['attempt_id' => $attempt->id, 'quiz_question_id' => $questionId],
                ['selected_option_ids' => $value, 'saved_at' => HomeworkTime::now()],
            );
        }
    }
}
