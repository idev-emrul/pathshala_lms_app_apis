<?php

namespace App\Services\Quiz;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkReader;
use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read queries and the JSON shapes of the quiz API. Answer keys only leave here in a released result.
 */
class QuizReader
{
    private const ROW_COLUMNS = [
        'quiz.*',
        'row.id as row_id',
        'row.status as row_status',
        'row.accepted_at',
        'row.reopen_deadline',
        'row.obtained_marks',
        'row.percentage',
        'row.grading_band_id',
        'row.remarks_label',
        'row.teacher_feedback',
        'row.evaluation_status',
        'row.is_released',
    ];

    public function __construct(private HomeworkReader $homework) {}

    public function ensureSetUp(Connection $db): void
    {
        if (! $db->getSchemaBuilder()->hasTable('qz_quizzes')) {
            throw new ApiException('Quiz is not set up for this school', 503);
        }
    }

    public function number(mixed $value): int|float|null
    {
        return $this->homework->number($value);
    }

    public function settings(Connection $db): ?object
    {
        return $db->table('qz_settings')->first();
    }

    // Student

    public function studentQuizIds(Connection $db, int $studentId): array
    {
        return $this->studentRows($db, $studentId)->pluck('quiz.id')->all();
    }

    public function findForStudent(Connection $db, int $studentId, int $quizId): object
    {
        return $this->studentRows($db, $studentId)->where('quiz.id', $quizId)->first(self::ROW_COLUMNS)
            ?? throw new ApiException('Quiz not found', 404);
    }

    public function studentQuiz(Connection $db, object $row): array
    {
        return $this->studentItems($db, collect([$row]))[0];
    }

    public function report(Connection $db, int $studentId, array $filters): array
    {
        $all = $this->studentRows($db, $studentId)->get(self::ROW_COLUMNS);
        $year = (int) ($filters['year'] ?? Carbon::parse(HomeworkTime::now())->year);
        $month = isset($filters['month']) ? (int) $filters['month'] : null;

        $rows = $all->filter(function ($row) use ($year, $month, $filters) {
            $due = Carbon::parse($row->due_at);

            return $due->year === $year
                && (! $month || $due->month === $month)
                && (empty($filters['status']) || $this->acceptance($row->row_status) === $filters['status']);
        });

        // Pending first by deadline, then newest.
        $sorted = $rows->sort(function ($a, $b) {
            $aPending = $this->acceptance($a->row_status) === 'pending';
            $bPending = $this->acceptance($b->row_status) === 'pending';

            if ($aPending !== $bPending) {
                return $aPending ? -1 : 1;
            }

            if ($aPending) {
                return strcmp($this->deadline($a), $this->deadline($b));
            }

            return strcmp($b->due_at, $a->due_at) ?: $b->id <=> $a->id;
        })->values();

        return [
            'year' => (string) $year,
            'years' => $all->map(fn ($row) => (string) Carbon::parse($row->due_at)->year)->unique()->sortDesc()->values()->all(),
            'summary' => $this->summary($db, $rows),
            'quizzes' => $this->studentItems($db, $sorted),
        ];
    }

    private function studentRows(Connection $db, int $studentId): Builder
    {
        return $db->table('qz_quiz_students as row')
            ->join('qz_quizzes as quiz', 'quiz.id', '=', 'row.quiz_id')
            ->where('row.student_id', $studentId)
            ->where('quiz.is_deleted', 0)
            ->whereNotIn('quiz.status', ['draft', 'cancelled']);
    }

    private function studentItems(Connection $db, Collection $rows): array
    {
        $names = $this->names($db, $rows);
        $attempts = $db->table('qz_attempts')->whereIn('quiz_student_id', $rows->pluck('row_id')->all())->orderBy('attempt_no')->get()->groupBy('quiz_student_id');

        return $rows->map(function ($row) use ($db, $names, $attempts) {
            $rowAttempts = $attempts[$row->row_id] ?? collect();
            $inApp = $row->conduct_mode === 'in_app';
            $latest = $rowAttempts->last();

            return [
                'id' => (string) $row->id,
                'title' => $row->title,
                'subject' => $names['subjects'][$row->subject_id] ?? null,
                'class_label' => $this->classLabel($row, $names),
                'instructions' => $row->instructions,
                'total_marks' => $this->homework->number($row->total_marks),
                'due_at' => HomeworkTime::toApi($this->deadline($row)),
                'quiz_due_at' => HomeworkTime::toApi($this->closesAt($row)),
                'acceptance' => $this->acceptance($row->row_status),
                'accepted_at' => HomeworkTime::toApi($row->accepted_at),
                'mode' => $row->conduct_mode,
                'duration_minutes' => $row->duration_minutes === null ? null : (int) $row->duration_minutes,
                'question_count' => (int) ($names['questions'][$row->id] ?? 0),
                'attempt' => $latest ? ($latest->status === 'in_progress' ? 'in_progress' : 'submitted') : null,
                'attempts_left' => $inApp ? max(0, (int) $row->max_attempts - $rowAttempts->count()) : null,
                'teacher' => $names['teachers'][$row->teacher_id] ?? null,
                'result' => $row->is_released ? $this->result($db, $row, $rowAttempts, $names['bands']) : null,
            ];
        })->all();
    }

    private function result(Connection $db, object $row, Collection $attempts, Collection $bands): array
    {
        return [
            'obtained' => $this->homework->number($row->obtained_marks),
            'total' => $this->homework->number($row->total_marks),
            'percent' => $this->homework->number($row->percentage),
            'remarks' => $this->remarks($row, $bands),
            'feedback' => $row->teacher_feedback,
        ] + ($row->conduct_mode === 'in_app' ? ['review' => $this->review($db, $row, $attempts)] : []);
    }

    /**
     * The best attempt, the one that counted. Indexes follow the question's own option order.
     */
    private function review(Connection $db, object $row, Collection $attempts): array
    {
        $attempt = $attempts->where('status', '<>', 'in_progress')->sortBy(fn ($item) => (float) $item->final_score)->last();
        $answers = $attempt ? $db->table('qz_attempt_answers')->where('attempt_id', $attempt->id)->get()->keyBy('quiz_question_id') : collect();

        return $db->table('qz_quiz_questions')->where('quiz_id', $row->id)->orderBy('sort_order')->get()->map(function ($question) use ($answers) {
            $snapshot = json_decode($question->snapshot_json, true);
            $options = $snapshot['options'] ?? [];
            $keys = array_column($options, 'key');
            $multi = $question->question_type === 'mcq_multi';
            $answer = $answers[$question->id] ?? null;

            $correct = array_keys(array_filter($options, fn ($option) => ! empty($option['is_correct'])));
            $chosen = $answer && ! blank($answer->selected_option_ids)
                ? array_values(array_filter(array_map(fn ($key) => array_search($key, $keys, true), explode(',', $answer->selected_option_ids)), fn ($index) => $index !== false))
                : [];

            return [
                'question_id' => (string) $question->id,
                'text' => $snapshot['stem'] ?? null,
                'options' => array_column($options, 'text'),
                'correct' => $multi ? $correct : ($correct[0] ?? null),
                'chosen' => $multi ? $chosen : ($chosen[0] ?? null),
                'marks' => $this->homework->number($question->marks),
                'obtained' => $this->homework->number($answer->teacher_marks ?? $answer->auto_marks ?? 0),
                'explanation' => $snapshot['explanation'] ?? null,
            ];
        })->all();
    }

    private function summary(Connection $db, Collection $rows): array
    {
        $released = $rows->filter(fn ($row) => $row->is_released);
        $counted = $released->map(fn ($row) => ['title' => $row->title, 'percent' => (float) $row->percentage]);

        if ($this->settings($db)?->not_accepted_policy === 'zero') {
            $missed = $rows->filter(fn ($row) => $this->acceptance($row->row_status) === 'not_accepted' && HomeworkTime::isPast($this->closesAt($row)));
            $counted = $counted->concat($missed->map(fn ($row) => ['title' => $row->title, 'percent' => 0.0]));
        }

        $best = $counted->sortBy('percent')->last();
        $lowest = $counted->sortBy('percent')->first();

        return [
            'total' => $rows->count(),
            'accepted' => $rows->filter(fn ($row) => $this->acceptance($row->row_status) === 'accepted')->count(),
            'pending' => $rows->filter(fn ($row) => $this->acceptance($row->row_status) === 'pending')->count(),
            'not_accepted' => $rows->filter(fn ($row) => $this->acceptance($row->row_status) === 'not_accepted')->count(),
            'total_marks' => $this->homework->number($released->sum(fn ($row) => (float) $row->total_marks)),
            'total_obtained' => $this->homework->number($released->sum(fn ($row) => (float) $row->obtained_marks)),
            'average_percent' => $counted->isEmpty() ? null : round($counted->avg('percent'), 1),
            'best' => $best ? ['title' => $best['title'], 'percent' => round($best['percent'], 1)] : null,
            'lowest' => $lowest ? ['title' => $lowest['title'], 'percent' => round($lowest['percent'], 1)] : null,
        ];
    }

    // Teacher

    /**
     * Quizzes the teacher created, or that name them as the teacher.
     */
    public function visibleQuizzes(HomeworkCaller $caller): Builder
    {
        return $caller->db->table('qz_quizzes as quiz')
            ->where('quiz.is_deleted', 0)
            ->where(function ($query) use ($caller) {
                $query->where('quiz.created_by', $caller->userId);

                if ($caller->teacherCode) {
                    $query->orWhere('quiz.teacher_id', $caller->teacherCode);
                }
            });
    }

    /**
     * Another teacher's quiz looks the same as one that does not exist.
     */
    public function findVisible(HomeworkCaller $caller, int $quizId): object
    {
        return $this->visibleQuizzes($caller)->where('quiz.id', $quizId)->first(['quiz.*'])
            ?? throw new ApiException('Quiz not found', 404);
    }

    public function teacherList(HomeworkCaller $caller, array $filters): array
    {
        [$classId, $sectionId] = array_pad(explode('-', (string) ($filters['class_id'] ?? '')), 2, null);

        $quizzes = $this->visibleQuizzes($caller)
            ->when($classId, fn ($query, $value) => $query->where('quiz.class_id', $value))
            ->when($sectionId, fn ($query, $value) => $query->where('quiz.section_id', $value))
            ->when($filters['subject_id'] ?? null, fn ($query, $value) => $query->where('quiz.subject_id', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('quiz.status', $value))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where('quiz.title', 'like', '%'.addcslashes($value, '\\%_').'%'))
            ->orderByDesc('quiz.id')
            ->get(['quiz.*']);

        return ['quizzes' => $this->teacherItems($caller->db, $quizzes)] + $this->classOptions($caller);
    }

    /**
     * Keys for the create form: "12" is every section, "12-4" is one. The whole-class key
     * is only for a teacher who teaches every section that has students.
     */
    public function classOptions(HomeworkCaller $caller): array
    {
        $classes = [];
        $bySubject = [];

        foreach ($this->homework->teachingOptions($caller) as $option) {
            $sectionIds = collect($option['sections'])->pluck('id');

            $inClass = $caller->db->table('student_current_status_info as status')
                ->join('session_info as session', 'session.session_code', '=', 'status.session_code')
                ->where('session.running_session', 1)
                ->where('status.class_code', $option['id'])
                ->where('status.active_status', 1)
                ->distinct()
                ->pluck('status.section_code')
                ->map(fn ($code) => (string) $code);

            if ($inClass->isNotEmpty() && $inClass->diff($sectionIds)->isEmpty()) {
                $classes[$option['id']] = $option['name'];
            }

            foreach ($option['sections'] as $section) {
                $classes[$option['id'].'-'.$section['id']] = trim($option['name'].' '.$section['name']);
            }

            $bySubject[$option['id']] = $option['subjects'];
        }

        return [
            'classes' => (object) $classes,
            'subjects' => collect($bySubject)->flatten(1)->pluck('name')->filter()->unique()->values()->all(),
            'subjects_by_class' => (object) $bySubject,
        ];
    }

    public function teacherQuiz(Connection $db, object $quiz): array
    {
        return $this->teacherItems($db, collect([$quiz]))[0];
    }

    public function roster(Connection $db, object $quiz): array
    {
        $rows = $db->table('qz_quiz_students as row')
            ->leftJoin('student_current_status_info as status', 'status.current_status_info_id', '=', 'row.student_id')
            ->leftJoin('student_info as student', 'student.student_registration_code', '=', 'status.student_registration_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'status.section_code')
            ->where('row.quiz_id', $quiz->id)
            ->get(['row.*', 'status.roll_number', 'student.student_name', 'section.section_name', 'section.sec_position']);

        $attempts = $db->table('qz_attempts')->whereIn('quiz_student_id', $rows->pluck('id')->all())->get()->groupBy('quiz_student_id');
        $bands = $this->bands($db);

        $entries = $rows->sortBy([fn ($a, $b) => (int) $a->roll_number <=> (int) $b->roll_number, fn ($a, $b) => (int) $a->sec_position <=> (int) $b->sec_position])
            ->values()
            ->map(function ($row) use ($attempts, $bands) {
                $rowAttempts = $attempts[$row->id] ?? collect();
                $finished = $rowAttempts->where('status', '<>', 'in_progress');
                $marked = $row->evaluation_status === 'evaluated' || $row->is_released;

                return [
                    'student_id' => (string) $row->student_id,
                    'name' => $row->student_name,
                    'roll' => $row->roll_number === null ? null : (string) $row->roll_number,
                    'section' => $row->section_name,
                    'acceptance' => $this->acceptance($row->status),
                    'submitted' => $finished->isNotEmpty(),
                    'in_progress' => $rowAttempts->contains('status', 'in_progress'),
                    'auto_score' => $finished->isEmpty() ? null : $this->homework->number($finished->max(fn ($item) => (float) $item->auto_score)),
                    'obtained' => $marked ? $this->homework->number($row->obtained_marks) : null,
                    'percent' => $marked ? $this->homework->number($row->percentage) : null,
                    'feedback' => $marked ? $row->teacher_feedback : null,
                    'remarks' => $marked ? $this->remarks($row, $bands) : null,
                    'released' => (bool) $row->is_released,
                ];
            })->all();

        return ['quiz' => $this->teacherQuiz($db, $quiz), 'entries' => $entries];
    }

    private function teacherItems(Connection $db, Collection $quizzes): array
    {
        $names = $this->names($db, $quizzes);
        $rows = $db->table('qz_quiz_students')->whereIn('quiz_id', $quizzes->pluck('id')->all())->get(['quiz_id', 'status', 'evaluation_status', 'percentage', 'is_released'])->groupBy('quiz_id');
        $creators = $db->table('users')->whereIn('id', $quizzes->pluck('created_by')->filter()->unique()->all())->pluck('user_full_name', 'id');
        $zeroPolicy = $this->settings($db)?->not_accepted_policy === 'zero';

        return $quizzes->map(function ($quiz) use ($names, $rows, $creators, $zeroPolicy) {
            $quizRows = $rows[$quiz->id] ?? collect();
            $count = fn (string $acceptance) => $quizRows->filter(fn ($row) => $this->acceptance($row->status) === $acceptance)->count();
            $accepted = $count('accepted');

            $percents = $quizRows->where('evaluation_status', '<>', 'not_evaluated')->map(fn ($row) => (float) $row->percentage);

            if ($zeroPolicy && HomeworkTime::isPast($quiz->due_at)) {
                $percents = $percents->concat(array_fill(0, $count('not_accepted'), 0.0));
            }

            return [
                'id' => (string) $quiz->id,
                'title' => $quiz->title,
                'subject' => $names['subjects'][$quiz->subject_id] ?? null,
                'class_label' => $this->classLabel($quiz, $names),
                'due_at' => HomeworkTime::toApi($quiz->accept_deadline),
                'quiz_due_at' => HomeworkTime::toApi($quiz->due_at),
                'mode' => $quiz->conduct_mode,
                'status' => $quiz->status,
                'total_marks' => $this->homework->number($quiz->total_marks),
                'duration_minutes' => $quiz->duration_minutes === null ? null : (int) $quiz->duration_minutes,
                'question_count' => (int) ($names['questions'][$quiz->id] ?? 0),
                'targeted' => $quizRows->count(),
                'accepted' => $accepted,
                'pending' => $count('pending'),
                'not_accepted' => $count('not_accepted'),
                'scored' => $quizRows->where('evaluation_status', 'auto_scored')->count(),
                'evaluated' => $quizRows->where('evaluation_status', 'evaluated')->count(),
                'released' => $quizRows->contains(fn ($row) => (bool) $row->is_released),
                'average_percent' => $percents->isEmpty() ? null : round($percents->avg(), 1),
                'highest_percent' => $percents->isEmpty() ? null : round($percents->max(), 1),
                'lowest_percent' => $percents->isEmpty() ? null : round($percents->min(), 1),
                'acceptance_rate' => $quizRows->isEmpty() ? null : round($accepted / $quizRows->count() * 100, 1),
                'teacher' => $quiz->teacher_id ? ['id' => (string) $quiz->teacher_id, 'name' => $names['teachers'][$quiz->teacher_id] ?? null] : null,
                'created_by' => $quiz->created_by ? ['id' => (string) $quiz->created_by, 'name' => $creators[$quiz->created_by] ?? null] : null,
            ];
        })->values()->all();
    }

    // Shared

    /**
     * A reopened student counts as pending, and an exempted one as not accepted.
     */
    public function acceptance(string $status): string
    {
        return match ($status) {
            'pending', 'reopened' => 'pending',
            'accepted' => 'accepted',
            default => 'not_accepted',
        };
    }

    public function deadline(object $row): string
    {
        return $row->reopen_deadline ?? $row->accept_deadline;
    }

    /**
     * A student's reopen deadline is also the last time they can hand in.
     */
    public function closesAt(object $row): string
    {
        return $row->reopen_deadline ?? $row->due_at;
    }

    /**
     * The band's Bangla label when set. The row stores the English one, as the web does.
     */
    private function remarks(object $row, Collection $bands): ?string
    {
        return $bands[$row->grading_band_id]->label_bn ?? $row->remarks_label;
    }

    private function bands(Connection $db): Collection
    {
        return $db->table('perf_grading_bands')->get(['id', 'label_bn'])->filter(fn ($band) => ! blank($band->label_bn))->keyBy('id');
    }

    private function classLabel(object $quiz, array $names): ?string
    {
        $class = $names['classes'][$quiz->class_id] ?? null;
        $section = $quiz->section_id ? ($names['sections'][$quiz->section_id] ?? null) : null;

        return $class === null ? null : trim($class.' '.$section);
    }

    private function names(Connection $db, Collection $quizzes): array
    {
        $ids = fn (string $field) => $quizzes->pluck($field)->filter()->unique()->all();

        return [
            'subjects' => $db->table('subject_info')->whereIn('subject_code', $ids('subject_id'))->pluck('subject_name', 'subject_code'),
            'classes' => $db->table('class_info')->whereIn('class_code', $ids('class_id'))->pluck('class_name', 'class_code'),
            'sections' => $db->table('section_info')->whereIn('section_code', $ids('section_id'))->pluck('section_name', 'section_code'),
            'teachers' => $db->table('teacher_info')->whereIn('teacher_code', $ids('teacher_id'))->pluck('teacher_name', 'teacher_code'),
            'questions' => $db->table('qz_quiz_questions')->whereIn('quiz_id', $ids('id'))->selectRaw('quiz_id, count(*) as total')->groupBy('quiz_id')->pluck('total', 'quiz_id'),
            'bands' => $this->bands($db),
        ];
    }
}
