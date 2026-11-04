<?php

namespace App\Services\Behaviour;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What the behaviour services share: codes, terms and periods, teacher scope, scores and the
 * notification queue. The web system's pages read the same rows, so this follows its bh_common_model.
 * Students are current_status_info_id here, like in homework.
 */
class BehaviourSupport
{
    private const TABLES = ['bh_observations', 'bh_ratings', 'bh_recognitions', 'bh_incidents', 'bh_incident_history', 'bh_goals', 'bh_terms'];

    private const DEFAULT_INDICATORS = [
        'responsibility', 'respect', 'punctuality', 'classroom_behaviour', 'teamwork',
        'self_control', 'participation', 'leadership', 'homework_responsibility', 'school_rules_compliance',
    ];

    private const DEFAULT_TAGS = [
        'best_behaviour', 'most_responsible', 'helpful_student', 'leadership', 'team_player', 'punctuality', 'positive_improvement',
    ];

    // Score saved with each rating, and the lowest percent that earns the rating. Highest first.
    public const RATINGS = [
        'excellent' => ['score' => 100, 'from_percent' => 85],
        'good' => ['score' => 75, 'from_percent' => 70],
        'developing' => ['score' => 50, 'from_percent' => 55],
        'needs_support' => ['score' => 25, 'from_percent' => 0],
    ];

    // The incident lifecycle, in order.
    public const STATUSES = ['reported', 'under_review', 'resolved'];

    private const STATUS_LABELS = ['reported' => 'Reported', 'under_review' => 'Under Review', 'resolved' => 'Resolved'];

    private const BN_MONTHS = [1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

    public function __construct(private SchoolDbService $schools) {}

    public function ensureSetUp(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();

        foreach (self::TABLES as $table) {
            if (! $schema->hasTable($table)) {
                throw new ApiException('The behaviour module is not set up for this school yet.', 503, errorCode: 'not_set_up');
            }
        }
    }

    public function writer(HomeworkCaller $caller): Connection
    {
        return $this->schools->connectForBehaviour($caller->dbName);
    }

    public function isAdmin(HomeworkCaller $caller): bool
    {
        return $caller->user->role === AppUser::ROLE_ADMIN;
    }

    /**
     * @return string[] The school's active indicator codes, in order.
     */
    public function indicators(Connection $db): array
    {
        return $this->options($db)['indicator'];
    }

    /**
     * @return string[] The school's active recognition tag codes.
     */
    public function tags(Connection $db): array
    {
        return $this->options($db)['tag'];
    }

    public function tagName(Connection $db, string $code): string
    {
        return $this->options($db)['tag_names'][$code] ?? $code;
    }

    /**
     * The admin can change the lists in bh_options. A type with no rows keeps the defaults.
     */
    private function options(Connection $db): array
    {
        $rows = $db->getSchemaBuilder()->hasTable('bh_options')
            ? $db->table('bh_options')->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        $active = fn (string $type) => $rows->where('type', $type)->where('is_active', 1)->pluck('code')->all();
        $hasRows = fn (string $type) => $rows->where('type', $type)->isNotEmpty();

        $indicators = $hasRows('indicator') ? $active('indicator') : self::DEFAULT_INDICATORS;
        $tags = $hasRows('tag') ? $active('tag') : self::DEFAULT_TAGS;

        // Turned-off tags still name their old rows.
        $tagNames = $rows->where('type', 'tag')->pluck('name_en', 'code')->all();

        foreach (self::DEFAULT_TAGS as $code) {
            $tagNames[$code] ??= ucwords(str_replace('_', ' ', $code));
        }

        return ['indicator' => $indicators, 'tag' => $tags, 'tag_names' => $tagNames];
    }

    public function statusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES, true);

        return $index === false ? -1 : $index;
    }

    public function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function ratingFor(int|float|null $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        foreach (self::RATINGS as $code => $rating) {
            if ($percent >= $rating['from_percent']) {
                return $code;
            }
        }

        return 'needs_support';
    }

    public function runningSessionCode(Connection $db): ?int
    {
        $code = $db->table('session_info')->where('running_session', 1)->max('session_code');

        return $code === null ? null : (int) $code;
    }

    /**
     * The session's terms, or the last 12 months when the school has entered no terms.
     */
    public function periods(Connection $db, mixed $sessionCode): array
    {
        $terms = $db->table('bh_terms')->where('session_id', $sessionCode)->orderBy('sort_order')->orderBy('start_date')->get();

        if ($terms->isEmpty()) {
            return $this->monthPeriods();
        }

        return $terms->map(fn (object $term) => [
            'key' => 'term-'.$term->id,
            'label' => $term->name,
            'from' => $term->start_date,
            'to' => $term->end_date,
        ])->all();
    }

    public function monthPeriods(int $count = 12): array
    {
        $thisMonth = Carbon::parse(HomeworkTime::today())->startOfMonth();
        $periods = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $month = $thisMonth->copy()->subMonths($i);

            $periods[] = [
                'key' => 'month-'.$month->format('Y-m'),
                'label' => self::BN_MONTHS[$month->month].' '.$month->year,
                'from' => $month->toDateString(),
                'to' => $month->copy()->endOfMonth()->toDateString(),
            ];
        }

        return $periods;
    }

    /**
     * The period holding today, else the last one that has started, else the first.
     */
    public function currentIndex(array $periods): int
    {
        $today = HomeworkTime::today();
        $last = 0;

        foreach ($periods as $index => $period) {
            if ($period['from'] <= $today && $today <= $period['to']) {
                return $index;
            }

            if ($period['from'] <= $today) {
                $last = $index;
            }
        }

        return $last;
    }

    public function periodIndex(array $periods, ?string $key): int
    {
        foreach ($periods as $index => $period) {
            if ($period['key'] === $key) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * An admin sees the whole school. A teacher sees students of a section they are class teacher
     * of, or a class they teach. A row with no session, section or shift counts for all.
     */
    public function limitToCaller(Builder $query, HomeworkCaller $caller, string $alias): Builder
    {
        if ($this->isAdmin($caller)) {
            return $query;
        }

        if (! $caller->teacherCode) {
            return $query->whereRaw('1 = 0');
        }

        $orAll = fn (string $column) => fn (Builder $q) => $q->whereNull($column)->orWhere($column, 0);

        return $query->where(fn (Builder $outer) => $outer
            ->whereExists(fn (Builder $exists) => $exists
                ->selectRaw('1')
                ->from('class_teacher_info as ct')
                ->where('ct.teacher_code', $caller->teacherCode)
                ->whereColumn('ct.class_code', "$alias.class_code")
                ->whereColumn('ct.section_code', "$alias.section_code")
                ->where(fn (Builder $q) => $orAll('ct.session_code')($q)->orWhereColumn('ct.session_code', "$alias.session_code"))
                ->where(fn (Builder $q) => $orAll('ct.shift_code')($q)->orWhereColumn('ct.shift_code', "$alias.shift_code")))
            ->orWhereExists(fn (Builder $exists) => $exists
                ->selectRaw('1')
                ->from('subject_teacher_info as st')
                ->where('st.teacher_code', $caller->teacherCode)
                ->whereColumn('st.class_code', "$alias.class_code")
                ->where(fn (Builder $q) => $orAll('st.section_code')($q)->orWhereColumn('st.section_code', "$alias.section_code"))
                ->where(fn (Builder $q) => $orAll('st.session_code')($q)->orWhereColumn('st.session_code', "$alias.session_code"))
                ->where(fn (Builder $q) => $orAll('st.shift_code')($q)->orWhereColumn('st.shift_code', "$alias.shift_code"))));
    }

    public function student(Connection $db, int $studentId): ?object
    {
        return $db->table('student_current_status_info as sc')
            ->leftJoin('student_info as si', 'si.student_registration_code', '=', 'sc.student_registration_code')
            ->where('sc.current_status_info_id', $studentId)
            ->selectRaw('sc.current_status_info_id AS student_id, sc.roll_number, sc.active_status, sc.session_code, sc.class_code, sc.section_code, sc.shift_code, TRIM(si.student_name) AS student_name, si.contact_number')
            ->first();
    }

    /**
     * The active student, if the caller may work with them.
     */
    public function scopedStudent(HomeworkCaller $caller, int $studentId): ?object
    {
        $student = $this->student($caller->db, $studentId);

        if (! $student || (int) $student->active_status !== 1) {
            return null;
        }

        $allowed = $this->limitToCaller($caller->db->table('student_current_status_info as sc'), $caller, 'sc')
            ->where('sc.current_status_info_id', $studentId)
            ->exists();

        return $allowed ? $student : null;
    }

    /**
     * The student named in a request body, if this teacher or admin may log for them.
     */
    public function managedStudent(HomeworkCaller $caller, mixed $value): object
    {
        if (trim((string) $value) === '') {
            throw ValidationException::withMessages(['student_id' => 'student_id is required.']);
        }

        $studentId = BehaviourText::studentId($value);

        return ($studentId ? $this->scopedStudent($caller, $studentId) : null)
            ?? throw new ApiException('This student is not one of your students, or is no longer active.', 403);
    }

    /**
     * Active students for the roster and class reports. Every filter is optional.
     */
    public function students(HomeworkCaller $caller, array $filters, int $limit = 500): Collection
    {
        $query = $caller->db->table('student_current_status_info as sc')
            ->leftJoin('student_info as si', 'si.student_registration_code', '=', 'sc.student_registration_code')
            ->leftJoin('class_info as cls', 'cls.class_code', '=', 'sc.class_code')
            ->leftJoin('section_info as sec', 'sec.section_code', '=', 'sc.section_code')
            ->where('sc.active_status', 1)
            ->when($filters['session_code'] ?? null, fn (Builder $q, $value) => $q->where('sc.session_code', $value))
            ->when($filters['class_code'] ?? null, fn (Builder $q, $value) => $q->where('sc.class_code', $value))
            ->when($filters['section_code'] ?? null, fn (Builder $q, $value) => $q->where('sc.section_code', $value))
            ->when($filters['search'] ?? null, fn (Builder $q, $value) => $q->where(fn (Builder $inner) => $inner
                ->where('si.student_name', 'like', "%$value%")
                ->orWhere('sc.roll_number', 'like', "%$value%")));

        return $this->limitToCaller($query, $caller, 'sc')
            ->selectRaw('sc.current_status_info_id AS student_id, sc.roll_number, sc.session_code, sc.class_code, sc.section_code, TRIM(si.student_name) AS student_name, cls.class_name, sec.section_name')
            ->orderBy('cls.class_position')
            ->orderBy('sec.sec_position')
            ->orderByRaw('sc.roll_number + 0')
            ->orderBy('sc.roll_number')
            ->limit($limit)
            ->get();
    }

    /**
     * indicator => [percent, ratings] for one student, live from bh_ratings. Turned-off indicators are left out.
     */
    public function indicatorScores(Connection $db, int $studentId, string $from, string $to, ?int $subjectId = null): array
    {
        $rows = $db->table('bh_ratings as r')
            ->join('bh_observations as o', 'o.id', '=', 'r.observation_id')
            ->where('o.student_id', $studentId)
            ->where('o.is_deleted', 0)
            ->whereBetween('o.observed_on', [$from, $to])
            ->when($subjectId, fn (Builder $q) => $q->where('o.subject_id', $subjectId))
            ->groupBy('r.indicator')
            ->selectRaw('r.indicator, AVG(r.score) AS percent, COUNT(*) AS ratings')
            ->get();

        $active = $this->indicators($db);
        $scores = [];

        foreach ($rows as $row) {
            if (in_array($row->indicator, $active, true)) {
                $scores[$row->indicator] = ['percent' => round((float) $row->percent, 1), 'ratings' => (int) $row->ratings];
            }
        }

        return $scores;
    }

    /**
     * student => indicator => percent, for many students at once.
     */
    public function groupScores(HomeworkCaller $caller, array $filters, string $from, string $to): array
    {
        $query = $caller->db->table('bh_ratings as r')
            ->join('bh_observations as o', 'o.id', '=', 'r.observation_id')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'o.student_id')
            ->where('o.is_deleted', 0)
            ->whereBetween('o.observed_on', [$from, $to])
            ->when($filters['session_code'] ?? null, fn (Builder $q, $value) => $q->where('o.session_id', $value))
            ->when($filters['class_code'] ?? null, fn (Builder $q, $value) => $q->where('o.class_id', $value))
            ->when($filters['section_code'] ?? null, fn (Builder $q, $value) => $q->where('o.section_id', $value))
            ->when($filters['subject_id'] ?? null, fn (Builder $q, $value) => $q->where('o.subject_id', $value));

        $rows = $this->limitToCaller($query, $caller, 'sc')
            ->groupBy('o.student_id', 'r.indicator')
            ->selectRaw('o.student_id, r.indicator, AVG(r.score) AS percent')
            ->get();

        $active = $this->indicators($caller->db);
        $scores = [];

        foreach ($rows as $row) {
            if (in_array($row->indicator, $active, true)) {
                $scores[(int) $row->student_id][$row->indicator] = round((float) $row->percent, 1);
            }
        }

        return $scores;
    }

    /**
     * The average of the indicators that have ratings. Null when none do.
     */
    public function overall(array $scores): ?float
    {
        if (! $scores) {
            return null;
        }

        $values = array_map(fn ($score) => is_array($score) ? $score['percent'] : $score, $scores);

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * Only queued here. A sender still has to read sl_notifications. Incident details never go in a message.
     */
    public function notify(Connection $writer, int $refId, string $event, string $type, ?int $recipientId, ?string $mobile, string $message): void
    {
        if (! $writer->getSchemaBuilder()->hasTable('sl_notifications')) {
            return;
        }

        $mobile = trim((string) $mobile);

        $writer->table('sl_notifications')->insert([
            'module' => 'bh',
            'ref_id' => $refId,
            'event' => $event,
            'recipient_type' => $type,
            'recipient_id' => $recipientId,
            'mobile' => $mobile !== '' ? $mobile : null,
            'channel' => $mobile !== '' ? 'sms' : 'inapp',
            'message' => $message,
            'status' => 'queued',
            'created_at' => HomeworkTime::now(),
        ]);
    }

    /**
     * Only when a teacher or admin ticks it, never from a rating. Guardian only.
     */
    public function notifySupportRequired(Connection $writer, object $student, int $refId): void
    {
        $this->notify($writer, $refId, 'support_required', 'guardian', (int) $student->student_id, $student->contact_number,
            "Pathshala: the school would like to talk with you about how we can support {$student->student_name} together. Please contact the school.");
    }
}
