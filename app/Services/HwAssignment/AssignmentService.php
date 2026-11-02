<?php

namespace App\Services\HwAssignment;

use App\Exceptions\ApiException;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creating, editing and deleting homework. Every field is checked first, so one bad field changes nothing.
 */
class AssignmentService
{
    private const FORMATS = ['text', 'image', 'pdf', 'document', 'link', 'audio', 'video'];
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    private const MAX_ATTACHMENTS = 10;

    public function __construct(
        private SchoolDbService $schools,
        private HomeworkReader $reader,
        private HomeworkFiles $files,
    ) {}

    public function create(HomeworkCaller $caller, Request $request): int
    {
        $values = $this->validated($caller, $request);
        $target = array_intersect_key($values['columns'], array_flip(['class_id', 'section_id', 'shift_id', 'group_id']));

        $sessionId = $this->targetSession($caller->db, $target);

        if (! $sessionId) {
            throw ValidationException::withMessages(['class_id' => 'This class has no active students in a running session.']);
        }

        $settings = $caller->db->table('hw_settings')->first();
        $uploads = $values['uploads'];
        $stored = $this->files->store($caller->db, $uploads, 'assignment', 'hwa_'.$caller->userId);

        try {
            $db = $this->schools->connectForHomework($caller->dbName);

            return $db->transaction(function () use ($db, $caller, $values, $settings, $sessionId, $stored) {
                $now = HomeworkTime::now();

                $homeworkId = $db->table('hw_assignments')->insertGetId($values['columns'] + [
                    'institute_code' => $settings->institute_code ?? 1,
                    'academic_session_id' => $sessionId,
                    'teacher_id' => $caller->teacherCode,
                    'allow_resubmission' => $settings?->allow_free_resubmission ? 1 : 0,
                    'max_versions' => null,
                    'notify_on_publish' => 1,
                    'status' => 'draft',
                    'is_deleted' => 0,
                    'created_by' => $caller->userId,
                    'created_at' => $now,
                ]);

                $this->saveFormats($db, $homeworkId, $values['formats']);
                $this->saveRubric($db, $homeworkId, $values['rubric'] ?? []);
                $this->saveAttachments($db, $homeworkId, $stored, $caller->userId);

                $homework = $db->table('hw_assignments')->where('id', $homeworkId)->first();
                $this->publish($db, $homework, $sessionId);

                return $homeworkId;
            });
        } catch (Throwable $e) {
            $this->files->delete(array_column($stored, 'path'));

            throw $e;
        }
    }

    public function update(HomeworkCaller $caller, object $homework, Request $request): void
    {
        $values = $this->validated($caller, $request, $homework);
        $removeIds = array_map('intval', $this->listInput($request, 'remove_attachment_ids'));
        $publish = $request->boolean('publish');

        if (! $values['columns'] && $values['formats'] === null && $values['rubric'] === null && ! $values['uploads'] && ! $removeIds && ! $publish) {
            throw ValidationException::withMessages(['homework' => 'Send at least one field to change.']);
        }

        $db = $caller->db;
        $existing = $db->table('hw_assignment_attachments')->where('assignment_id', $homework->id)->where('type', 'attachment')->get();
        $removing = $existing->whereIn('id', $removeIds);

        if (count($removeIds) !== $removing->count()) {
            throw ValidationException::withMessages(['remove_attachment_ids' => 'One of these attachments does not belong to this homework.']);
        }

        if ($existing->count() - $removing->count() + count($values['uploads']) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['files' => 'A homework can have at most '.self::MAX_ATTACHMENTS.' attachments.']);
        }

        $sessionId = null;

        if ($publish && $homework->status === 'draft') {
            $target = array_intersect_key($values['columns'] + (array) $homework, array_flip(['class_id', 'section_id', 'shift_id', 'group_id']));
            $sessionId = $this->targetSession($db, $target);

            if (! $sessionId) {
                throw ValidationException::withMessages(['class_id' => 'This class has no active students in a running session.']);
            }
        }

        $stored = $this->files->store($db, $values['uploads'], 'assignment', 'hwa_'.$caller->userId);

        try {
            $write = $this->schools->connectForHomework($caller->dbName);

            $write->transaction(function () use ($write, $caller, $homework, $values, $stored, $removing, $sessionId) {
                $now = HomeworkTime::now();

                $write->table('hw_assignments')->where('id', $homework->id)->update($values['columns'] + [
                    'updated_by' => $caller->userId,
                    'updated_at' => $now,
                ]);

                if ($values['formats'] !== null) {
                    $write->table('hw_assignment_formats')->where('assignment_id', $homework->id)->delete();
                    $this->saveFormats($write, $homework->id, $values['formats']);
                }

                if ($values['rubric'] !== null) {
                    $write->table('hw_assignment_rubric_criteria')->where('assignment_id', $homework->id)->delete();
                    $this->saveRubric($write, $homework->id, $values['rubric']);
                }

                $write->table('hw_assignment_attachments')->whereIn('id', $removing->pluck('id')->all() ?: [0])->delete();
                $this->saveAttachments($write, $homework->id, $stored, $caller->userId);

                if ($sessionId) {
                    $write->table('hw_assignments')->where('id', $homework->id)->update(['academic_session_id' => $sessionId]);
                    $this->publish($write, $write->table('hw_assignments')->where('id', $homework->id)->first(), $sessionId);
                }
            });
        } catch (Throwable $e) {
            $this->files->delete(array_column($stored, 'path'));

            throw $e;
        }

        $this->files->delete($removing->pluck('file_path')->filter()->all());
    }

    /**
     * A soft delete, as on the web. Refused once a student has handed in, so their work is never lost.
     */
    public function delete(HomeworkCaller $caller, object $homework): void
    {
        $db = $this->schools->connectForHomework($caller->dbName);

        $db->transaction(function () use ($db, $caller, $homework) {
            $db->table('hw_assignments')->where('id', $homework->id)->lockForUpdate()->first();

            if ($db->table('hw_assignment_students')->where('assignment_id', $homework->id)->where('version_count', '>', 0)->exists()) {
                throw new ApiException('A student has already submitted this homework, so it cannot be deleted.', 409);
            }

            $db->table('hw_assignments')->where('id', $homework->id)->update([
                'is_deleted' => 1,
                'deleted_by' => $caller->userId,
                'deleted_at' => HomeworkTime::now(),
            ]);
        });
    }

    private function publish(Connection $db, object $homework, int $sessionId): void
    {
        $now = HomeworkTime::now();

        $students = $this->targetStudents($db, (array) $homework, $sessionId)->pluck('current_status_info_id');

        foreach ($students->chunk(500) as $chunk) {
            $db->table('hw_assignment_students')->insert($chunk->map(fn ($studentId) => [
                'assignment_id' => $homework->id,
                'student_id' => $studentId,
                'status' => 'pending',
                'is_late' => 0,
                'version_count' => 0,
                'created_at' => $now,
            ])->values()->all());
        }

        $db->table('hw_assignments')->where('id', $homework->id)->update(['status' => 'published', 'published_at' => $now]);

        $db->table('hw_notification_events')->insert([
            'event_type' => 'assigned',
            'assignment_id' => $homework->id,
            'assignment_student_id' => null,
            'payload' => json_encode(['title' => $homework->title, 'due_at' => $homework->due_at], JSON_UNESCAPED_UNICODE),
            'occurred_at' => $now,
        ]);
    }

    /**
     * Several sessions can be running at once, so the newest one with students in this class wins.
     */
    private function targetSession(Connection $db, array $target): ?int
    {
        $running = $db->table('session_info')->where('running_session', 1)->orderByDesc('session_code')->pluck('session_code');

        foreach ($running as $sessionId) {
            if ($this->targetStudents($db, $target, $sessionId)->isNotEmpty()) {
                return (int) $sessionId;
            }
        }

        return null;
    }

    private function targetStudents(Connection $db, array $target, int $sessionId)
    {
        return $db->table('student_current_status_info')
            ->where('session_code', $sessionId)
            ->where('class_code', $target['class_id'])
            ->where('active_status', 1)
            ->when($target['section_id'] ?? null, fn ($query, $value) => $query->where('section_code', $value))
            ->when($target['shift_id'] ?? null, fn ($query, $value) => $query->where('shift_code', $value))
            ->when($target['group_id'] ?? null, fn ($query, $value) => $query->where('group_code', $value))
            ->get(['current_status_info_id']);
    }

    private function saveFormats(Connection $db, int $homeworkId, array $formats): void
    {
        $rows = [];

        foreach ($formats as $format) {
            foreach ($format === 'document' ? ['word', 'excel', 'powerpoint'] : [$format] as $stored) {
                $rows[] = ['assignment_id' => $homeworkId, 'format' => $stored];
            }
        }

        $db->table('hw_assignment_formats')->insert($rows);
    }

    private function saveRubric(Connection $db, int $homeworkId, array $rubric): void
    {
        foreach (array_values($rubric) as $index => $criterion) {
            $db->table('hw_assignment_rubric_criteria')->insert([
                'assignment_id' => $homeworkId,
                'criteria_name' => $criterion['name'],
                'max_marks' => $criterion['max'],
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function saveAttachments(Connection $db, int $homeworkId, array $stored, int $userId): void
    {
        foreach ($stored as $file) {
            $db->table('hw_assignment_attachments')->insert([
                'assignment_id' => $homeworkId,
                'type' => 'attachment',
                'file_name' => Str::limit($file['name'], 250, ''),
                'file_path' => $file['path'],
                'file_size' => $file['size'],
                'created_by' => $userId,
                'created_at' => HomeworkTime::now(),
            ]);
        }
    }

    /**
     * @return array{columns: array, formats: ?array, rubric: ?array, uploads: array<int, UploadedFile>}
     */
    private function validated(HomeworkCaller $caller, Request $request, ?object $homework = null): array
    {
        $db = $caller->db;
        $creating = $homework === null;
        $errors = [];
        $columns = [];
        $has = fn (string $field) => $creating || $request->has($field);

        if (! $creating && $homework->status === 'closed') {
            throw new ApiException('This homework is closed');
        }

        // Only the caller's own code can be sent, and it is what a new homework gets anyway.
        if ($request->filled('teacher_id') && (int) $request->input('teacher_id') !== (int) $caller->teacherCode) {
            throw new ApiException('You can only set yourself as the teacher', 403);
        }

        if (! $creating && $request->has('teacher_id')) {
            $columns['teacher_id'] = $request->filled('teacher_id') ? $caller->teacherCode : null;
        }

        foreach (['title' => 255, 'chapter' => 255] as $field => $max) {
            if ($has($field)) {
                $value = trim((string) $request->input($field));

                if ($field === 'title' && $value === '') {
                    $errors[$field] = 'Required.';
                } elseif (mb_strlen($value) > $max) {
                    $errors[$field] = "At most $max characters.";
                } else {
                    $columns[$field === 'chapter' ? 'chapter_topic' : 'title'] = $value === '' ? null : $value;
                }
            }
        }

        if ($has('description')) {
            $value = trim((string) $request->input('description'));

            if ($value === '') {
                $errors['description'] = 'Required.';
            } else {
                $columns['description'] = $value;
            }
        }

        if ($has('type')) {
            $types = $this->reader->activeTypes($db);
            $type = $request->input('type');
            $match = $types->first(fn ($row) => (string) $row->id === (string) $type || $this->reader->slug($row->type_name) === $type);

            if ($match) {
                $columns['assignment_type_id'] = $match->id;
            } else {
                $errors['type'] = blank($type) ? 'Required.' : 'Unknown type.';
            }
        }

        $this->validateTarget($caller, $request, $homework, $columns, $errors);
        $this->validateDates($request, $creating, $columns, $errors);

        $finalTotal = $creating ? null : (float) $homework->total_marks;

        if ($has('total_marks')) {
            $total = $request->input('total_marks');

            if (! is_numeric($total) || $total <= 0) {
                $errors['total_marks'] = 'Must be more than 0.';
            } else {
                $finalTotal = (float) $total;
                $columns['total_marks'] = $finalTotal;
            }
        }

        if ($request->has('passing_marks')) {
            $passing = $request->input('passing_marks');

            if (blank($passing)) {
                $columns['passing_marks'] = null;
            } elseif (! is_numeric($passing) || $passing < 0 || ($finalTotal !== null && $passing > $finalTotal)) {
                $errors['passing_marks'] = 'Must be from 0 to total_marks.';
            } else {
                $columns['passing_marks'] = (float) $passing;
            }
        } elseif (! $creating && $homework->passing_marks !== null && $finalTotal !== null && $homework->passing_marks > $finalTotal) {
            $errors['passing_marks'] = 'Must be from 0 to total_marks.';
        }

        if ($request->has('priority')) {
            if (in_array($request->input('priority'), ['low', 'medium', 'high'], true)) {
                $columns['priority'] = $request->input('priority');
            } else {
                $errors['priority'] = 'Use low, medium or high.';
            }
        } elseif ($creating) {
            $columns['priority'] = 'medium';
        }

        $formats = null;

        if ($has('allowed_formats')) {
            $formats = array_values(array_unique($this->listInput($request, 'allowed_formats')));

            if (! $formats) {
                $errors['allowed_formats'] = 'Choose at least one format.';
            } elseif (array_diff($formats, self::FORMATS)) {
                $errors['allowed_formats'] = 'Unknown format.';
            }
        }

        $rubric = $this->validateRubric($db, $request, $homework, $finalTotal, $columns, $errors);

        $uploads = $this->uploads($request, $errors);

        if (! $creating) {
            $this->validateLocks($db, $homework, $columns, $rubric, $errors);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['columns' => $columns, 'formats' => $formats, 'rubric' => $rubric, 'uploads' => $uploads];
    }

    private function validateTarget(HomeworkCaller $caller, Request $request, ?object $homework, array &$columns, array &$errors): void
    {
        $creating = $homework === null;
        $fields = ['class_id', 'section_id', 'subject_id'];

        foreach (array_merge($fields, ['shift_id', 'group_id']) as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);

                if (! blank($value) && ! ctype_digit((string) $value)) {
                    $errors[$field] = 'Must be a number.';
                } else {
                    $columns[$field] = blank($value) ? null : (int) $value;
                }
            }
        }

        foreach (['class_id', 'subject_id'] as $field) {
            if (($creating || $request->has($field)) && empty($columns[$field]) && ! isset($errors[$field])) {
                $errors[$field] = 'Required.';
            }
        }

        if (isset($errors['class_id']) || isset($errors['subject_id']) || isset($errors['section_id'])) {
            return;
        }

        // Only what the teacher changes is checked, so homework made on the web can still be edited.
        if (! $creating && ! array_intersect_key($columns, array_flip($fields))) {
            return;
        }

        $classId = $columns['class_id'] ?? $homework->class_id;
        $subjectId = $columns['subject_id'] ?? $homework->subject_id;
        $sectionId = array_key_exists('section_id', $columns) ? $columns['section_id'] : ($homework->section_id ?? null);

        $option = collect($this->reader->teachingOptions($caller))->firstWhere('id', (string) $classId);

        if (! $option) {
            $errors['class_id'] = 'Choose one of your classes.';

            return;
        }

        if (! collect($option['subjects'])->contains('id', (string) $subjectId)) {
            $errors['subject_id'] = 'Choose one of your subjects for this class.';
        }

        if ($sectionId && ! collect($option['sections'])->contains('id', (string) $sectionId)) {
            $errors['section_id'] = 'Choose one of your sections for this class.';
        }
    }

    private function validateDates(Request $request, bool $creating, array &$columns, array &$errors): void
    {
        $isDate = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $dueAt = null;

        if ($request->filled('due_at')) {
            if (! is_string($request->input('due_at')) || strtotime($request->input('due_at')) === false) {
                $errors['due_at'] = 'Use a UTC date-time such as 2026-09-21T04:00:00Z.';
            } else {
                $dueAt = HomeworkTime::fromApi($request->input('due_at'));
            }
        } elseif ($request->filled('due_date')) {
            $minutes = $request->input('due_minutes');

            if (! $isDate($request->input('due_date')) || ! is_numeric($minutes) || $minutes < 0 || $minutes >= 1440) {
                $errors['due_date'] = 'Send due_date as YYYY-MM-DD and due_minutes from 0 to 1439.';
            } else {
                $dueAt = HomeworkTime::fromDateAndMinutes($request->input('due_date'), (int) $minutes);
            }
        } elseif ($creating) {
            $errors['due_at'] = 'Required.';
        }

        if ($dueAt) {
            if (HomeworkTime::isPast($dueAt)) {
                $errors['due_at'] = 'Must be in the future.';
            } else {
                $columns['due_at'] = $dueAt;
            }
        }

        if ($request->filled('assigned_date')) {
            if ($isDate($request->input('assigned_date'))) {
                $columns['assigned_date'] = $request->input('assigned_date');
            } else {
                $errors['assigned_date'] = 'Use YYYY-MM-DD.';
            }
        } elseif ($creating) {
            $columns['assigned_date'] = HomeworkTime::today();
        }

        if ($request->has('start_time')) {
            $time = $request->input('start_time');

            if (blank($time)) {
                $columns['start_time'] = null;
            } elseif (is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $columns['start_time'] = $time.':00';
            } else {
                $errors['start_time'] = 'Use HH:MM.';
            }
        }
    }

    /**
     * Sets assessment_mode. Returns null when the rubric is not being changed.
     */
    private function validateRubric(Connection $db, Request $request, ?object $homework, ?float $finalTotal, array &$columns, array &$errors): ?array
    {
        $creating = $homework === null;
        $settings = $db->table('hw_settings')->first();
        $rubric = null;

        if ($request->has('rubric') || $creating) {
            $raw = $request->input('rubric', []);
            $raw = is_string($raw) ? (json_decode($raw, true) ?? []) : $raw;
            $rubric = [];
            $names = [];

            foreach ((array) $raw as $criterion) {
                $name = trim((string) ($criterion['name'] ?? ''));
                $max = $criterion['max'] ?? null;

                if ($name === '' || mb_strlen($name) > 150 || ! is_numeric($max) || $max <= 0) {
                    $errors['rubric'] = 'Each criterion needs a name and a max above 0.';
                } elseif (isset($names[Str::lower($name)])) {
                    $errors['rubric'] = "The criterion $name is listed twice.";
                } else {
                    $names[Str::lower($name)] = true;
                    $rubric[] = ['name' => $name, 'max' => (float) $max];
                }
            }

            $sum = array_sum(array_column($rubric, 'max'));

            if ($rubric && ! isset($errors['rubric']) && $finalTotal !== null && abs($sum - $finalTotal) > 0.001) {
                $errors['rubric'] = 'The max values add up to '.$this->reader->number($sum).' but total_marks is '.$this->reader->number($finalTotal).'.';
            }

            if ($settings && ! $settings->allow_mode_override) {
                if ($settings->default_assessment_mode === 'rubric' && ! $rubric) {
                    $errors['rubric'] = 'This school requires a rubric.';
                } elseif ($settings->default_assessment_mode !== 'rubric' && $rubric) {
                    $errors['rubric'] = 'This school does not use rubrics.';
                }
            }

            $columns['assessment_mode'] = $rubric ? 'rubric' : 'simple';
        } elseif ($homework->assessment_mode === 'rubric' && isset($columns['total_marks'])) {
            $sum = (float) $db->table('hw_assignment_rubric_criteria')->where('assignment_id', $homework->id)->sum('max_marks');

            if (abs($sum - $columns['total_marks']) > 0.001) {
                $errors['total_marks'] = 'Send a matching rubric with total_marks.';
            }
        }

        return $rubric;
    }

    private function validateLocks(Connection $db, object $homework, array $columns, ?array $rubric, array &$errors): void
    {
        if ($homework->status !== 'draft') {
            foreach (['class_id', 'section_id', 'shift_id', 'group_id'] as $field) {
                if (array_key_exists($field, $columns) && (int) $columns[$field] !== (int) $homework->{$field}) {
                    $errors[$field] = 'This cannot change once the homework is published.';
                }
            }
        }

        if ($db->table('hw_assignment_students')->where('assignment_id', $homework->id)->where('status', 'checked')->exists()) {
            $totalChanged = isset($columns['total_marks']) && abs($columns['total_marks'] - (float) $homework->total_marks) > 0.001;
            $modeChanged = isset($columns['assessment_mode']) && $columns['assessment_mode'] !== $homework->assessment_mode;

            if ($totalChanged) {
                $errors['total_marks'] = 'This cannot change once something is assessed.';
            }

            if ($rubric !== null || $modeChanged) {
                $errors['rubric'] = 'This cannot change once something is assessed.';
            }
        }
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function uploads(Request $request, array &$errors): array
    {
        $files = array_values(array_filter((array) $request->file('files')));

        if (count($files) > self::MAX_ATTACHMENTS) {
            $errors['files'] = 'Send at most '.self::MAX_ATTACHMENTS.' files.';
        }

        foreach ($files as $file) {
            if (! $file->isValid()) {
                $errors['files'] = 'A file could not be uploaded.';
            } elseif (! in_array(Str::lower($file->getClientOriginalExtension()), self::ATTACHMENT_EXTENSIONS, true)) {
                $errors['files'] = $file->getClientOriginalName().' is not an accepted file type.';
            } elseif ($file->getSize() > config('pathshala.homework.max_file_mb') * 1024 * 1024) {
                $errors['files'] = $file->getClientOriginalName().' is too large.';
            }
        }

        return $files;
    }

    /**
     * A list can come as a JSON array, repeated name[] fields or a,b,c.
     */
    private function listInput(Request $request, string $field): array
    {
        $value = $request->input($field, []);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        return array_values(array_filter(array_map('trim', array_map('strval', (array) $value)), fn ($item) => $item !== ''));
    }
}
