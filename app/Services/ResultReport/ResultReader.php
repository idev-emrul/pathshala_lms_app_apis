<?php

namespace App\Services\ResultReport;

use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;

/**
 * Read queries and the JSON shapes of the result API. Every figure is the web system's; nothing is
 * recalculated except percentages and section figures, which are not stored.
 *
 * - exam_overall_result: one row per student per exam (final_result = 0). A combined annual exam has
 *   a second row (final_result = 1, status = 3) with the combined figures; its subject marks are in
 *   combine_mark_info. Rows with status = 2 and final_result = 1 are copies of the normal row written
 *   by older code, and are ignored. Duplicate rows exist, so the latest id is used.
 * - Published = published_exam = 1, web_pub_date reached, and no marks edited after the merit list
 *   (examclass_status = 0).
 * - Position, class size and class average are for the student's section.
 */
class ResultReader
{
    // exam_info.exam_type values with no marks for the app: 10 admission test, 12 EVS evaluation.
    private const EXCLUDED_EXAM_TYPES = [10, 12];

    public function findRow(Connection $db, int $studentId): ?object
    {
        return $db->table('student_current_status_info')
            ->where('current_status_info_id', $studentId)
            ->where('active_status', '<>', 5)
            ->first();
    }

    /**
     * True when the teacher is a class or subject teacher of the row's class, in a running session.
     */
    public function teacherTeaches(Connection $db, ?int $teacherCode, object $row): bool
    {
        if (! $teacherCode) {
            return false;
        }

        foreach (['class_teacher_info', 'subject_teacher_info'] as $table) {
            $found = $db->table("$table as t")
                ->join('session_info as session', function ($join) {
                    $join->on('session.session_code', '=', 't.session_code')->where('session.running_session', 1);
                })
                ->where('t.teacher_code', $teacherCode)
                ->where('t.session_code', $row->session_code)
                ->where('t.class_code', $row->class_code)
                ->whereIn('t.section_code', [(int) $row->section_code, 0])
                ->whereIn('t.group_code', [(int) $row->group_code, 0])
                ->whereIn('t.shift_code', [(int) $row->shift_code, 0])
                ->exists();

            if ($found) {
                return true;
            }
        }

        return false;
    }

    // Student

    /**
     * 2.1 Every published exam of the student across all their years, with subjects. Oldest first.
     */
    public function studentResults(Connection $db, object $row): array
    {
        $enrolments = $db->table('student_current_status_info')
            ->where('student_registration_code', $row->student_registration_code)
            ->where('active_status', '<>', 5)
            ->pluck('current_status_info_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $in = implode(',', array_fill(0, count($enrolments), '?'));
        $excluded = implode(',', self::EXCLUDED_EXAM_TYPES);

        $rows = $db->select("
            SELECT eor.stud_class_id, eor.exam_class_id, eor.final_result, eor.overall_total_mark, eor.average_gpa,
                   eor.letter_grade, eor.merit_sectionwise, eci.custom_exam_name, eci.exam_total_mark,
                   ei.exam_name, epi.web_pub_date, scsi.section_code
            FROM exam_overall_result eor
            JOIN (SELECT MAX(exam_overall_result_id) AS id FROM exam_overall_result
                  WHERE stud_class_id IN ($in) GROUP BY stud_class_id, exam_class_id, final_result) latest
              ON latest.id = eor.exam_overall_result_id
            JOIN exam_class_info eci ON eci.exam_class_id = eor.exam_class_id
              AND eci.exam_class_active_status = 1 AND eci.examclass_status = 0
            JOIN exam_info ei ON ei.exam_id = eci.exam_id AND ei.exam_type NOT IN ($excluded)
            JOIN exam_publication_info epi ON epi.exam_class_id = eci.exam_class_id
              AND epi.published_exam = 1 AND epi.web_pub_date <= ?
            JOIN student_current_status_info scsi ON scsi.current_status_info_id = eor.stud_class_id
            WHERE (eor.final_result = 0 OR eor.status = 3)
            ORDER BY epi.web_pub_date, eor.exam_class_id, eor.final_result", [...$enrolments, HomeworkTime::today()]);

        $totalsMaps = [];
        $exams = [];

        foreach ($rows as $result) {
            $combined = (int) $result->final_result === 1;
            $key = $result->exam_class_id.'-'.(int) $combined;
            $totalsMaps[$key] ??= $this->totalMarksMap($db, (int) $result->exam_class_id, $combined);

            $total = $this->totalMarks($result->exam_total_mark, $totalsMaps[$key], (int) $result->stud_class_id);
            $section = $this->sectionResults($db, (int) $result->exam_class_id, (int) $result->final_result, (int) $result->section_code);

            $percentages = [];
            foreach ($section as $mate) {
                $mateTotal = $this->totalMarks($result->exam_total_mark, $totalsMaps[$key], (int) $mate->stud_class_id);
                if ($mateTotal) {
                    $percentages[] = $mate->overall_total_mark / $mateTotal * 100;
                }
            }

            $name = $result->custom_exam_name ?: $result->exam_name;

            $exams[] = [
                'id' => $combined ? $result->exam_class_id.'-combined' : (string) $result->exam_class_id,
                'name' => $combined ? $name.' (Combined)' : $name,
                'status' => 'published',
                'published_at' => $result->web_pub_date ? HomeworkTime::toApi($result->web_pub_date.' 00:00:00') : null,
                'total_marks' => $total,
                'obtained_marks' => $this->number($result->overall_total_mark),
                'percentage' => $total ? round($result->overall_total_mark / $total * 100, 2) : null,
                'gpa' => $this->gpa($result->average_gpa),
                'grade' => $result->letter_grade,
                'position' => $result->merit_sectionwise ? (int) $result->merit_sectionwise : null,
                'class_size' => count($section),
                'class_average' => $percentages ? round(array_sum($percentages) / count($percentages), 2) : null,
                'marksheet_url' => null,
                'subjects' => $this->subjectMarks($db, (int) $result->stud_class_id, (int) $result->exam_class_id, $combined),
            ];
        }

        return [
            'rank_visible' => true,
            'class_average_visible' => true,
            'transcript_url' => null,
            'exams' => $exams,
        ];
    }

    // Teacher

    /**
     * Exams of the classes the teacher is class teacher of, in the running session(s), oldest first.
     * With $examId, only that exam.
     */
    public function teacherExams(Connection $db, ?int $teacherCode, ?int $examId = null): array
    {
        if (! $teacherCode) {
            return [];
        }

        $excluded = implode(',', self::EXCLUDED_EXAM_TYPES);

        return $db->select("
            SELECT DISTINCT eci.exam_class_id, eci.custom_exam_name, eci.examclass_status, eci.exam_total_mark,
                   eci.session_code, eci.class_code, ei.exam_name, ci.class_name, epi.published_exam, epi.web_pub_date,
                   (SELECT COUNT(*) FROM exam_overall_result r WHERE r.exam_class_id = eci.exam_class_id) AS result_count
            FROM class_teacher_info cti
            JOIN session_info ss ON ss.session_code = cti.session_code AND ss.running_session = 1
            JOIN exam_class_info eci ON eci.class_code = cti.class_code AND eci.session_code = cti.session_code
              AND eci.exam_class_active_status = 1
              AND (cti.shift_code = 0 OR eci.shift_code IN (cti.shift_code, 0))
            JOIN exam_info ei ON ei.exam_id = eci.exam_id AND ei.exam_type NOT IN ($excluded)
            LEFT JOIN exam_publication_info epi ON epi.exam_class_id = eci.exam_class_id
            LEFT JOIN class_info ci ON ci.class_code = eci.class_code
            WHERE cti.teacher_code = ?".($examId !== null ? ' AND eci.exam_class_id = ?' : '').'
            ORDER BY eci.exam_class_id', array_values(array_filter([$teacherCode, $examId], fn ($v) => $v !== null)));
    }

    public function examExists(Connection $db, int $examId): bool
    {
        return $db->table('exam_class_info')->where('exam_class_id', $examId)->where('exam_class_active_status', 1)->exists();
    }

    /**
     * { id, name, status } of a teacher exam row. processing = marks edited after the merit list, or
     * no result made yet.
     */
    public function examSummary(object $exam): array
    {
        if ((int) $exam->examclass_status === 1 || (int) $exam->result_count === 0) {
            $status = 'processing';
        } elseif ((int) $exam->published_exam === 1 && $exam->web_pub_date && $exam->web_pub_date <= HomeworkTime::today()) {
            $status = 'published';
        } else {
            $status = 'pending';
        }

        return [
            'id' => (string) $exam->exam_class_id,
            'name' => $exam->custom_exam_name ?: $exam->exam_name,
            'status' => $status,
            'class_label' => $exam->class_name,
        ];
    }

    /**
     * 2.3 One row per student of the teacher's section(s) for an exam, with the latest result. Marks are
     * null while the result is not made.
     */
    public function examSheet(Connection $db, int $teacherCode, object $exam): array
    {
        $rows = $db->select('
            SELECT DISTINCT scsi.current_status_info_id, scsi.roll_number, scsi.section_code, si.student_name,
                   si.student_name_bn, e.overall_total_mark, e.average_gpa, e.letter_grade, e.merit_sectionwise, e.remarks_note
            FROM exam_class_info eci
            JOIN class_teacher_info cti ON cti.teacher_code = ? AND cti.class_code = eci.class_code AND cti.session_code = eci.session_code
            JOIN student_current_status_info scsi ON scsi.session_code = eci.session_code AND scsi.class_code = eci.class_code
              AND (cti.section_code = 0 OR scsi.section_code = cti.section_code)
              AND (cti.group_code = 0 OR scsi.group_code = cti.group_code)
              AND (cti.shift_code = 0 OR scsi.shift_code = cti.shift_code)
              AND scsi.active_status <> 5
            JOIN student_info si ON si.student_registration_code = scsi.student_registration_code
            LEFT JOIN exam_overall_result e ON e.exam_overall_result_id = (
              SELECT MAX(x.exam_overall_result_id) FROM exam_overall_result x
              WHERE x.stud_class_id = scsi.current_status_info_id AND x.exam_class_id = eci.exam_class_id AND x.final_result = 0)
            WHERE eci.exam_class_id = ?
            ORDER BY scsi.section_code, scsi.roll_number', [$teacherCode, $exam->exam_class_id]);

        $totals = $this->totalMarksMap($db, (int) $exam->exam_class_id, false);
        $percentages = [];
        $students = [];

        foreach ($rows as $row) {
            $total = $this->totalMarks($exam->exam_total_mark, $totals, (int) $row->current_status_info_id);
            $made = $row->overall_total_mark !== null;

            if ($made && $total) {
                $percentages[] = $row->overall_total_mark / $total * 100;
            }

            $students[] = [
                'student_id' => (string) $row->current_status_info_id,
                'student_name' => $row->student_name ?: $row->student_name_bn,
                'roll' => (string) $row->roll_number,
                'obtained_marks' => $made ? $this->number($row->overall_total_mark) : null,
                'total_marks' => $made ? $total : null,
                'gpa' => $made ? $this->gpa($row->average_gpa) : null,
                'grade' => $made ? $row->letter_grade : null,
                'position' => $made && $row->merit_sectionwise ? (int) $row->merit_sectionwise : null,
                // The web system stores the comment with addslashes.
                'comment' => ($row->remarks_note ?? '') !== '' ? stripslashes($row->remarks_note) : null,
            ];
        }

        return [
            'exam' => $this->examSummary($exam),
            'class_average' => $percentages ? round(array_sum($percentages) / count($percentages), 2) : null,
            'comments_enabled' => (int) $exam->result_count > 0,
            'rows' => $students,
        ];
    }

    /**
     * The student row, if it is in the exam's session and class and the teacher is its class teacher.
     */
    public function teacherStudentRow(Connection $db, int $teacherCode, object $exam, int $studentId): ?object
    {
        return $db->table('student_current_status_info as s')
            ->join('class_teacher_info as cti', function ($join) use ($teacherCode) {
                $join->on('cti.class_code', '=', 's.class_code')
                    ->on('cti.session_code', '=', 's.session_code')
                    ->where('cti.teacher_code', $teacherCode)
                    ->where(fn ($q) => $q->where('cti.section_code', 0)->orWhereColumn('s.section_code', 'cti.section_code'))
                    ->where(fn ($q) => $q->where('cti.group_code', 0)->orWhereColumn('s.group_code', 'cti.group_code'))
                    ->where(fn ($q) => $q->where('cti.shift_code', 0)->orWhereColumn('s.shift_code', 'cti.shift_code'));
            })
            ->where('s.current_status_info_id', $studentId)
            ->where('s.session_code', $exam->session_code)
            ->where('s.class_code', $exam->class_code)
            ->where('s.active_status', '<>', 5)
            ->first(['s.current_status_info_id']);
    }

    public function hasResult(Connection $db, int $studentId, int $examId): bool
    {
        return $db->table('exam_overall_result')->where('stud_class_id', $studentId)->where('exam_class_id', $examId)->exists();
    }

    // Shared

    private function subjectMarks(Connection $db, int $studentId, int $examId, bool $combined): array
    {
        return $db->table(($combined ? 'combine_mark_info' : 'subject_mark_info').' as m')
            ->join('exam_subject_info as esi', function ($join) use ($examId) {
                $join->on('esi.exam_subject_id', '=', 'm.exam_subject_id')->where('esi.exam_class_id', $examId);
            })
            ->leftJoin('subject_group as sg', 'sg.subject_group_id', '=', 'esi.subject_group_code')
            ->where('m.stud_class_id', $studentId)
            ->orderBy('sg.subject_group_position')
            ->orderBy('esi.exam_subject_id')
            ->get(['sg.subject_group_name', 'esi.total_marks', 'm.exact_total_mark', 'm.letter_grade'])
            ->map(fn ($row) => [
                'name' => $row->subject_group_name,
                'total_marks' => $this->number($row->total_marks),
                'obtained_marks' => $this->number($row->exact_total_mark),
                'grade' => $row->letter_grade,
            ])
            ->all();
    }

    /**
     * Full marks per student for one exam: the sum of the student's counted subjects (no_add_table = 0).
     */
    private function totalMarksMap(Connection $db, int $examId, bool $combined): array
    {
        return $db->table(($combined ? 'combine_mark_info' : 'subject_mark_info').' as m')
            ->join('exam_subject_info as esi', function ($join) use ($examId) {
                $join->on('esi.exam_subject_id', '=', 'm.exam_subject_id')->where('esi.exam_class_id', $examId)->where('esi.no_add_table', 0);
            })
            ->groupBy('m.stud_class_id')
            ->selectRaw('m.stud_class_id, SUM(esi.total_marks) AS total')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->stud_class_id => (float) $row->total])
            ->all();
    }

    /**
     * The latest overall mark of every student of one section in one exam.
     */
    private function sectionResults(Connection $db, int $examId, int $finalResult, int $sectionCode): array
    {
        return $db->select('
            SELECT e.stud_class_id, e.overall_total_mark
            FROM exam_overall_result e
            JOIN (SELECT MAX(exam_overall_result_id) AS id FROM exam_overall_result
                  WHERE exam_class_id = ? AND final_result = ? AND (final_result = 0 OR status = 3)
                  GROUP BY stud_class_id) latest ON latest.id = e.exam_overall_result_id
            JOIN student_current_status_info s ON s.current_status_info_id = e.stud_class_id
            WHERE s.section_code = ?', [$examId, $finalResult, $sectionCode]);
    }

    /**
     * Full marks: the exam's configured total, else the student's counted subjects.
     */
    private function totalMarks(mixed $examTotal, array $totalsMap, int $studentId): int|float|null
    {
        if ((int) $examTotal > 0) {
            return (int) $examTotal;
        }

        return isset($totalsMap[$studentId]) ? $this->number($totalsMap[$studentId]) : null;
    }

    // 5.33 is the web system's "golden A+" marker; the GPA itself is at most 5.
    private function gpa(mixed $gpa): int|float|null
    {
        return $gpa === null ? null : $this->number(min(5, round((float) $gpa, 2)));
    }

    // Whole numbers as integers, others as floats.
    private function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        return $value == (int) $value ? (int) $value : round($value, 2);
    }
}
