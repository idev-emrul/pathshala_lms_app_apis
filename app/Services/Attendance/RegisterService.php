<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 10.6 The class teacher submits the register. Each mark is written into the day column of
 * attendance_info, as the web register does; a month that was not set up yet gets its row, as the
 * web month setup creates it. Every submission is kept in app_attendance_register_log (who, when,
 * from the app). A later submission for the same day is a correction with its own key.
 *
 * No SMS is sent yet. A day already on leave stays leave.
 */
class RegisterService
{
    // The statuses a teacher may submit.
    public const MARKABLE = ['present' => 1, 'absent' => 2, 'fugitive' => 5];

    public function __construct(
        private SchoolDbService $schools,
        private AttendanceReader $reader,
    ) {}

    /**
     * Returns false when the Idempotency-Key was already used: the register is not written again.
     */
    public function submit(AttendanceCaller $caller, object $section, string $date, array $marks, ?string $key, ?string $ip): bool
    {
        $students = collect($this->reader->sectionStudents($caller->db, $section))->keyBy(fn ($s) => (string) $s->current_status_info_id);

        $errors = [];
        $valid = [];
        foreach ($marks as $i => $mark) {
            $studentId = is_array($mark) && isset($mark['student_id']) && is_scalar($mark['student_id']) ? (string) $mark['student_id'] : '';
            $status = is_array($mark) && isset($mark['status']) && is_string($mark['status']) ? $mark['status'] : '';

            if (! $students->has($studentId)) {
                $errors["marks.$i.student_id"] = 'This student is not in your class.';
            } elseif (! isset(self::MARKABLE[$status])) {
                $errors["marks.$i.status"] = 'Use present, absent or fugitive.';
            } else {
                $valid[$studentId] = ['student' => $students[$studentId], 'code' => self::MARKABLE[$status]];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $db = $this->schools->connectForAttendance($caller->dbName);

        if ($key && $db->table('app_attendance_register_log')->where('idempotency_key', $key)->exists()) {
            return false;
        }

        // Approved leave is not overwritten.
        $current = $this->reader->codesForDate($caller->db, array_map(fn ($mark) => (int) $mark['student']->current_status_info_id, $valid), $date);
        $valid = array_filter($valid, fn ($mark) => ($current[(int) $mark['student']->current_status_info_id] ?? 0) !== 3);

        $day = Carbon::parse($date);
        $column = 'd'.$day->day;
        $now = HomeworkTime::now();
        $userId = (int) $caller->userId;

        try {
            $db->transaction(function () use ($db, $valid, $day, $column, $now, $userId, $section, $date, $marks, $key, $ip, $caller) {
                foreach ($valid as $mark) {
                    $student = $mark['student'];
                    $row = $db->table('attendance_info')
                        ->where('attendance_student_id', $student->current_status_info_id)
                        ->where('attendance_month', $day->month)
                        ->where('attendance_year', $day->year)
                        ->orderBy('attendance_id')
                        ->first(['attendance_id']);

                    if ($row) {
                        $db->table('attendance_info')
                            ->where('attendance_id', $row->attendance_id)
                            ->where('attendance_student_id', $student->current_status_info_id)
                            ->update([$column => $mark['code'], 'attendance_modifier' => $userId, 'attendance_DOM' => $now]);
                    } else {
                        $insert = [
                            'attendance_month' => $day->month,
                            'attendance_year' => $day->year,
                            'attendance_class' => $student->class_code,
                            'attendance_section' => $student->section_code,
                            'attendance_student_id' => $student->current_status_info_id,
                            'attendance_creator' => $userId,
                            'attendance_modifier' => $userId,
                            'attendance_DOM' => $now,
                        ];
                        for ($d = 1; $d <= 31; $d++) {
                            $insert['d'.$d] = 0;
                        }
                        $insert[$column] = $mark['code'];

                        $db->table('attendance_info')->insert($insert);
                    }
                }

                $db->table('app_attendance_register_log')->insert([
                    'section_id' => $section->section_id,
                    'session_code' => $section->session_code,
                    'class_code' => $section->class_code,
                    'section_code' => $section->section_code,
                    'group_code' => $section->group_code,
                    'shift_code' => $section->shift_code,
                    'attendance_date' => $date,
                    'teacher_code' => (int) $caller->teacherCode,
                    'marks' => json_encode($marks, JSON_UNESCAPED_UNICODE),
                    'idempotency_key' => $key,
                    'created_ip' => $ip,
                    'created_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two retries arrived at the same moment; the first one wrote the register.
            return false;
        }

        // TODO: absent / present / fugitive SMS to guardians, as the web register does.

        return true;
    }

    /**
     * The teacher's class-teacher section: the one named by $sectionId, or the first one. 403 when the
     * teacher is not a class teacher, or the section is not theirs.
     */
    public function section(AttendanceCaller $caller, ?string $sectionId): object
    {
        $sections = $this->reader->classTeacherSections($caller->db, $caller->teacherCode);

        if (! $sections) {
            throw new ApiException('You are not a class teacher this session', 403);
        }

        foreach ($sections as $section) {
            if (! $sectionId || $sectionId === $section->section_id) {
                return $section;
            }
        }

        throw new ApiException('This is not your class', 403);
    }
}
