<?php

namespace App\Services\Behaviour;

use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;

/**
 * Quick entries and recognitions, following the web system's bh_entry_model. The score and the
 * student's class are copied into the rows, so old reports do not change later.
 */
class BehaviourEntryService
{
    public function __construct(private BehaviourSupport $support) {}

    /**
     * @param  array<string, string>  $ratings  indicator => rating, already checked
     */
    public function saveEntry(HomeworkCaller $caller, object $student, array $ratings, string $comment, ?int $subjectId, string $observedOn, bool $supportRequired): int
    {
        $writer = $this->support->writer($caller);

        return $writer->transaction(function () use ($writer, $caller, $student, $ratings, $comment, $subjectId, $observedOn, $supportRequired) {
            $id = $writer->table('bh_observations')->insertGetId([
                'student_id' => $student->student_id,
                'session_id' => $student->session_code,
                'class_id' => $student->class_code,
                'section_id' => $student->section_code,
                'subject_id' => $subjectId,
                'teacher_id' => $caller->teacherCode,
                'observed_on' => $observedOn,
                'comment' => $comment !== '' ? $comment : null,
                'support_required' => $supportRequired ? 1 : 0,
                'is_deleted' => 0,
                'created_by' => $caller->userId,
                'created_at' => HomeworkTime::now(),
            ]);

            $rows = [];

            foreach ($ratings as $indicator => $rating) {
                $rows[] = ['observation_id' => $id, 'indicator' => $indicator, 'rating' => $rating, 'score' => BehaviourSupport::RATINGS[$rating]['score']];
            }

            $writer->table('bh_ratings')->insert($rows);

            if ($supportRequired) {
                $this->support->notifySupportRequired($writer, $student, $id);
            }

            return $id;
        });
    }

    public function saveRecognition(HomeworkCaller $caller, object $student, string $tag, string $note, string $givenOn): int
    {
        $writer = $this->support->writer($caller);

        return $writer->transaction(function () use ($writer, $caller, $student, $tag, $note, $givenOn) {
            $id = $writer->table('bh_recognitions')->insertGetId([
                'student_id' => $student->student_id,
                'session_id' => $student->session_code,
                'class_id' => $student->class_code,
                'section_id' => $student->section_code,
                'tag' => $tag,
                'note' => $note !== '' ? $note : null,
                'given_by' => $caller->teacherCode,
                'given_on' => $givenOn,
                'is_deleted' => 0,
                'created_by' => $caller->userId,
                'created_at' => HomeworkTime::now(),
            ]);

            $message = "Pathshala: well done! {$student->student_name} received the recognition \"{$this->support->tagName($caller->db, $tag)}\"".($note !== '' ? ": $note" : '.');
            $this->support->notify($writer, $id, 'positive_recognition', 'guardian', (int) $student->student_id, $student->contact_number, $message);
            $this->support->notify($writer, $id, 'positive_recognition', 'student', (int) $student->student_id, null, $message);

            return $id;
        });
    }
}
