<?php

namespace App\Services\Quiz;

use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Status changes that depend on the clock, and scoring. No cron job runs these, so they are
 * applied before a quiz is read or changed, as the web pages do.
 */
class QuizLifecycle
{
    public const SUBMIT_GRACE_SECONDS = 30;

    public function __construct(private SchoolDbService $schools) {}

    /**
     * Past the deadline, pending becomes not accepted. Timed-out attempts are closed with what
     * was saved. Results set to release after the due time are released.
     */
    public function sync(string $dbName, array $quizIds): void
    {
        if (! $quizIds) {
            return;
        }

        $db = $this->schools->connectForQuiz($dbName);
        $now = HomeworkTime::now();

        $db->table('qz_quiz_students as row')
            ->join('qz_quizzes as quiz', 'quiz.id', '=', 'row.quiz_id')
            ->whereIn('row.quiz_id', $quizIds)
            ->whereIn('row.status', ['pending', 'reopened'])
            ->whereRaw('COALESCE(row.reopen_deadline, quiz.accept_deadline) < ?', [$now])
            ->update(['row.status' => 'not_accepted']);

        $timedOut = $db->table('qz_attempts as attempt')
            ->join('qz_quiz_students as row', 'row.id', '=', 'attempt.quiz_student_id')
            ->whereIn('row.quiz_id', $quizIds)
            ->where('attempt.status', 'in_progress')
            ->where('attempt.expires_at', '<', Carbon::parse($now)->subSeconds(self::SUBMIT_GRACE_SECONDS)->toDateTimeString())
            ->get(['attempt.*']);

        foreach ($timedOut as $attempt) {
            $db->transaction(fn () => $this->closeAttempt($db, $attempt, 'auto_submitted', $attempt->expires_at));
        }

        $autoRelease = $db->table('qz_quizzes')
            ->whereIn('id', $quizIds)
            ->where('is_deleted', 0)
            ->where('result_release_mode', 'auto_after_due')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('due_at', '<', $now)
            ->get();

        foreach ($autoRelease as $quiz) {
            $this->release($db, $quiz);
        }
    }

    /**
     * Scores the attempt from its saved answers, closes it and updates the student's result.
     * Runs inside the caller's transaction.
     */
    public function closeAttempt(Connection $db, object $attempt, string $status, string $submittedAt): void
    {
        $row = $db->table('qz_quiz_students')->where('id', $attempt->quiz_student_id)->first();
        $questions = $db->table('qz_quiz_questions')->where('quiz_id', $row->quiz_id)->get();
        $answers = $db->table('qz_attempt_answers')->where('attempt_id', $attempt->id)->get()->keyBy('quiz_question_id');
        $total = 0.0;

        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? null;

            if (! $answer || blank($answer->selected_option_ids)) {
                continue;
            }

            $isCorrect = $this->isCorrect($question, explode(',', $answer->selected_option_ids));
            $marks = $isCorrect ? (float) $question->marks : -(float) $question->negative_marks;
            $total += $marks;

            $db->table('qz_attempt_answers')->where('id', $answer->id)->update(['is_correct' => $isCorrect ? 1 : 0, 'auto_marks' => $marks]);
        }

        // Negative marks cannot take an attempt below zero.
        $score = max(0, round($total, 2));

        $db->table('qz_attempts')->where('id', $attempt->id)->update([
            'status' => $status,
            'submitted_at' => $submittedAt,
            'auto_score' => $score,
            'final_score' => $score,
        ]);

        $this->refreshResult($db, $row);
    }

    /**
     * Sets the marks and remarks, and releases nothing. A teacher's marks are not overwritten.
     */
    public function saveMarks(Connection $db, object $row, object $quiz, float $obtained, array $extra): void
    {
        $percent = $this->percent($obtained, (float) $quiz->total_marks);
        $band = $this->band($db, $percent);

        $db->table('qz_quiz_students')->where('id', $row->id)->update($extra + [
            'obtained_marks' => $obtained,
            'percentage' => $percent,
            'grading_band_id' => $band?->id,
            'remarks_label' => $band?->label_en,
        ]);
    }

    /**
     * Releases every scored, accepted student not yet released. Returns how many.
     */
    public function release(Connection $db, object $quiz): int
    {
        $rows = fn () => $db->table('qz_quiz_students')
            ->where('quiz_id', $quiz->id)
            ->where('status', 'accepted')
            ->whereIn('evaluation_status', ['auto_scored', 'evaluated'])
            ->where('is_released', 0);

        $count = $rows()->count();

        if (! $count) {
            return 0;
        }

        $db->transaction(function () use ($db, $quiz, $rows, $count) {
            $now = HomeworkTime::now();

            $rows()->update(['is_released' => 1, 'released_at' => $now]);
            $db->table('qz_quizzes')->where('id', $quiz->id)->update(['status' => 'released', 'released_at' => $now]);

            $db->table('qz_notification_events')->insert([
                'event_type' => 'result_released',
                'quiz_id' => $quiz->id,
                'student_id' => null,
                'payload' => json_encode(['title' => $quiz->title, 'students' => $count], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now,
            ]);
        });

        return $count;
    }

    /**
     * The best attempt counts.
     */
    private function refreshResult(Connection $db, object $row): void
    {
        if ($row->evaluation_status === 'evaluated') {
            return;
        }

        $best = $db->table('qz_attempts')
            ->where('quiz_student_id', $row->id)
            ->whereIn('status', ['submitted', 'auto_submitted'])
            ->max('final_score');

        $quiz = $db->table('qz_quizzes')->where('id', $row->quiz_id)->first();

        $this->saveMarks($db, $row, $quiz, (float) $best, ['evaluation_status' => 'auto_scored']);
    }

    /**
     * Several right answers must all be chosen, and nothing else.
     */
    private function isCorrect(object $question, array $selected): bool
    {
        $options = json_decode($question->snapshot_json, true)['options'] ?? [];
        $correct = array_column(array_filter($options, fn ($option) => ! empty($option['is_correct'])), 'key');

        sort($correct);
        sort($selected);

        return $correct === $selected;
    }

    private function percent(float $obtained, float $total): float
    {
        return $total > 0 ? round($obtained / $total * 100, 2) : 0.0;
    }

    /**
     * The highest band the score has reached, so there is no gap between 79.99 and 80.
     */
    private function band(Connection $db, float $percent): ?object
    {
        return $db->table('perf_grading_bands')
            ->where('status', 1)
            ->whereIn('scope', ['all', 'quiz'])
            ->where('min_percent', '<=', $percent)
            ->orderByDesc('min_percent')
            ->first();
    }
}
