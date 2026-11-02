<?php

namespace App\Services\HwAssignment;

use App\Exceptions\ApiException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read queries and the JSON shapes of the homework API.
 */
class HomeworkReader
{
    public const FILE_FORMATS = ['image', 'pdf', 'word', 'excel', 'powerpoint', 'audio', 'video'];

    public function __construct(private HomeworkFiles $files) {}

    // Student

    /**
     * The student's published or closed homework, newest due date first.
     */
    public function studentHomework(Connection $db, int $studentId, ?int $homeworkId = null): array
    {
        $rows = $db->table('hw_assignment_students as row')
            ->join('hw_assignments as a', 'a.id', '=', 'row.assignment_id')
            ->where('row.student_id', $studentId)
            ->where('a.is_deleted', 0)
            ->whereIn('a.status', ['published', 'closed'])
            ->when($homeworkId, fn ($query) => $query->where('a.id', $homeworkId))
            ->orderByDesc('a.due_at')
            ->get(['a.*', 'row.id as row_id', 'row.status as row_status', 'row.is_late as row_is_late', 'row.version_count', 'row.score_percent']);

        $details = $this->details($db, $rows);
        $work = $this->work($db, $rows->pluck('row_id')->all());

        return $rows->map(function ($row) use ($details, $work) {
            $rowWork = $work[$row->row_id];
            $dueAt = $rowWork['request']->new_due_at ?? $row->due_at;
            $status = $this->status($row->row_status, (bool) $row->row_is_late, $dueAt);

            return [
                'id' => (string) $row->id,
                'title' => $row->title,
                'description' => $row->description,
                'chapter' => $row->chapter_topic,
                'type' => $details['types'][$row->assignment_type_id] ?? null,
                'subject' => $details['subjects'][$row->subject_id] ?? null,
                'teacher_name' => $details['teachers'][$row->teacher_id] ?? null,
                'status' => $status,
                'due_at' => HomeworkTime::toApi($dueAt),
                'total_marks' => $this->number($row->total_marks),
                'passing_marks' => $this->number($row->passing_marks),
                'allowed_formats' => $details['formats'][$row->id] ?? [],
                'references' => $this->references($details['attachments'][$row->id] ?? [], $details['url']),
                'submissions' => $rowWork['versions'],
                'assessment' => $rowWork['assessment'],
                'resubmission_reason' => $status === 'resubmit_requested' ? $rowWork['request']?->reason : null,
                '_version_count' => (int) $row->version_count,
                '_is_late' => (bool) $row->row_is_late,
                '_score_percent' => $this->number($row->score_percent),
            ];
        })->all();
    }

    public function studentName(Connection $db, int $studentId): ?string
    {
        return $db->table('student_current_status_info as current')
            ->join('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->where('current.current_status_info_id', $studentId)
            ->value('student.student_name');
    }

    public function publicStudentItem(array $item): array
    {
        return array_diff_key($item, ['_version_count' => 1, '_is_late' => 1, '_score_percent' => 1]);
    }

    public function studentSummary(array $items): array
    {
        $count = fn (array $statuses) => count(array_filter($items, fn ($item) => in_array($item['status'], $statuses, true)));

        $total = count($items);
        $handedIn = array_filter($items, fn ($item) => $item['_version_count'] > 0);
        $onTime = array_filter($handedIn, fn ($item) => ! $item['_is_late']);
        $late = $count(['late']);
        $missing = $count(['missing']);

        $bySubject = [];

        foreach (collect($items)->groupBy('subject') as $subject => $group) {
            $bySubject[] = [
                'subject' => $subject,
                'total' => $group->count(),
                'submitted' => $group->where('_version_count', '>', 0)->count(),
                'late' => $group->where('status', 'late')->count(),
                'missing' => $group->where('status', 'missing')->count(),
                'average_percent' => $this->average($group->where('status', 'checked')->pluck('_score_percent')),
            ];
        }

        return [
            'total' => $total,
            'completed' => $count(['submitted', 'checked']),
            'pending' => $count(['not_submitted', 'resubmit_requested']),
            'missing' => $missing,
            'late' => $late,
            'average_score_percent' => $this->average(collect($items)->where('status', 'checked')->pluck('_score_percent')),
            'on_time_percent' => $this->percent(count($onTime), count($handedIn)),
            'completion_percent' => $this->percent(count($handedIn), $total),
            'late_percent' => $this->percent($late, $total),
            'missing_percent' => $this->percent($missing, $total),
            'by_subject' => $bySubject,
        ];
    }

    // Teacher

    /**
     * Homework the teacher created, or that names them as the teacher.
     */
    public function visibleHomework(HomeworkCaller $caller, array $filters = []): Builder
    {
        return $caller->db->table('hw_assignments as a')
            ->where('a.is_deleted', 0)
            ->where(function ($query) use ($caller) {
                $query->where('a.created_by', $caller->userId);

                if ($caller->teacherCode) {
                    $query->orWhere('a.teacher_id', $caller->teacherCode);
                }
            })
            ->when($filters['session_id'] ?? null, fn ($query, $value) => $query->where('a.academic_session_id', $value))
            ->when($filters['class_id'] ?? null, fn ($query, $value) => $query->where('a.class_id', $value))
            ->when($filters['section_id'] ?? null, fn ($query, $value) => $query->where('a.section_id', $value))
            ->when($filters['subject_id'] ?? null, fn ($query, $value) => $query->where('a.subject_id', $value))
            ->when($filters['type_id'] ?? null, fn ($query, $value) => $query->where('a.assignment_type_id', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('a.status', $value))
            ->when($filters['due_from'] ?? null, fn ($query, $value) => $query->where('a.due_at', '>=', $value.' 00:00:00'))
            ->when($filters['due_to'] ?? null, fn ($query, $value) => $query->where('a.due_at', '<=', $value.' 23:59:59'))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(function ($inner) use ($value) {
                $like = '%'.addcslashes($value, '\\%_').'%';
                $inner->where('a.title', 'like', $like)->orWhere('a.chapter_topic', 'like', $like);
            }));
    }

    /**
     * Another teacher's homework is not revealed: it looks the same as one that does not exist.
     */
    public function findVisible(HomeworkCaller $caller, int $homeworkId): object
    {
        return $this->visibleHomework($caller)->where('a.id', $homeworkId)->first(['a.*'])
            ?? throw new ApiException('Homework not found', 404);
    }

    public function teacherList(HomeworkCaller $caller, array $filters): array
    {
        return $this->teacherItems($caller->db, $this->visibleHomework($caller, $filters)->orderByDesc('a.id')->get(['a.*']));
    }

    public function dashboard(HomeworkCaller $caller, array $filters): array
    {
        $filters['status'] = null;

        $homework = $this->visibleHomework($caller, $filters)
            ->whereIn('a.status', ['published', 'closed'])
            ->orderByDesc('a.id')
            ->get(['a.*']);

        $counts = $this->counts($caller->db, $homework);
        $totals = ['assigned' => $homework->count(), 'submitted' => 0, 'pending' => 0, 'missing' => 0, 'under_review' => 0, 'checked' => 0];

        foreach ($counts as $row) {
            foreach (['submitted', 'pending', 'missing', 'under_review', 'checked'] as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return ['totals' => $totals, 'recent' => $this->teacherItems($caller->db, $homework->take(6))];
    }

    public function filterOptions(Connection $db): array
    {
        return [
            'sessions' => $db->table('session_info')->orderByDesc('session_code')->get(['session_code', 'session_name', 'running_session'])
                ->map(fn ($row) => ['id' => (string) $row->session_code, 'name' => $row->session_name, 'running' => (bool) $row->running_session])->all(),
            'types' => $this->activeTypes($db)->map(fn ($row) => ['id' => $this->slug($row->type_name), 'name' => $row->type_name])->values()->all(),
            'statuses' => ['draft', 'published', 'closed'],
            'teachers' => [],
        ];
    }

    public function activeTypes(Connection $db): Collection
    {
        return $db->table('hw_assignment_types')->where('status', 1)->orderBy('sort_order')->get(['id', 'type_name']);
    }

    public function teacherItem(Connection $db, object $homework): array
    {
        return $this->teacherItems($db, collect([$homework]))[0];
    }

    /**
     * One homework for the edit screen.
     */
    public function teacherDetail(Connection $db, object $homework): array
    {
        $attachments = $db->table('hw_assignment_attachments')->where('assignment_id', $homework->id)->orderBy('id')->get();
        $url = $this->files->urlResolver($db);
        $assessed = $db->table('hw_assignment_students')->where('assignment_id', $homework->id)->where('status', 'checked')->exists();
        $submitted = $db->table('hw_assignment_students')->where('assignment_id', $homework->id)->where('version_count', '>', 0)->exists();

        return $this->teacherItem($db, $homework) + [
            'form' => [
                'class_id' => $this->id($homework->class_id),
                'section_id' => $this->id($homework->section_id),
                'shift_id' => $this->id($homework->shift_id),
                'group_id' => $this->id($homework->group_id),
                'subject_id' => $this->id($homework->subject_id),
                'teacher_id' => $this->id($homework->teacher_id),
                'assigned_date' => $homework->assigned_date,
                'start_time' => $homework->start_time ? substr($homework->start_time, 0, 5) : null,
                'priority' => $homework->priority,
            ],
            'attachments' => $attachments->where('type', 'attachment')->values()->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->file_name,
                'url' => $url($row->file_path),
                'size_bytes' => $row->file_size === null ? null : (int) $row->file_size,
            ])->all(),
            'references' => $attachments->where('type', 'reference')->values()->map(fn ($row) => [
                'id' => (string) $row->id,
                'url' => $row->link,
            ])->all(),
            'locks' => [
                'editable' => $homework->status !== 'closed',
                'target' => $homework->status !== 'draft',
                'marks' => $assessed,
                'delete' => $submitted,
            ],
        ];
    }

    /**
     * One row per assigned student, by roll.
     */
    public function roster(Connection $db, object $homework): array
    {
        $rows = $this->students($db)->where('row.assignment_id', $homework->id)->get();
        $submittedAt = $db->table('hw_submissions')->whereIn('id', $rows->pluck('latest_submission_id')->filter()->all())->pluck('submitted_at', 'id');
        $requests = $this->openRequests($db, $rows->pluck('id')->all());

        return $rows->sortBy(fn ($row) => (int) $row->roll_number)->values()->map(fn ($row) => [
            'student_id' => (string) $row->student_id,
            'student_name' => $row->student_name,
            'roll' => $this->id($row->roll_number),
            'status' => $this->status($row->status, (bool) $row->is_late, $requests[$row->id]->new_due_at ?? $homework->due_at),
            'is_late' => (bool) $row->is_late,
            'submitted_at' => HomeworkTime::toApi($submittedAt[$row->latest_submission_id] ?? null),
            'versions' => (int) $row->version_count,
        ])->all();
    }

    public function studentWork(Connection $db, object $homework, int $studentId): array
    {
        $row = $this->students($db)->where('row.assignment_id', $homework->id)->where('row.student_id', $studentId)->first()
            ?? throw new ApiException('Student not found on this homework', 404);

        $work = $this->work($db, [$row->id])[$row->id];
        $status = $this->status($row->status, (bool) $row->is_late, $work['request']->new_due_at ?? $homework->due_at);

        return [
            'student_id' => (string) $row->student_id,
            'student_name' => $row->student_name,
            'roll' => $this->id($row->roll_number),
            'status' => $status,
            'resubmission_reason' => $status === 'resubmit_requested' ? $work['request']?->reason : null,
            'versions' => $work['versions'],
            'assessment' => $work['assessment'],
        ];
    }

    /**
     * The classes, sections and subjects a teacher teaches: the running sessions, or their latest session.
     */
    public function teachingOptions(HomeworkCaller $caller): array
    {
        if (! $caller->teacherCode) {
            return [];
        }

        $db = $caller->db;
        $running = $db->table('session_info')->where('running_session', 1)->pluck('session_code')->all();
        $rows = $db->table('subject_teacher_info')->where('teacher_code', $caller->teacherCode)->get();
        $sessionRows = $rows->whereIn('session_code', $running);

        if ($sessionRows->isEmpty() && $rows->isNotEmpty()) {
            $sessionRows = $rows->where('session_code', $rows->max('session_code'));
        }

        if ($sessionRows->isEmpty()) {
            return [];
        }

        $classes = $db->table('class_info')->whereIn('class_code', $sessionRows->pluck('class_code')->unique()->all())->orderBy('class_position')->get(['class_code', 'class_name']);
        $subjects = $db->table('subject_info')->whereIn('subject_code', $sessionRows->pluck('subject_code')->unique()->all())->pluck('subject_name', 'subject_code');
        $sections = $db->table('section_info')->where('section_activation_status', 1)->orderBy('sec_position')->pluck('section_name', 'section_code');

        return $classes->map(function ($class) use ($sessionRows, $subjects, $sections, $db) {
            $classRows = $sessionRows->where('class_code', $class->class_code);
            $codes = $classRows->pluck('section_code')->map(fn ($code) => (int) $code)->unique();

            // Section 0 means every section of the class.
            if ($codes->contains(0)) {
                $codes = $db->table('student_current_status_info')
                    ->where('class_code', $class->class_code)
                    ->whereIn('session_code', $classRows->pluck('session_code')->unique()->all())
                    ->where('active_status', 1)
                    ->distinct()
                    ->pluck('section_code')
                    ->map(fn ($code) => (int) $code);
            }

            return [
                'id' => (string) $class->class_code,
                'name' => $class->class_name,
                'sections' => $sections->filter(fn ($name, $code) => $codes->contains((int) $code))
                    ->map(fn ($name, $code) => ['id' => (string) $code, 'name' => $name])->values()->all(),
                'subjects' => $classRows->pluck('subject_code')->unique()->values()
                    ->map(fn ($code) => ['id' => (string) $code, 'name' => $subjects[$code] ?? null])->all(),
            ];
        })->all();
    }

    // Shared

    public function slug(string $name): string
    {
        return Str::slug($name, '_');
    }

    public function status(string $rowStatus, bool $isLate, string $dueAt): string
    {
        return match ($rowStatus) {
            'pending' => HomeworkTime::isPast($dueAt) ? 'missing' : 'not_submitted',
            'missing' => 'missing',
            'submitted', 'under_review' => $isLate ? 'late' : 'submitted',
            'resubmission_requested' => 'resubmit_requested',
            'checked' => 'checked',
            default => $rowStatus,
        };
    }

    public function openRequests(Connection $db, array $rowIds): Collection
    {
        return $db->table('hw_resubmission_requests')
            ->whereIn('assignment_student_id', $rowIds ?: [0])
            ->where('status', 'open')
            ->get()
            ->keyBy('assignment_student_id');
    }

    /**
     * Formats as stored. Word, Excel and PowerPoint are one "document" format in the API.
     */
    public function apiFormats(Collection $stored): array
    {
        return $stored->map(fn ($format) => in_array($format, ['word', 'excel', 'powerpoint'], true) ? 'document' : $format)
            ->unique()->values()->all();
    }

    public function number(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    private function students(Connection $db): Builder
    {
        return $db->table('hw_assignment_students as row')
            ->leftJoin('student_current_status_info as current', 'current.current_status_info_id', '=', 'row.student_id')
            ->leftJoin('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->select(['row.*', 'current.roll_number', 'student.student_name']);
    }

    private function teacherItems(Connection $db, Collection $homework): array
    {
        $details = $this->details($db, $homework);
        $counts = $this->counts($db, $homework);

        $classes = $this->names($db, 'class_info', 'class_code', 'class_name', $homework->pluck('class_id'));
        $sections = $this->names($db, 'section_info', 'section_code', 'section_name', $homework->pluck('section_id'));
        $creators = $this->names($db, 'users', 'id', 'user_full_name', $homework->pluck('created_by'));

        return $homework->values()->map(fn ($row) => [
            'id' => (string) $row->id,
            'title' => $row->title,
            'description' => $row->description,
            'chapter' => $row->chapter_topic,
            'type' => $details['types'][$row->assignment_type_id] ?? null,
            'class' => $classes[$row->class_id] ?? null,
            'section' => $sections[$row->section_id] ?? null,
            'subject' => $details['subjects'][$row->subject_id] ?? null,
            'teacher' => $row->teacher_id ? ['id' => (string) $row->teacher_id, 'name' => $details['teachers'][$row->teacher_id] ?? null] : null,
            'created_by' => $row->created_by ? ['id' => (string) $row->created_by, 'name' => $creators[$row->created_by] ?? null] : null,
            'status' => $row->status,
            'due_at' => HomeworkTime::toApi($row->due_at),
            'total_marks' => $this->number($row->total_marks),
            'passing_marks' => $this->number($row->passing_marks),
            'rubric' => $details['rubric'][$row->id] ?? [],
            'allowed_formats' => $details['formats'][$row->id] ?? [],
            'counts' => $counts[$row->id] ?? ['assigned' => 0, 'submitted' => 0, 'pending' => 0, 'missing' => 0, 'under_review' => 0, 'checked' => 0],
        ])->all();
    }

    private function counts(Connection $db, Collection $homework): array
    {
        $due = $homework->pluck('due_at', 'id');
        $counts = [];

        $rows = $db->table('hw_assignment_students')
            ->whereIn('assignment_id', $homework->pluck('id')->all() ?: [0])
            ->get(['assignment_id', 'status', 'version_count']);

        foreach ($homework as $row) {
            $counts[$row->id] = ['assigned' => 0, 'submitted' => 0, 'pending' => 0, 'missing' => 0, 'under_review' => 0, 'checked' => 0];
        }

        foreach ($rows as $row) {
            $count = &$counts[$row->assignment_id];
            $count['assigned']++;

            if ($row->version_count > 0) {
                $count['submitted']++;
            }

            match ($row->status) {
                'pending' => HomeworkTime::isPast($due[$row->assignment_id]) ? $count['missing']++ : $count['pending']++,
                'missing' => $count['missing']++,
                'submitted', 'under_review' => $count['under_review']++,
                'resubmission_requested' => $count['pending']++,
                'checked' => $count['checked']++,
                default => null,
            };

            unset($count);
        }

        return $counts;
    }

    private function details(Connection $db, Collection $homework): array
    {
        $ids = $homework->pluck('id')->all() ?: [0];

        return [
            'types' => $this->names($db, 'hw_assignment_types', 'id', 'type_name', $homework->pluck('assignment_type_id'))->map(fn ($name) => $this->slug($name)),
            'subjects' => $this->names($db, 'subject_info', 'subject_code', 'subject_name', $homework->pluck('subject_id')),
            'teachers' => $this->names($db, 'teacher_info', 'teacher_code', 'teacher_name', $homework->pluck('teacher_id')),
            'formats' => $db->table('hw_assignment_formats')->whereIn('assignment_id', $ids)->orderBy('id')->get()
                ->groupBy('assignment_id')->map(fn ($rows) => $this->apiFormats($rows->pluck('format'))),
            'rubric' => $db->table('hw_assignment_rubric_criteria')->whereIn('assignment_id', $ids)->orderBy('sort_order')->get()
                ->groupBy('assignment_id')->map(fn ($rows) => $rows->map(fn ($row) => ['name' => $row->criteria_name, 'max' => $this->number($row->max_marks)])->values()->all()),
            'attachments' => $db->table('hw_assignment_attachments')->whereIn('assignment_id', $ids)->orderBy('id')->get()->groupBy('assignment_id'),
            'url' => $this->files->urlResolver($db),
        ];
    }

    private function references(iterable $attachments, callable $url): array
    {
        $references = [];

        foreach ($attachments as $row) {
            $references[] = $row->type === 'reference'
                ? ['name' => $row->link, 'url' => $row->link, 'size_bytes' => null]
                : ['name' => $row->file_name, 'url' => $url($row->file_path), 'size_bytes' => $row->file_size === null ? null : (int) $row->file_size];
        }

        return $references;
    }

    /**
     * Every version, the latest version's assessment and any open request, per student row.
     */
    private function work(Connection $db, array $rowIds): array
    {
        $work = [];

        foreach ($rowIds as $rowId) {
            $work[$rowId] = ['versions' => [], 'assessment' => null, 'request' => null];
        }

        if (! $rowIds) {
            return $work;
        }

        $submissions = $db->table('hw_submissions')->whereIn('assignment_student_id', $rowIds)->orderBy('version_no')->get();
        $files = $db->table('hw_submission_files')->whereIn('submission_id', $submissions->pluck('id')->all() ?: [0])->orderBy('id')->get()->groupBy('submission_id');
        $url = $this->files->urlResolver($db);

        foreach ($submissions as $submission) {
            $rows = $files[$submission->id] ?? collect();
            $links = $rows->where('file_type', 'link')->pluck('link')->values()->all();

            $work[$submission->assignment_student_id]['versions'][] = [
                'version' => (int) $submission->version_no,
                'submitted_at' => HomeworkTime::toApi($submission->submitted_at),
                'is_late' => (bool) $submission->is_late,
                'text' => $submission->text_answer,
                'link' => $links[0] ?? null,
                'links' => $links,
                'files' => $rows->where('file_type', '<>', 'link')->values()->map(fn ($file) => [
                    'name' => $file->file_name,
                    'url' => $url($file->file_path),
                    'size_bytes' => $file->file_size === null ? null : (int) $file->file_size,
                ])->all(),
            ];
        }

        foreach ($this->assessments($db, $submissions->where('is_latest', 1)) as $rowId => $assessment) {
            $work[$rowId]['assessment'] = $assessment;
        }

        foreach ($this->openRequests($db, $rowIds) as $rowId => $request) {
            $work[$rowId]['request'] = $request;
        }

        return $work;
    }

    private function assessments(Connection $db, Collection $latest): array
    {
        $assessments = $db->table('hw_assessments')->whereIn('submission_id', $latest->pluck('id')->all() ?: [0])->get();
        $ids = $assessments->pluck('id')->all() ?: [0];

        $scores = $db->table('hw_assessment_rubric_scores as score')
            ->join('hw_assignment_rubric_criteria as criteria', 'criteria.id', '=', 'score.criteria_id')
            ->whereIn('score.assessment_id', $ids)
            ->orderBy('criteria.sort_order')
            ->get(['score.assessment_id', 'score.obtained_marks', 'criteria.criteria_name', 'criteria.max_marks'])
            ->groupBy('assessment_id');

        $tags = $db->table('hw_assessment_feedback_tags as link')
            ->join('hw_feedback_tags as tag', 'tag.id', '=', 'link.feedback_tag_id')
            ->whereIn('link.assessment_id', $ids)
            ->get(['link.assessment_id', 'tag.tag_name'])
            ->groupBy('assessment_id');

        $result = [];

        foreach ($assessments as $assessment) {
            $result[$assessment->assignment_student_id] = [
                'obtained_marks' => $this->number($assessment->obtained_marks),
                'total_marks' => $this->number($assessment->total_marks),
                'feedback' => $assessment->feedback_text,
                'tags' => ($tags[$assessment->id] ?? collect())->map(fn ($tag) => $this->slug($tag->tag_name))->values()->all(),
                'criteria' => ($scores[$assessment->id] ?? collect())->map(fn ($score) => [
                    'name' => $score->criteria_name,
                    'obtained' => $this->number($score->obtained_marks),
                    'max' => $this->number($score->max_marks),
                ])->values()->all(),
                'checked_at' => HomeworkTime::toApi($assessment->assessed_at),
            ];
        }

        return $result;
    }

    private function names(Connection $db, string $table, string $key, string $name, Collection $ids): Collection
    {
        $ids = $ids->filter()->unique()->values()->all();

        return $ids ? $db->table($table)->whereIn($key, $ids)->pluck($name, $key) : collect();
    }

    private function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function average(Collection $values): ?float
    {
        $values = $values->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : round($values->avg(), 1);
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }
}
