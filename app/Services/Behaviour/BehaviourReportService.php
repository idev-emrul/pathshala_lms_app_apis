<?php

namespace App\Services\Behaviour;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * The student overview, the admin dashboard and the reports, following the web system's
 * bh_report_model. Percents are worked out live from bh_ratings.
 */
class BehaviourReportService
{
    private const FOLLOW_UP_DAYS = 3;

    private const LIST_LIMIT = 20;

    public function __construct(private BehaviourSupport $support) {}

    /**
     * Incidents are never part of this, because students and guardians do not see them.
     */
    public function overview(Connection $db, object $student): array
    {
        $studentId = (int) $student->student_id;
        $periods = $this->support->periods($db, $student->session_code);
        $index = $this->support->currentIndex($periods);
        $current = $periods[$index];
        $previous = $index > 0 ? $periods[$index - 1] : null;

        $now = $this->support->indicatorScores($db, $studentId, $current['from'], $current['to']);
        $before = $previous ? $this->support->indicatorScores($db, $studentId, $previous['from'], $previous['to']) : [];
        $overall = $this->support->overall($now);

        $indicators = array_map(fn (string $code) => [
            'type' => $code,
            'rating' => $this->support->ratingFor($now[$code]['percent'] ?? null),
            'percent' => $now[$code]['percent'] ?? null,
            'previous_percent' => $before[$code]['percent'] ?? null,
        ], $this->support->indicators($db));

        // Up to six periods ending with the current one, only those with ratings.
        $trend = [];

        for ($i = max(0, $index - 5); $i <= $index; $i++) {
            $percent = $i === $index ? $overall : $this->support->overall($this->support->indicatorScores($db, $studentId, $periods[$i]['from'], $periods[$i]['to']));

            if ($percent !== null) {
                $trend[] = ['label' => $periods[$i]['label'], 'percent' => $percent];
            }
        }

        return [
            'period' => $this->periodShape($current, false),
            'previous_period' => $previous ? $this->periodShape($previous, false) : null,
            'overall_percent' => $overall,
            'overall_rating' => $this->support->ratingFor($overall),
            'support_required' => $this->supportRequested($db, $studentId, $current['from']),
            'indicators' => $indicators,
            'recognitions' => $this->recognitions($db, $studentId),
            'trend' => $trend,
            'feedback' => $this->feedback($db, $studentId),
            'goals' => $db->table('bh_goals')->where('student_id', $studentId)
                ->orderByRaw('status = "active" DESC')
                ->orderByDesc('id')
                ->get()
                ->map(fn (object $goal) => ['id' => (string) $goal->id, 'title' => $goal->title, 'status' => $goal->status])
                ->all(),
        ];
    }

    public function dashboard(HomeworkCaller $caller, array $filters, array $current, ?array $previous): array
    {
        $db = $caller->db;

        $now = $this->overallsByClass($db, $filters, $current);
        $improved = 0;

        if ($previous) {
            $old = $this->overallsByClass($db, $filters, $previous)['students'];

            foreach ($now['students'] as $studentId => $overall) {
                if (isset($old[$studentId]) && $overall > $old[$studentId]) {
                    $improved++;
                }
            }
        }

        $observations = fn () => $this->filtered($db->table('bh_observations as o')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'o.student_id')
            ->where('o.is_deleted', 0), $filters, 'o');
        $recognitions = fn () => $this->filtered($db->table('bh_recognitions as rc')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'rc.student_id')
            ->where('rc.is_deleted', 0), $filters, 'rc');
        $incidents = fn () => $this->filtered($db->table('bh_incidents as i')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'i.student_id')
            ->where('i.is_deleted', 0), $filters, 'i');

        $inPeriod = [$current['from'], $current['to']];

        $resolved = $incidents()
            ->join('bh_incident_history as h', 'h.incident_id', '=', 'i.id')
            ->where('h.status', 'resolved')
            ->whereBetween('h.created_at', ["{$current['from']} 00:00:00", "{$current['to']} 23:59:59"])
            ->distinct()
            ->count('h.incident_id');

        return [
            'total_observations' => $observations()->whereBetween('o.observed_on', $inPeriod)->count(),
            'positive_recognition' => $recognitions()->whereBetween('rc.given_on', $inPeriod)->count(),
            'improvement_cases' => $improved,
            'support_required' => $observations()->whereBetween('o.observed_on', $inPeriod)->where('o.support_required', 1)->distinct()->count('o.student_id')
                + $incidents()->where('i.support_required', 1)->where('i.status', '<>', 'resolved')->count(),
            'under_review' => $incidents()->where('i.status', 'under_review')->count(),
            'resolved_cases' => $resolved,
            'class_summary' => array_map(fn (array $class) => [
                'name' => $class['name'],
                'students' => $class['students'],
                'overall_percent' => $class['overall'],
                'overall_rating' => $this->support->ratingFor($class['overall']),
                'by_rating' => $class['bands'],
            ], $now['classes']),
            'indicator_average' => (object) $observations()
                ->join('bh_ratings as r', 'r.observation_id', '=', 'o.id')
                ->whereBetween('o.observed_on', $inPeriod)
                ->groupBy('r.indicator')
                ->selectRaw('r.indicator, AVG(r.score) AS percent')
                ->pluck('percent', 'indicator')
                ->map(fn ($percent) => round((float) $percent, 1))
                ->all(),
            'recognition_tags' => (object) $recognitions()
                ->whereBetween('rc.given_on', $inPeriod)
                ->groupBy('rc.tag')
                ->selectRaw('rc.tag, COUNT(*) AS n')
                ->orderByDesc('n')
                ->pluck('n', 'tag')
                ->map(fn ($count) => (int) $count)
                ->all(),
            'follow_up' => $this->followUp($db, $filters),
        ];
    }

    public function studentReport(HomeworkCaller $caller, object $student, array $current, ?array $previous, ?int $subjectId): array
    {
        $db = $caller->db;
        $studentId = (int) $student->student_id;

        $now = $this->support->indicatorScores($db, $studentId, $current['from'], $current['to'], $subjectId);
        $old = $previous ? $this->support->indicatorScores($db, $studentId, $previous['from'], $previous['to'], $subjectId) : [];
        $overall = $this->support->overall($now);

        return [
            'scope' => 'student',
            'student_id' => (string) $student->student_id,
            'student_name' => $student->student_name,
            'overall_percent' => $overall,
            'overall_rating' => $this->support->ratingFor($overall),
            'previous_percent' => $this->support->overall($old),
            'indicators' => array_map(fn (string $code) => [
                'type' => $code,
                'percent' => $now[$code]['percent'] ?? null,
                'rating' => $this->support->ratingFor($now[$code]['percent'] ?? null),
                'previous_percent' => $old[$code]['percent'] ?? null,
                'ratings' => $now[$code]['ratings'] ?? 0,
            ], $this->support->indicators($db)),
        ];
    }

    public function classReport(HomeworkCaller $caller, array $filters, array $current, ?array $previous): array
    {
        $students = $this->support->students($caller, $filters);
        $now = $this->support->groupScores($caller, $filters, $current['from'], $current['to']);
        $old = $previous ? $this->support->groupScores($caller, $filters, $previous['from'], $previous['to']) : [];

        $rows = [];
        $sumNow = [];
        $sumOld = [];

        foreach ($students as $student) {
            $studentId = (int) $student->student_id;
            $scoresNow = $now[$studentId] ?? [];
            $scoresOld = $old[$studentId] ?? [];
            $overall = $this->support->overall($scoresNow);

            $rows[] = [
                'student_id' => (string) $student->student_id,
                'name' => $student->student_name,
                'roll' => (string) $student->roll_number,
                'overall_percent' => $overall,
                'overall_rating' => $this->support->ratingFor($overall),
                'previous_percent' => $this->support->overall($scoresOld),
                'indicators' => (object) $scoresNow,
            ];

            foreach ($scoresNow as $code => $percent) {
                $sumNow[$code][] = $percent;
            }

            foreach ($scoresOld as $code => $percent) {
                $sumOld[$code][] = $percent;
            }
        }

        $average = fn (array $values) => $values ? round(array_sum($values) / count($values), 1) : null;

        return [
            'scope' => 'class',
            'indicator_average' => array_map(fn (string $code) => [
                'type' => $code,
                'percent' => $average($sumNow[$code] ?? []),
                'previous_percent' => $average($sumOld[$code] ?? []),
            ], $this->support->indicators($caller->db)),
            'students' => $rows,
        ];
    }

    public function periodShape(array $period, bool $withKey = true): array
    {
        return ($withKey ? ['key' => $period['key']] : []) + ['label' => $period['label'], 'from' => $period['from'], 'to' => $period['to']];
    }

    /**
     * True only when a teacher or admin ticked Support Required, never from the ratings.
     */
    private function supportRequested(Connection $db, int $studentId, string $from): bool
    {
        return $db->table('bh_observations')->where('student_id', $studentId)->where('support_required', 1)->where('is_deleted', 0)->where('observed_on', '>=', $from)->exists()
            || $db->table('bh_incidents')->where('student_id', $studentId)->where('support_required', 1)->where('is_deleted', 0)->where('status', '<>', 'resolved')->exists();
    }

    private function recognitions(Connection $db, int $studentId): array
    {
        return $db->table('bh_recognitions as rc')
            ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'rc.given_by')
            ->where('rc.student_id', $studentId)
            ->where('rc.is_deleted', 0)
            ->orderByDesc('rc.given_on')
            ->orderByDesc('rc.id')
            ->limit(self::LIST_LIMIT)
            ->get(['rc.id', 'rc.tag', 'rc.given_on', 'rc.note', 't.teacher_name'])
            ->map(fn (object $row) => [
                'id' => (string) $row->id,
                'tag' => $row->tag,
                'date' => $row->given_on,
                'given_by' => (string) $row->teacher_name,
                'note' => (string) $row->note,
            ])
            ->all();
    }

    private function feedback(Connection $db, int $studentId): array
    {
        return $db->table('bh_observations as o')
            ->leftJoin('subject_info as sub', 'sub.subject_code', '=', 'o.subject_id')
            ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'o.teacher_id')
            ->where('o.student_id', $studentId)
            ->where('o.is_deleted', 0)
            ->whereNotNull('o.comment')
            ->where('o.comment', '<>', '')
            ->orderByDesc('o.observed_on')
            ->orderByDesc('o.id')
            ->limit(self::LIST_LIMIT)
            ->get(['o.id', 'o.comment', 'o.observed_on', 'sub.subject_name', 't.teacher_name'])
            ->map(fn (object $row) => [
                'id' => (string) $row->id,
                'subject' => (string) $row->subject_name,
                'teacher' => (string) $row->teacher_name,
                'comment' => $row->comment,
                'date' => $row->observed_on,
            ])
            ->all();
    }

    /**
     * Each row's own class on the day it was saved.
     */
    private function filtered(Builder $query, array $filters, string $alias): Builder
    {
        return $query
            ->when($filters['session_code'] ?? null, fn (Builder $q, $value) => $q->where("$alias.session_id", $value))
            ->when($filters['class_code'] ?? null, fn (Builder $q, $value) => $q->where("$alias.class_id", $value))
            ->when($filters['section_code'] ?? null, fn (Builder $q, $value) => $q->where("$alias.section_id", $value));
    }

    /**
     * student => overall, and per class and section the average and how many students are in each rating.
     */
    private function overallsByClass(Connection $db, array $filters, array $period): array
    {
        $rows = $this->filtered($db->table('bh_ratings as r')
            ->join('bh_observations as o', 'o.id', '=', 'r.observation_id')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'o.student_id')
            ->leftJoin('class_info as cls', 'cls.class_code', '=', 'o.class_id')
            ->leftJoin('section_info as sec', 'sec.section_code', '=', 'o.section_id')
            ->where('o.is_deleted', 0)
            ->whereBetween('o.observed_on', [$period['from'], $period['to']]), $filters, 'o')
            ->groupBy('o.student_id', 'o.class_id', 'o.section_id', 'cls.class_name', 'cls.class_position', 'sec.section_name', 'sec.sec_position', 'r.indicator')
            ->selectRaw('o.student_id, o.class_id, o.section_id, cls.class_name, cls.class_position, sec.section_name, sec.sec_position, r.indicator, AVG(r.score) AS percent')
            ->get();

        $scores = [];
        $places = [];

        foreach ($rows as $row) {
            $scores[(int) $row->student_id][$row->indicator] = (float) $row->percent;
            $places[(int) $row->student_id] = $row;
        }

        $students = [];
        $classes = [];

        foreach ($scores as $studentId => $percents) {
            $overall = round(array_sum($percents) / count($percents), 1);
            $students[$studentId] = $overall;
            $place = $places[$studentId];
            $key = $place->class_id.'-'.$place->section_id;

            $classes[$key] ??= [
                'name' => trim($place->class_name.' · '.$place->section_name, ' ·'),
                'sort' => sprintf('%05d-%05d', (int) $place->class_position, (int) $place->sec_position),
                'overalls' => [],
                'bands' => ['excellent' => 0, 'good' => 0, 'developing' => 0, 'needs_support' => 0],
            ];
            $classes[$key]['overalls'][] = $overall;
            $classes[$key]['bands'][$this->support->ratingFor($overall)]++;
        }

        foreach ($classes as $key => $class) {
            $classes[$key]['overall'] = round(array_sum($class['overalls']) / count($class['overalls']), 1);
            $classes[$key]['students'] = count($class['overalls']);
        }

        uasort($classes, fn (array $a, array $b) => strcmp($a['sort'], $b['sort']));

        return ['students' => $students, 'classes' => array_values($classes)];
    }

    /**
     * Open incidents, the longest without a change first.
     */
    private function followUp(Connection $db, array $filters): array
    {
        $dueBefore = Carbon::parse(HomeworkTime::now())->subDays(self::FOLLOW_UP_DAYS)->toDateTimeString();

        return $this->filtered($db->table('bh_incidents as i')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'i.student_id')
            ->leftJoin('student_info as si', 'si.student_registration_code', '=', 'sc.student_registration_code')
            ->leftJoin('class_info as cls', 'cls.class_code', '=', 'i.class_id')
            ->leftJoin('section_info as sec', 'sec.section_code', '=', 'i.section_id')
            ->where('i.is_deleted', 0)
            ->where('i.status', '<>', 'resolved'), $filters, 'i')
            ->selectRaw('i.id, i.student_id, i.status, i.category, TRIM(si.student_name) AS student_name, cls.class_name, sec.section_name, (SELECT MAX(h.created_at) FROM bh_incident_history h WHERE h.incident_id = i.id) AS last_change_at')
            ->orderBy('last_change_at')
            ->limit(8)
            ->get()
            ->map(fn (object $row) => [
                'id' => 'inc-'.$row->id,
                'student_id' => (string) $row->student_id,
                'student_name' => (string) $row->student_name,
                'class' => trim($row->class_name.' '.$row->section_name),
                'category' => $row->category,
                'status' => $row->status,
                'last_change_at' => HomeworkTime::toApi($row->last_change_at),
                'follow_up_due' => $row->last_change_at !== null && $row->last_change_at < $dueBefore,
            ])
            ->all();
    }
}
