<?php

namespace App\Services\Quiz;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\AssignmentService;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Creating a quiz, marks and release. Every field is checked first, so one bad field changes nothing.
 */
class QuizTeacherService
{
    private const OPTION_KEYS = ['a', 'b', 'c', 'd', 'e', 'f'];

    private const OPTION_LABELS = ['ক', 'খ', 'গ', 'ঘ', 'ঙ', 'চ'];

    private const MAX_FEEDBACK = 2000;

    public function __construct(
        private SchoolDbService $schools,
        private QuizReader $reader,
        private QuizLifecycle $lifecycle,
        private AssignmentService $assignments,
    ) {}

    public function create(HomeworkCaller $caller, Request $request): int
    {
        // A teacher's quiz is always their own.
        if ($request->filled('teacher_id') && (int) $request->input('teacher_id') !== (int) $caller->teacherCode) {
            throw new ApiException('You can only set yourself as the teacher', 403);
        }

        $values = $this->validated($caller, $request);
        $sessionId = $this->assignments->targetSession($caller->db, $values['target']);

        if (! $sessionId) {
            throw ValidationException::withMessages(['class_id' => 'This class has no active students in a running session.']);
        }

        $students = $this->assignments->targetStudents($caller->db, $values['target'], $sessionId)->pluck('current_status_info_id');
        $institute = $caller->db->table('institute_info')->value('institute_code') ?? 1;
        $db = $this->schools->connectForQuiz($caller->dbName);

        return $db->transaction(function () use ($db, $caller, $values, $sessionId, $students, $institute) {
            $now = HomeworkTime::now();

            $quizId = $db->table('qz_quizzes')->insertGetId($values['columns'] + [
                'institute_code' => $institute,
                'academic_session_id' => $sessionId,
                'class_id' => $values['target']['class_id'],
                'section_id' => $values['target']['section_id'],
                'teacher_id' => $caller->teacherCode,
                'publish_at' => $now,
                'status' => 'published',
                'source_module' => 'quiz',
                'notify_on_publish' => 1,
                'is_deleted' => 0,
                'created_by' => $caller->userId,
                'created_at' => $now,
                'published_at' => $now,
            ]);

            $this->saveQuestions($db, $caller, $quizId, $values, $now);

            foreach ($students->chunk(500) as $chunk) {
                $db->table('qz_quiz_students')->insert($chunk->map(fn ($studentId) => [
                    'quiz_id' => $quizId,
                    'student_id' => $studentId,
                    'status' => 'pending',
                    'received_at' => $now,
                    'evaluation_status' => 'not_evaluated',
                    'is_released' => 0,
                    'created_at' => $now,
                ])->values()->all());
            }

            $db->table('qz_notification_events')->insert([
                'event_type' => 'quiz_received',
                'quiz_id' => $quizId,
                'student_id' => null,
                'payload' => json_encode([
                    'title' => $values['columns']['title'],
                    'accept_deadline' => $values['columns']['accept_deadline'],
                    'due_at' => $values['columns']['due_at'],
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now,
            ]);

            return $quizId;
        });
    }

    /**
     * Every row is checked first. One wrong row means nothing is saved.
     */
    public function saveMarks(HomeworkCaller $caller, object $quiz, mixed $marks): void
    {
        if (! is_array($marks) || ! $marks) {
            throw ValidationException::withMessages(['marks' => 'Send at least one row.']);
        }

        $rows = $caller->db->table('qz_quiz_students')->where('quiz_id', $quiz->id)->get()->keyBy('student_id');
        $total = (float) $quiz->total_marks;
        $seen = [];
        $errors = [];

        foreach (array_values($marks) as $index => $mark) {
            $studentId = is_array($mark) ? ($mark['student_id'] ?? null) : null;
            $obtained = is_array($mark) ? ($mark['obtained'] ?? null) : null;
            $feedback = is_array($mark) ? ($mark['feedback'] ?? null) : null;
            $row = is_scalar($studentId) ? ($rows[$studentId] ?? null) : null;

            if (! $row) {
                $errors["marks.$index"] = 'This student is not on this quiz.';
            } elseif (isset($seen[$studentId])) {
                $errors["marks.$index"] = 'This student is listed twice.';
            } elseif ($row->status !== 'accepted') {
                $errors["marks.$index"] = 'This student did not accept the quiz.';
            } elseif (! is_numeric($obtained) || $obtained < 0 || $obtained > $total) {
                $errors["marks.$index"] = 'obtained must be from 0 to total_marks.';
            } elseif ($feedback !== null && (! is_string($feedback) || mb_strlen($feedback) > self::MAX_FEEDBACK)) {
                $errors["marks.$index"] = 'Feedback can be at most '.self::MAX_FEEDBACK.' characters.';
            }

            if ($row) {
                $seen[$studentId] = true;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $db = $this->schools->connectForQuiz($caller->dbName);

        $db->transaction(function () use ($db, $caller, $quiz, $marks, $rows) {
            $now = HomeworkTime::now();

            foreach ($marks as $mark) {
                $this->lifecycle->saveMarks($db, $rows[$mark['student_id']], $quiz, (float) $mark['obtained'], [
                    'teacher_feedback' => $mark['feedback'] ?? $rows[$mark['student_id']]->teacher_feedback,
                    'evaluation_status' => 'evaluated',
                    'evaluated_by' => $caller->userId,
                    'evaluated_at' => $now,
                ]);
            }
        });
    }

    public function release(HomeworkCaller $caller, object $quiz): void
    {
        if (! in_array($quiz->status, ['published', 'closed', 'evaluated', 'released'], true)) {
            throw new ApiException('This quiz cannot be released.');
        }

        $released = $this->lifecycle->release($this->schools->connectForQuiz($caller->dbName), $quiz);

        if (! $released && ! $caller->db->table('qz_quiz_students')->where('quiz_id', $quiz->id)->where('is_released', 1)->exists()) {
            throw new ApiException('There are no results to release yet.');
        }
    }

    private function validated(HomeworkCaller $caller, Request $request): array
    {
        $errors = [];
        $columns = [];

        $title = trim((string) $request->input('title'));

        if ($title === '') {
            $errors['title'] = 'Required.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'At most 255 characters.';
        }

        $columns['title'] = $title;
        $columns['instructions'] = trim((string) $request->input('instructions')) ?: null;

        $target = $this->validateTarget($caller, $request, $columns, $errors);

        $total = $request->input('total_marks');

        if (! is_numeric($total) || $total <= 0) {
            $errors['total_marks'] = 'Must be more than 0.';
        } else {
            $columns['total_marks'] = (float) $total;
        }

        $this->validateDates($request, $columns, $errors);
        $settings = $this->reader->settings($caller->db);
        $this->validateOptions($request, $settings, $columns, $errors);

        $mode = $request->input('mode');
        $questions = [];

        if (! in_array($mode, ['offline', 'in_app'], true)) {
            $errors['mode'] = 'Use offline or in_app.';
        } else {
            $columns['conduct_mode'] = $mode;

            if ($mode === 'in_app') {
                $duration = $request->input('duration_minutes');

                if (! is_numeric($duration) || (int) $duration != $duration || $duration < 1 || $duration > 600) {
                    $errors['duration_minutes'] = 'Must be from 1 to 600.';
                } else {
                    $columns['duration_minutes'] = (int) $duration;
                }

                $questions = $this->validateQuestions($request, $columns['total_marks'] ?? null, $errors);
            } else {
                // A quiz held in class has no timer, and one sitting.
                $columns['duration_minutes'] = null;
                $columns['max_attempts'] = 1;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['columns' => $columns, 'target' => $target, 'questions' => $questions];
    }

    private function validateTarget(HomeworkCaller $caller, Request $request, array &$columns, array &$errors): array
    {
        $key = (string) $request->input('class_id');
        $options = $this->reader->classOptions($caller);
        [$classId, $sectionId] = array_pad(explode('-', $key), 2, null);
        $target = ['class_id' => (int) $classId, 'section_id' => $sectionId === null ? null : (int) $sectionId];

        if ($key === '') {
            $errors['class_id'] = 'Required.';
        } elseif (! isset($options['classes']->{$key})) {
            $errors['class_id'] = 'Choose one of your classes.';
        }

        $subjects = collect($options['subjects_by_class']->{$classId} ?? []);
        $subjectId = $request->input('subject_id');
        $subjectName = $request->input('subject');

        $subject = ! blank($subjectId)
            ? $subjects->firstWhere('id', (string) $subjectId)
            : (is_string($subjectName) && $subjectName !== '' ? $subjects->firstWhere('name', trim($subjectName)) : null);

        if (blank($subjectId) && blank($subjectName)) {
            $errors['subject'] = 'Required.';
        } elseif (! $subject) {
            $errors['subject'] = 'Choose one of your subjects for this class.';
        } else {
            $columns['subject_id'] = (int) $subject['id'];
        }

        return $target;
    }

    private function validateDates(Request $request, array &$columns, array &$errors): void
    {
        $parse = function (string $field) use ($request, &$errors) {
            $value = $request->input($field);

            if (! is_string($value) || strtotime($value) === false) {
                $errors[$field] = 'Use a UTC date-time such as 2026-09-21T04:00:00Z.';

                return null;
            }

            return HomeworkTime::fromApi($value);
        };

        $deadline = null;

        if (! $request->filled('due_at')) {
            $errors['due_at'] = 'Required.';
        } else {
            $deadline = $parse('due_at');

            if ($deadline && HomeworkTime::isPast($deadline)) {
                $errors['due_at'] = 'Must be in the future.';
            }
        }

        $closes = $request->filled('ends_at') ? $parse('ends_at') : $deadline;

        if ($deadline && $closes && $closes < $deadline) {
            $errors['ends_at'] = 'Must not be before due_at.';
        }

        $start = $request->filled('start_at') ? $parse('start_at') : null;

        if ($start && $closes && $start >= $closes) {
            $errors['start_at'] = 'Must be before the quiz closes.';
        }

        $columns['accept_deadline'] = $deadline;
        $columns['due_at'] = $closes;
        $columns['start_at'] = $start;
    }

    private function validateOptions(Request $request, ?object $settings, array &$columns, array &$errors): void
    {
        $columns['max_attempts'] = (int) ($settings->default_max_attempts ?? 1);

        if ($request->filled('max_attempts')) {
            $attempts = $request->input('max_attempts');

            if (! is_numeric($attempts) || (int) $attempts != $attempts || $attempts < 1 || $attempts > 10) {
                $errors['max_attempts'] = 'Must be from 1 to 10.';
            } else {
                $columns['max_attempts'] = (int) $attempts;
            }
        }

        $columns['shuffle_questions'] = $request->boolean('shuffle_questions') ? 1 : 0;
        $columns['shuffle_options'] = $request->boolean('shuffle_options') ? 1 : 0;
        $columns['result_release_mode'] = ($settings->auto_release ?? 0) ? 'auto_after_due' : 'manual';

        if ($request->filled('result_release')) {
            if (in_array($request->input('result_release'), ['manual', 'auto_after_due'], true)) {
                $columns['result_release_mode'] = $request->input('result_release');
            } else {
                $errors['result_release'] = 'Use manual or auto_after_due.';
            }
        }
    }

    private function validateQuestions(Request $request, ?float $total, array &$errors): array
    {
        $raw = $request->input('questions');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($raw) || ! $raw) {
            $errors['questions'] = 'An in-app quiz needs at least one question.';

            return [];
        }

        $questions = [];
        $sum = 0.0;

        foreach (array_values($raw) as $index => $question) {
            $field = "questions.$index";

            if (! is_array($question)) {
                $errors[$field] = 'Must be a question.';

                continue;
            }

            $text = trim((string) (is_string($question['text'] ?? null) ? $question['text'] : ''));
            $options = is_array($question['options'] ?? null) ? array_values($question['options']) : [];
            $marks = $question['marks'] ?? null;
            $negative = $question['negative_marks'] ?? 0;
            $correct = $question['correct'] ?? null;
            $indexes = is_array($correct) ? array_values($correct) : [$correct];

            if ($text === '') {
                $errors["$field.text"] = 'Required.';
            }

            $validOptions = count($options) >= 2 && count($options) <= 6;

            foreach ($options as $option) {
                $validOptions = $validOptions && is_scalar($option) && trim((string) $option) !== '';
            }

            if (! $validOptions) {
                $errors["$field.options"] = 'Send 2 to 6 options.';
            }

            $validCorrect = $indexes !== [];

            foreach ($indexes as $choice) {
                $validCorrect = $validCorrect && is_int($choice) && isset($options[$choice]);
            }

            if (! $validCorrect || count(array_unique($indexes)) !== count($indexes)) {
                $errors["$field.correct"] = 'Must be an option index, or a list of them.';
            }

            if (! is_numeric($marks) || $marks <= 0) {
                $errors["$field.marks"] = 'Must be more than 0.';
            } else {
                $sum += (float) $marks;
            }

            if (! is_numeric($negative) || $negative < 0) {
                $errors["$field.negative_marks"] = 'Must be 0 or more.';
            }

            $questions[] = [
                'text' => $text,
                'options' => $options,
                'correct' => $indexes,
                'multi' => is_array($correct) && count($indexes) > 1,
                'marks' => (float) $marks,
                'negative_marks' => (float) $negative,
                'explanation' => is_string($question['explanation'] ?? null) ? trim($question['explanation']) ?: null : null,
            ];
        }

        if ($total !== null && $questions && abs($sum - $total) > 0.001 && ! array_filter(array_keys($errors), fn ($key) => str_contains($key, '.marks'))) {
            $errors['questions'] = "The marks add up to $sum but total_marks is $total.";
        }

        return $questions;
    }

    /**
     * Also saved to the question bank as the teacher's private questions, as the web form does.
     * The quiz keeps its own copy, so later edits to the bank cannot change a result.
     */
    private function saveQuestions(Connection $db, HomeworkCaller $caller, int $quizId, array $values, string $now): void
    {
        foreach ($values['questions'] as $index => $question) {
            $type = $question['multi'] ? 'mcq_multi' : 'mcq_single';

            $bankId = $db->table('qb_questions')->insertGetId([
                'class_code' => $values['target']['class_id'],
                'subject_code' => $values['columns']['subject_id'],
                'question_type' => $type,
                'stem' => $question['text'],
                'difficulty' => 'medium',
                'default_marks' => $question['marks'],
                'explanation' => $question['explanation'],
                'visibility' => 'private',
                'status' => 'active',
                'is_deleted' => 0,
                'created_by' => $caller->userId,
                'created_at' => $now,
            ]);

            $snapshotOptions = [];

            foreach ($question['options'] as $position => $text) {
                $isCorrect = in_array($position, $question['correct'], true);
                $text = trim((string) $text);

                $snapshotOptions[] = ['key' => self::OPTION_KEYS[$position], 'text' => $text, 'is_correct' => $isCorrect ? 1 : 0];

                $db->table('qb_question_options')->insert([
                    'question_id' => $bankId,
                    'label' => self::OPTION_LABELS[$position],
                    'option_text' => $text,
                    'is_correct' => $isCorrect ? 1 : 0,
                    'sort_order' => $position + 1,
                ]);
            }

            $db->table('qz_quiz_questions')->insert([
                'quiz_id' => $quizId,
                'sort_order' => $index + 1,
                'question_id' => $bankId,
                'question_type' => $type,
                'marks' => $question['marks'],
                'negative_marks' => $question['negative_marks'],
                'snapshot_json' => json_encode([
                    'type' => $type,
                    'stem' => $question['text'],
                    'stimulus' => null,
                    'difficulty' => 'medium',
                    'explanation' => $question['explanation'],
                    'options' => $snapshotOptions,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        }
    }
}
