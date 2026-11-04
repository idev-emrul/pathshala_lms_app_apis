<?php

namespace App\Services\ClassRoutine;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Read queries and the JSON shapes of the class routine API.
 *
 * clr_routines is a weekly timetable. A section / group / shift / medium of 0 means "all".
 * There are no per-date changes, so status and status_reason are always null, and there are no breaks.
 */
class RoutineReader
{
    /**
     * A student's periods for one date. Empty on a weekly off day or a holiday.
     */
    public function studentPeriods(Connection $db, object $placement, string $date): array
    {
        $dayId = $this->schoolDayId($db, $date);

        if (! $dayId || $this->isHoliday($db, $date, (int) $placement->class_code)) {
            return [];
        }

        $rows = $db->table('clr_routines as r')
            ->join('clr_periods as p', function ($join) {
                $join->on('p.period_id', '=', 'r.period_id')->where('p.status', 1);
            })
            ->leftJoin('subject_group as sg', 'sg.subject_group_id', '=', 'r.subject_group_code')
            ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'r.teacher_code')
            ->leftJoin('clr_class_rooms as room', 'room.class_room_id', '=', 'r.class_room_id')
            ->where('r.status', 1)
            ->where('r.day_id', $dayId)
            ->where('r.session_code', $placement->session_code)
            ->where('r.class_code', $placement->class_code)
            ->whereIn('r.section_code', [(int) $placement->section_code, 0])
            ->whereIn('r.group_code', [(int) $placement->group_code, 0])
            ->whereIn('r.shift_code', [(int) $placement->shift_code, 0])
            ->whereIn('r.institute_medium_id', [(int) $placement->institute_medium_id, 0])
            ->orderBy('p.start_time')
            ->orderBy('p.period_id')
            ->get(['p.period_id', 'p.start_time', 'p.end_time', 'sg.subject_group_name as title', 't.teacher_name', 'room.class_room_name as room']);

        return $rows->map(fn ($row) => $this->period($row) + [
            'teacher_name' => $row->teacher_name,
            'room' => $row->room,
            'status' => null,
            'status_reason' => null,
        ])->all();
    }

    /**
     * A teacher's own periods for one date, in the running session(s). Empty on a weekly off day or a
     * school-wide holiday.
     */
    public function teacherPeriods(Connection $db, ?int $teacherCode, string $date): array
    {
        $dayId = $this->schoolDayId($db, $date);

        if (! $teacherCode || ! $dayId || $this->isHoliday($db, $date)) {
            return [];
        }

        $rows = $db->table('clr_routines as r')
            ->join('clr_periods as p', function ($join) {
                $join->on('p.period_id', '=', 'r.period_id')->where('p.status', 1);
            })
            ->join('session_info as session', function ($join) {
                $join->on('session.session_code', '=', 'r.session_code')->where('session.running_session', 1);
            })
            ->leftJoin('subject_group as sg', 'sg.subject_group_id', '=', 'r.subject_group_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'r.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'r.section_code')
            ->leftJoin('clr_class_rooms as room', 'room.class_room_id', '=', 'r.class_room_id')
            ->where('r.status', 1)
            ->where('r.day_id', $dayId)
            ->where('r.teacher_code', $teacherCode)
            ->orderBy('p.start_time')
            ->orderBy('p.period_id')
            ->get(['p.period_id', 'p.start_time', 'p.end_time', 'sg.subject_group_name as title', 'class.class_name as class_label', 'section.section_name as section', 'room.class_room_name as room']);

        return $rows->map(fn ($row) => $this->period($row) + [
            'class_label' => $row->class_label,
            'section' => $row->section,
            'room' => $row->room,
            'status' => null,
            'status_reason' => null,
        ])->all();
    }

    /**
     * Fields shared by student and teacher periods. A period whose end is not after its start has no duration.
     */
    private function period(object $row): array
    {
        $start = Carbon::parse($row->start_time);
        $end = Carbon::parse($row->end_time);

        return [
            'id' => (string) $row->period_id,
            'type' => 'lesson',
            'start_time' => $start->format('H:i'),
            'duration_minutes' => $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : null,
            'title' => $row->title,
        ];
    }

    /**
     * clr_days id of the date's weekday, or null when it is a weekly off day (status 0).
     */
    private function schoolDayId(Connection $db, string $date): ?int
    {
        $day = $db->table('clr_days')
            ->where('day_name', Carbon::parse($date)->format('l'))
            ->first(['day_id', 'status']);

        return $day && (int) $day->status === 1 ? (int) $day->day_id : null;
    }

    /**
     * An active "no attendance" calendar event on the date. Its student_class is "All", empty, or a list
     * of class codes or names. Without a class, only school-wide events count.
     */
    private function isHoliday(Connection $db, string $date, ?int $classCode = null): bool
    {
        $events = $db->table('academic_calendar_info')
            ->where('event_status', 1)
            ->where('event_attendance', 0)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->pluck('student_class');

        $className = $classCode !== null
            ? strtolower((string) $db->table('class_info')->where('class_code', $classCode)->value('class_name'))
            : null;

        foreach ($events as $classes) {
            $targets = array_map('trim', explode(',', strtolower((string) $classes)));

            if ($targets === [''] || in_array('all', $targets, true) || in_array('0', $targets, true)) {
                return true;
            }

            if ($classCode !== null && (in_array((string) $classCode, $targets, true) || in_array($className, $targets, true))) {
                return true;
            }
        }

        return false;
    }
}
