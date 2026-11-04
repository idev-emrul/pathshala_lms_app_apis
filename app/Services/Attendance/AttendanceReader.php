<?php

namespace App\Services\Attendance;

use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Read queries and the JSON shapes of the attendance API.
 *
 * attendance_info: one row per enrolment (attendance_student_id = current_status_info_id) per month,
 * day columns d1..d31: 0 not marked, 1 present, 2 absent, 3 leave, 4 holiday, 5 fugitive.
 * Holidays and weekly off days come from academic_calendar_info and clr_days, as on the web profile.
 *
 * Statuses are only those the school records: present, absent, leave, holiday, fugitive, and pending
 * (a working day not marked yet). There is no "late". Counts and percentages follow the web
 * profile's attendance search: pending = working days - (present + absent), never below 0, and each
 * percentage is its count / (present + absent + leave + fugitive + pending).
 */
class AttendanceReader
{
    public const CODES = [1 => 'present', 2 => 'absent', 3 => 'leave', 4 => 'holiday', 5 => 'fugitive'];

    public function findRow(Connection $db, int $studentId): ?object
    {
        return $db->table('student_current_status_info')
            ->where('current_status_info_id', $studentId)
            ->where('active_status', '<>', 5)
            ->first();
    }

    /**
     * The student's current enrolment (latest active, else latest transfer or inactive) and every
     * enrolment of the student, oldest first.
     */
    public function student(Connection $db, object $row): array
    {
        $current = $db->table('student_current_status_info as s')
            ->join('student_info as si', 'si.student_registration_code', '=', 's.student_registration_code')
            ->leftJoin('session_info as session', 'session.session_code', '=', 's.session_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 's.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 's.section_code')
            ->where('s.student_registration_code', $row->student_registration_code)
            ->whereIn('s.active_status', [1, 2, 3])
            ->orderByRaw('s.active_status = 1 DESC')
            ->orderByDesc('s.session_code')
            ->orderByDesc('s.current_status_info_id')
            ->first(['s.current_status_info_id', 's.session_code', 'si.student_name', 'si.student_name_bn', 'session.session_name', 'session.session_year', 'class.class_name', 'section.section_name']);

        $enrolments = $db->table('student_current_status_info as s')
            ->leftJoin('session_info as session', 'session.session_code', '=', 's.session_code')
            ->where('s.student_registration_code', $row->student_registration_code)
            ->where('s.active_status', '<>', 5)
            ->orderBy('s.session_code')
            ->orderBy('s.current_status_info_id')
            ->get(['s.current_status_info_id', 's.session_code', 's.class_code', 's.section_code', 's.group_code', 's.shift_code', 'session.session_name'])
            ->all();

        return [$current ?? $row, $enrolments];
    }

    /**
     * 10.1 The attendance page for a duration: today, counts, percentages, recent days.
     */
    public function report(Connection $db, object $current, array $enrolments, string $from, string $to): array
    {
        $today = HomeworkTime::today();
        $until = min($to, $today);
        $days = $from <= $until ? $this->days($db, $enrolments, $from, $until) : [];
        $stats = $this->stats($days);

        $recent = [];
        foreach (array_reverse($days) as $day) {
            if ($day['code'] !== 0) {
                $recent[] = $day['object'];
            }
            if (count($recent) === 10) {
                break;
            }
        }

        $todayDay = $this->days($db, $enrolments, $today, $today)[0]['object'] ?? null;

        $years = [];
        foreach (array_reverse($enrolments) as $enrolment) {
            if ($enrolment->session_name && ! in_array($enrolment->session_name, $years, true)) {
                $years[] = $enrolment->session_name;
            }
        }

        return [
            'academic_year' => $current->session_name ?? null,
            'term_id' => null,
            'years' => $years,
            'terms' => [],
            'from' => $from,
            'to' => $to,
            'student_name' => ($current->student_name ?? null) ?: ($current->student_name_bn ?? null),
            'class_label' => $this->classLabel($current->class_name ?? null, $current->section_name ?? null),
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'today' => $todayDay ? [
                'date' => $today,
                'status' => $todayDay['status'],
                'check_in' => null,
                'updated_at' => $todayDay['marked_at'],
            ] : null,
            'working_days' => $stats['working_days'],
            'counts' => $stats['counts'],
            'percentages' => $stats['percentages'],
            'rate' => $stats['percentages']['present'],
            'recent' => $recent,
        ];
    }

    /**
     * 10.2 Every day of one month, up to today.
     */
    public function month(Connection $db, array $enrolments, int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $until = min(Carbon::parse($from)->endOfMonth()->toDateString(), HomeworkTime::today());

        return [
            'year' => $year,
            'month' => $month,
            'days' => $from <= $until ? array_column($this->days($db, $enrolments, $from, $until), 'object') : [],
        ];
    }

    /**
     * 10.3 The current session: percentage, counts, and one chart point per month with marked days.
     */
    public function summary(Connection $db, object $current, array $enrolments): array
    {
        $from = $this->sessionStart($current);
        $until = min($this->sessionEnd($current), HomeworkTime::today());
        $days = $from <= $until ? $this->days($db, $enrolments, $from, $until) : [];
        $stats = $this->stats($days);

        $monthly = [];
        foreach (collect($days)->groupBy(fn ($day) => substr($day['object']['date'], 0, 7)) as $key => $monthDays) {
            $monthStats = $this->stats($monthDays->all());

            if ($monthStats['marked'] > 0) {
                $monthly[] = ['month' => (int) substr($key, 5, 2), 'percentage' => $monthStats['percentages']['present']];
            }
        }

        return ['percentage' => $stats['percentages']['present'], 'counts' => $stats['counts'], 'monthly' => $monthly];
    }

    public function sessionStart(object $current): string
    {
        $year = ($current->session_year ?? null) ? (int) $current->session_year : (int) substr((string) ($current->session_name ?? ''), 0, 4);

        return ($year ?: (int) HomeworkTime::today()).'-01-01';
    }

    // A "2026-2027" session ends in its second year.
    private function sessionEnd(object $current): string
    {
        $start = (int) substr($this->sessionStart($current), 0, 4);

        return ($start + (preg_match('/^\d{4}\s*-\s*\d{4}$/', (string) ($current->session_name ?? '')) ? 1 : 0)).'-12-31';
    }

    // Class register

    /**
     * The teacher's class-teacher assignments in the running session(s), each with its register id
     * "session-class-section-group-shift".
     */
    public function classTeacherSections(Connection $db, ?int $teacherCode): array
    {
        if (! $teacherCode) {
            return [];
        }

        return $db->table('class_teacher_info as cti')
            ->join('session_info as session', function ($join) {
                $join->on('session.session_code', '=', 'cti.session_code')->where('session.running_session', 1);
            })
            ->leftJoin('class_info as class', 'class.class_code', '=', 'cti.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'cti.section_code')
            ->where('cti.teacher_code', $teacherCode)
            ->orderBy('cti.class_code')
            ->orderBy('cti.section_code')
            ->get(['cti.session_code', 'cti.class_code', 'cti.section_code', 'cti.group_code', 'cti.shift_code', 'class.class_name', 'section.section_name'])
            ->map(function ($section) {
                $section->section_id = implode('-', array_map('intval', [$section->session_code, $section->class_code, $section->section_code, $section->group_code, $section->shift_code]));

                return $section;
            })
            ->all();
    }

    /**
     * Active students of a class-teacher assignment (0 = all sections / groups / shifts), in roll order.
     */
    public function sectionStudents(Connection $db, object $section): array
    {
        return $db->table('student_current_status_info as s')
            ->join('student_info as si', 'si.student_registration_code', '=', 's.student_registration_code')
            ->where('s.session_code', $section->session_code)
            ->where('s.class_code', $section->class_code)
            ->when((int) $section->section_code, fn ($q) => $q->where('s.section_code', $section->section_code))
            ->when((int) $section->group_code, fn ($q) => $q->where('s.group_code', $section->group_code))
            ->when((int) $section->shift_code, fn ($q) => $q->where('s.shift_code', $section->shift_code))
            ->where('s.active_status', 1)
            ->orderBy('s.roll_number')
            ->orderBy('s.current_status_info_id')
            ->get(['s.current_status_info_id', 's.roll_number', 's.class_code', 's.section_code', 'si.student_name', 'si.student_name_bn'])
            ->all();
    }

    /**
     * 10.5 The register of one section for one date.
     */
    public function register(Connection $db, object $section, string $date): array
    {
        $students = $this->sectionStudents($db, $section);
        $codes = $this->codesForDate($db, array_map(fn ($s) => (int) $s->current_status_info_id, $students), $date);

        $submittedAt = $db->table('app_attendance_register_log')
            ->where('section_id', $section->section_id)
            ->where('attendance_date', $date)
            ->orderByDesc('id')
            ->value('created_at');

        return [
            'section_id' => $section->section_id,
            'date' => $date,
            'class_label' => $this->classLabel($section->class_name, $section->section_name),
            'marking_enabled' => $date <= HomeworkTime::today(),
            'submitted_at' => HomeworkTime::toApi($submittedAt),
            'students' => array_map(fn ($student) => [
                'student_id' => (string) $student->current_status_info_id,
                'name' => $student->student_name ?: $student->student_name_bn,
                'roll' => (string) $student->roll_number,
                'status' => self::CODES[$codes[(int) $student->current_status_info_id] ?? 0] ?? null,
                'leave_reason' => null,
            ], $students),
        ];
    }

    /**
     * Day code of each enrolment for one date, as current_status_info_id => code.
     */
    public function codesForDate(Connection $db, array $enrolmentIds, string $date): array
    {
        if (! $enrolmentIds) {
            return [];
        }

        $day = Carbon::parse($date);
        $column = 'd'.$day->day;

        return $db->table('attendance_info')
            ->whereIn('attendance_student_id', $enrolmentIds)
            ->where('attendance_month', $day->month)
            ->where('attendance_year', $day->year)
            ->orderBy('attendance_id')
            ->pluck($column, 'attendance_student_id')
            ->map(fn ($code) => (int) $code)
            ->all();
    }

    // Days

    /**
     * Every day between two dates: code, holiday, and the contract's day object.
     */
    private function days(Connection $db, array $enrolments, string $from, string $until): array
    {
        $codes = $this->dayCodes($db, array_map(fn ($e) => (int) $e->current_status_info_id, $enrolments), $from, $until);
        $holidays = $this->holidayDates($db, $from, $until);
        $logs = $this->registerLogs($db, $enrolments, $from, $until);
        $days = [];

        for ($date = Carbon::parse($from); $date->toDateString() <= $until; $date->addDay()) {
            $key = $date->toDateString();
            $code = $codes[$key] ?? 0;
            $holiday = isset($holidays[$key]);
            $status = $code !== 0 ? (self::CODES[$code] ?? 'pending') : ($holiday ? 'holiday' : 'pending');
            $log = $code !== 0 ? ($logs[$key] ?? null) : null;

            $days[] = [
                'code' => $code,
                'holiday' => $holiday,
                'status' => $status,
                'marked_at' => $log ? HomeworkTime::toApi($log->created_at) : null,
                'object' => [
                    'date' => $key,
                    'status' => $status,
                    'check_in' => null,
                    'check_out' => null,
                    'marked_by' => $log?->teacher_name,
                    'marked_at' => $log ? HomeworkTime::toApi($log->created_at) : null,
                    'note' => null,
                ],
            ];
        }

        return $days;
    }

    /**
     * Counts and percentages for a list of days, as the web profile does.
     */
    private function stats(array $days): array
    {
        $counts = ['present' => 0, 'absent' => 0, 'leave' => 0, 'fugitive' => 0, 'holiday' => 0, 'pending' => 0];

        foreach ($days as $day) {
            if ($day['holiday']) {
                $counts['holiday']++;
            }

            $name = self::CODES[$day['code']] ?? null;
            if (in_array($name, ['present', 'absent', 'leave', 'fugitive'], true)) {
                $counts[$name]++;
            }
        }

        $working = count($days) - $counts['holiday'];
        $counts['pending'] = max(0, $working - ($counts['present'] + $counts['absent']));
        $total = $counts['present'] + $counts['absent'] + $counts['leave'] + $counts['fugitive'] + $counts['pending'];

        $percentages = [];
        foreach (['present', 'absent', 'leave', 'fugitive'] as $key) {
            $percentages[$key] = $total > 0 ? round($counts[$key] / $total * 100, 1) : 0;
        }

        return [
            'working_days' => $working,
            'counts' => $counts,
            'percentages' => $percentages,
            'marked' => $counts['present'] + $counts['absent'] + $counts['leave'] + $counts['fugitive'],
        ];
    }

    /**
     * Day codes of the enrolments between two dates, as 'Y-m-d' => code (only marked days).
     */
    private function dayCodes(Connection $db, array $enrolmentIds, string $from, string $until): array
    {
        if (! $enrolmentIds) {
            return [];
        }

        $fromKey = (int) substr($from, 0, 4) * 100 + (int) substr($from, 5, 2);
        $toKey = (int) substr($until, 0, 4) * 100 + (int) substr($until, 5, 2);

        $rows = $db->table('attendance_info')
            ->whereIn('attendance_student_id', $enrolmentIds)
            ->whereRaw('(attendance_year * 100 + attendance_month) BETWEEN ? AND ?', [$fromKey, $toKey])
            ->orderBy('attendance_student_id')
            ->orderBy('attendance_id')
            ->get();

        $codes = [];
        foreach ($rows as $row) {
            $daysInMonth = Carbon::create((int) $row->attendance_year, (int) $row->attendance_month)->daysInMonth;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $code = (int) $row->{'d'.$d};
                $date = sprintf('%04d-%02d-%02d', $row->attendance_year, $row->attendance_month, $d);

                if ($code !== 0 && $date >= $from && $date <= $until) {
                    $codes[$date] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Calendar holidays and weekly off days between two dates, as 'Y-m-d' => true
     * (the rule of the web system's get_working_days_info_for_range).
     */
    private function holidayDates(Connection $db, string $from, string $until): array
    {
        $dates = [];

        $events = $db->table('academic_calendar_info')
            ->where('event_status', 1)
            ->where('event_attendance', 0)
            ->where('start_date', '<=', $until)
            ->where('end_date', '>=', $from)
            ->get(['start_date', 'end_date']);

        foreach ($events as $event) {
            for ($day = Carbon::parse(max($event->start_date, $from)); $day->toDateString() <= min($event->end_date, $until); $day->addDay()) {
                $dates[$day->toDateString()] = true;
            }
        }

        $offDays = $db->table('clr_days')->where('status', 0)->pluck('day_name')->map(fn ($name) => strtolower(trim((string) $name)))->filter()->all();

        if ($offDays) {
            for ($day = Carbon::parse($from); $day->toDateString() <= $until; $day->addDay()) {
                if (in_array(strtolower($day->format('l')), $offDays, true)) {
                    $dates[$day->toDateString()] = true;
                }
            }
        }

        return $dates;
    }

    /**
     * Registers submitted from the app that cover these enrolments, as 'Y-m-d' => latest log row.
     */
    private function registerLogs(Connection $db, array $enrolments, string $from, string $until): array
    {
        $logs = [];

        foreach ($enrolments as $enrolment) {
            $rows = $db->table('app_attendance_register_log as l')
                ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'l.teacher_code')
                ->where('l.session_code', $enrolment->session_code)
                ->where('l.class_code', $enrolment->class_code)
                ->whereIn('l.section_code', [(int) $enrolment->section_code, 0])
                ->whereIn('l.group_code', [(int) $enrolment->group_code, 0])
                ->whereIn('l.shift_code', [(int) $enrolment->shift_code, 0])
                ->whereBetween('l.attendance_date', [$from, $until])
                ->orderBy('l.id')
                ->get(['l.attendance_date', 'l.created_at', 't.teacher_name']);

            foreach ($rows as $row) {
                $logs[$row->attendance_date] = $row;
            }
        }

        return $logs;
    }

    private function classLabel(?string $class, ?string $section): ?string
    {
        return $section ? $class.' - '.$section : $class;
    }
}
