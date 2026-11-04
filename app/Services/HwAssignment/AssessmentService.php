<?php

namespace App\Services\HwAssignment;

use App\Exceptions\ApiException;
use App\Services\SchoolDbService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A teacher marking the latest version, or asking the student to send it again.
 */
class AssessmentService
{
    public function __construct(
        private SchoolDbService $schools,
        private HomeworkReader $reader,
    ) {}

    public function assess(HomeworkCaller $caller, object $homework, int $studentId, array $input): void
    {
        $db = $this->schools->connectForHomework($caller->dbName);

        $db->transaction(function () use ($db, $caller, $homework, $studentId, $input) {
            $row = $this->handedInRow($db, $homework->id, $studentId);

            if ($db->table('hw_resubmission_requests')->where('assignment_student_id', $row->id)->where('status', 'open')->exists()) {
                throw new ApiException('A resubmission request is open. Wait for the student to send the new version.');
            }

            $marks = $this->marks($db, $homework, $input);
            $tagIds = $this->tagIds($db, $input['tags'] ?? []);
            $feedback = $input['feedback'] ?? null;

            if ($feedback !== null && mb_strlen((string) $feedback) > 2000) {
                throw ValidationException::withMessages(['feedback' => 'Feedback can be at most 2,000 characters.']);
            }

            $total = (float) $homework->total_marks;
            $percent = $total > 0 ? round($marks['obtained'] / $total * 100, 2) : null;
            $now = HomeworkTime::now();

            $values = [
                'submission_id' => $row->latest_submission_id,
                'assignment_student_id' => $row->id,
                'mode' => $homework->assessment_mode,
                'obtained_marks' => $marks['obtained'],
                'total_marks' => $total,
                'score_percent' => $percent,
                'is_passed' => $homework->passing_marks === null ? null : ($marks['obtained'] >= (float) $homework->passing_marks ? 1 : 0),
                'feedback_text' => $feedback,
                'assessed_by' => $caller->userId,
                'assessed_at' => $now,
            ];

            $assessmentId = $db->table('hw_assessments')->where('submission_id', $row->latest_submission_id)->value('id');

            if ($assessmentId) {
                $db->table('hw_assessments')->where('id', $assessmentId)->update($values);
            } else {
                $assessmentId = $db->table('hw_assessments')->insertGetId($values);
            }

            $db->table('hw_assessment_rubric_scores')->where('assessment_id', $assessmentId)->delete();
            $db->table('hw_assessment_feedback_tags')->where('assessment_id', $assessmentId)->delete();

            foreach ($marks['scores'] as $criteriaId => $obtained) {
                $db->table('hw_assessment_rubric_scores')->insert(['assessment_id' => $assessmentId, 'criteria_id' => $criteriaId, 'obtained_marks' => $obtained]);
            }

            foreach ($tagIds as $tagId) {
                $db->table('hw_assessment_feedback_tags')->insert(['assessment_id' => $assessmentId, 'feedback_tag_id' => $tagId]);
            }

            $db->table('hw_submissions')->where('id', $row->latest_submission_id)->update(['status' => 'checked']);
            $db->table('hw_assignment_students')->where('id', $row->id)->update([
                'status' => 'checked',
                'obtained_marks' => $marks['obtained'],
                'score_percent' => $percent,
                'checked_at' => $now,
            ]);

            $db->table('hw_notification_events')->insert([
                'event_type' => 'assessed',
                'assignment_id' => $homework->id,
                'assignment_student_id' => $row->id,
                'payload' => json_encode(['obtained_marks' => $this->reader->number($marks['obtained']), 'total_marks' => $this->reader->number($total), 'version_no' => (int) $row->version_count]),
                'occurred_at' => $now,
            ]);
        });
    }

    public function requestResubmission(HomeworkCaller $caller, object $homework, int $studentId, array $input): void
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        $newDueAt = null;
        $errors = [];

        if ($reason === '') {
            $errors['reason'] = 'Enter a reason.';
        } elseif (mb_strlen($reason) > 1000) {
            $errors['reason'] = 'The reason can be at most 1,000 characters.';
        }

        if (! blank($input['new_due_at'] ?? null)) {
            if (! $this->isDateTime($input['new_due_at'])) {
                $errors['new_due_at'] = 'Use a UTC date-time such as 2026-09-21T04:00:00Z.';
            } else {
                $newDueAt = HomeworkTime::fromApi($input['new_due_at']);

                if (HomeworkTime::isPast($newDueAt)) {
                    $errors['new_due_at'] = 'The new due time must be in the future.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if ($homework->status === 'closed') {
            throw new ApiException('This homework is closed');
        }

        $db = $this->schools->connectForHomework($caller->dbName);

        $db->transaction(function () use ($db, $caller, $homework, $studentId, $reason, $newDueAt) {
            $row = $this->handedInRow($db, $homework->id, $studentId);

            if ($db->table('hw_resubmission_requests')->where('assignment_student_id', $row->id)->where('status', 'open')->exists()) {
                throw new ApiException('A resubmission request is already open');
            }

            if ($homework->max_versions && $row->version_count >= $homework->max_versions) {
                throw new ApiException('The maximum number of versions has been reached');
            }

            $now = HomeworkTime::now();

            $db->table('hw_resubmission_requests')->insert([
                'assignment_student_id' => $row->id,
                'submission_id' => $row->latest_submission_id,
                'reason' => $reason,
                'new_due_at' => $newDueAt,
                'requested_by' => $caller->userId,
                'requested_at' => $now,
                'status' => 'open',
            ]);

            $db->table('hw_submissions')->where('id', $row->latest_submission_id)->update(['status' => 'returned']);
            $db->table('hw_assignment_students')->where('id', $row->id)->update(['status' => 'resubmission_requested']);

            $db->table('hw_notification_events')->insert([
                'event_type' => 'resubmission_requested',
                'assignment_id' => $homework->id,
                'assignment_student_id' => $row->id,
                'payload' => json_encode(['reason' => $reason, 'new_due_at' => $newDueAt], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now,
            ]);
        });
    }

    private function handedInRow($db, int $homeworkId, int $studentId): object
    {
        $row = $db->table('hw_assignment_students')->where('assignment_id', $homeworkId)->where('student_id', $studentId)->lockForUpdate()->first()
            ?? throw new ApiException('Student not found on this homework', 404);

        if (! $row->latest_submission_id) {
            throw new ApiException('Nothing has been handed in yet');
        }

        return $row;
    }

    /**
     * @return array{obtained: float, scores: array<int, float>}
     */
    private function marks($db, object $homework, array $input): array
    {
        if ($homework->assessment_mode !== 'rubric') {
            $obtained = $input['obtained_marks'] ?? null;

            if (! is_numeric($obtained) || $obtained < 0 || $obtained > $homework->total_marks) {
                throw ValidationException::withMessages(['obtained_marks' => 'Enter marks from 0 to '.$this->reader->number($homework->total_marks).'.']);
            }

            return ['obtained' => (float) $obtained, 'scores' => []];
        }

        $criteria = $db->table('hw_assignment_rubric_criteria')->where('assignment_id', $homework->id)->get();
        $byName = $criteria->keyBy(fn ($row) => $this->normalize($row->criteria_name));
        $scores = [];
        $errors = [];

        foreach ((array) ($input['criteria'] ?? []) as $index => $mark) {
            $criterion = $byName[$this->normalize((string) ($mark['name'] ?? ''))] ?? null;
            $obtained = $mark['obtained'] ?? null;

            if (! $criterion) {
                $errors["criteria.$index.name"] = 'This homework has no such criterion.';
            } elseif (! is_numeric($obtained) || $obtained < 0 || $obtained > $criterion->max_marks) {
                $errors["criteria.$index.obtained"] = 'Enter marks from 0 to '.$this->reader->number($criterion->max_marks).'.';
            } else {
                $scores[$criterion->id] = (float) $obtained;
            }
        }

        if (! $errors && count($scores) !== $criteria->count()) {
            $errors['criteria'] = 'Every criterion needs a mark.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['obtained' => array_sum($scores), 'scores' => $scores];
    }

    private function tagIds($db, mixed $tags): array
    {
        $tags = is_array($tags) ? $tags : array_filter(explode(',', (string) $tags));
        $known = $db->table('hw_feedback_tags')->where('status', 1)->get(['id', 'tag_name'])
            ->keyBy(fn ($row) => $this->reader->slug($row->tag_name));
        $ids = [];

        foreach ($tags as $tag) {
            $row = $known[$tag] ?? null;

            if (! $row) {
                throw ValidationException::withMessages(['tags' => "Unknown tag: $tag."]);
            }

            $ids[$row->id] = $row->id;
        }

        return array_values($ids);
    }

    private function normalize(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', '', $name));
    }

    private function isDateTime(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }
}
