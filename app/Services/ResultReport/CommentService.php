<?php

namespace App\Services\ResultReport;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Validation\ValidationException;

/**
 * 2.4 The class teacher's comment on a student's result. It is the comment the web system prints on
 * the transcript: exam_overall_result.remarks_note, saved the way the web comment screen saves it
 * (addslashes, on every row of the student's exam). Nothing else in the table is changed.
 */
class CommentService
{
    // remarks_note is varchar(300).
    private const MAX_LENGTH = 300;

    public function __construct(private SchoolDbService $schools) {}

    public function save(ResultCaller $caller, int $studentId, int $examId, string $comment): void
    {
        $stored = addslashes(trim($comment));

        if (mb_strlen($stored) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['comment' => 'The comment must be at most '.self::MAX_LENGTH.' characters.']);
        }

        $updated = $this->schools->connectForResult($caller->dbName)
            ->table('exam_overall_result')
            ->where('stud_class_id', $studentId)
            ->where('exam_class_id', $examId)
            ->update(['remarks_note' => $stored, 'overall_dom' => HomeworkTime::today()]);

        if ($updated === 0 && ! $caller->db->table('exam_overall_result')->where('stud_class_id', $studentId)->where('exam_class_id', $examId)->exists()) {
            throw new ApiException('Comments are not enabled for this exam yet', 403);
        }
    }
}
