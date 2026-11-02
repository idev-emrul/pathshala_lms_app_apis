<?php

namespace App\Services\HwAssignment;

use App\Exceptions\ApiException;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A student handing in homework. The server clock decides whether it is late.
 */
class SubmissionService
{
    private const EXTENSIONS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'pdf' => ['pdf'],
        'word' => ['doc', 'docx'],
        'excel' => ['xls', 'xlsx', 'csv'],
        'powerpoint' => ['ppt', 'pptx'],
        'audio' => ['mp3', 'm4a', 'wav', 'ogg', 'aac'],
        'video' => ['mp4', 'mov', 'webm', '3gp', 'mkv'],
    ];

    private const FILE_TYPES = [
        'image' => 'photo',
        'pdf' => 'pdf',
        'word' => 'document',
        'excel' => 'document',
        'powerpoint' => 'document',
        'audio' => 'audio',
        'video' => 'video',
    ];

    public function __construct(
        private SchoolDbService $schools,
        private HomeworkFiles $files,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $uploads
     */
    public function submit(HomeworkCaller $caller, int $studentId, int $homeworkId, ?string $text, ?string $link, array $uploads): void
    {
        if (! in_array($studentId, $caller->activeStudentIds, true)) {
            throw new ApiException('This student is not active', 403);
        }

        $db = $caller->db;
        $homework = $db->table('hw_assignments')->where('id', $homeworkId)->where('is_deleted', 0)->where('status', '<>', 'draft')->first();
        $row = $homework ? $db->table('hw_assignment_students')->where('assignment_id', $homeworkId)->where('student_id', $studentId)->first() : null;

        if (! $row) {
            throw new ApiException('Homework not found', 404);
        }

        $formats = $db->table('hw_assignment_formats')->where('assignment_id', $homeworkId)->pluck('max_file_size_mb', 'format');

        $this->ensureOpen($db, $homework, $row);
        $fileTypes = $this->validateInput($formats, $text, $link, $uploads);

        $stored = $this->files->store($db, $uploads, 'submission', "hws_{$homeworkId}_{$studentId}");

        try {
            $write = $this->schools->connectForHomework($caller->dbName);
            $write->transaction(fn () => $this->save($write, $homework, $row->id, $studentId, $text, $link, $stored, $fileTypes));
        } catch (Throwable $e) {
            $this->files->delete(array_column($stored, 'path'));

            throw $e;
        }
    }

    private function save(Connection $db, object $homework, int $rowId, int $studentId, ?string $text, ?string $link, array $stored, array $fileTypes): void
    {
        // Rechecked under a lock, so two taps cannot both become the same version.
        $row = $db->table('hw_assignment_students')->where('id', $rowId)->lockForUpdate()->first();
        $state = $this->ensureOpen($db, $homework, $row);
        $now = HomeworkTime::now();
        $version = $row->version_count + 1;

        $db->table('hw_submissions')->where('assignment_student_id', $rowId)->update(['is_latest' => 0]);

        $submissionId = $db->table('hw_submissions')->insertGetId([
            'assignment_student_id' => $rowId,
            'assignment_id' => $homework->id,
            'student_id' => $studentId,
            'version_no' => $version,
            'text_answer' => $text,
            'submitted_at' => $now,
            'is_late' => $state['is_late'] ? 1 : 0,
            'resubmission_request_id' => $state['request']?->id,
            'is_latest' => 1,
            'status' => 'submitted',
            'submitted_by' => null,
            'submitted_via' => 'app',
            'created_at' => $now,
        ]);

        $files = [];

        foreach ($stored as $index => $file) {
            $files[] = ['submission_id' => $submissionId, 'file_type' => $fileTypes[$index], 'file_name' => Str::limit($file['name'], 250, ''), 'file_path' => $file['path'], 'link' => null, 'file_size' => $file['size']];
        }

        if ($link) {
            $files[] = ['submission_id' => $submissionId, 'file_type' => 'link', 'file_name' => null, 'file_path' => null, 'link' => $link, 'file_size' => null];
        }

        if ($files) {
            $db->table('hw_submission_files')->insert($files);
        }

        $db->table('hw_assignment_students')->where('id', $rowId)->update([
            'status' => 'submitted',
            'is_late' => $state['is_late'] ? 1 : 0,
            'latest_submission_id' => $submissionId,
            'version_count' => $version,
            'first_submitted_at' => $row->first_submitted_at ?? $now,
        ]);

        if ($state['request']) {
            $db->table('hw_resubmission_requests')->where('id', $state['request']->id)->update(['status' => 'fulfilled']);
        }

        $db->table('hw_notification_events')->insert([
            'event_type' => $state['is_late'] ? 'late' : 'submitted',
            'assignment_id' => $homework->id,
            'assignment_student_id' => $rowId,
            'payload' => json_encode(['version_no' => $version, 'via' => 'app']),
            'occurred_at' => $now,
        ]);
    }

    private function ensureOpen(Connection $db, object $homework, object $row): array
    {
        if ($homework->status === 'closed') {
            throw new ApiException('This homework is closed');
        }

        $opensAt = $homework->assigned_date.' '.($homework->start_time ?: '00:00:00');

        if (! HomeworkTime::isPast($opensAt)) {
            throw new ApiException('This homework is not open yet');
        }

        $request = $db->table('hw_resubmission_requests')->where('assignment_student_id', $row->id)->where('status', 'open')->first();

        if ($row->version_count > 0 && ! $request) {
            if ($row->status === 'checked') {
                throw new ApiException('This homework is already checked');
            }

            if (! $homework->allow_resubmission) {
                throw new ApiException('This homework is already handed in');
            }
        }

        if ($homework->max_versions && $row->version_count >= $homework->max_versions) {
            throw new ApiException('The maximum number of versions has been reached');
        }

        $isLate = HomeworkTime::isPast($request->new_due_at ?? $homework->due_at);

        if ($isLate && ! $db->table('hw_settings')->value('allow_late_submission')) {
            throw new ApiException('The due time has passed and late work is not accepted');
        }

        return ['is_late' => $isLate, 'request' => $request];
    }

    /**
     * @return array<int, string> The hw_submission_files.file_type of each upload
     */
    private function validateInput($formats, ?string $text, ?string $link, array $uploads): array
    {
        $errors = [];
        $fileTypes = [];

        if (blank($text) && blank($link) && ! $uploads) {
            $errors['submission'] = 'Send text, a link or at least one file.';
        }

        if (! blank($text)) {
            if (! $formats->has('text')) {
                $errors['text'] = 'This homework does not accept text.';
            } elseif (mb_strlen($text) > 20000) {
                $errors['text'] = 'Text can be at most 20,000 characters.';
            }
        }

        if (! blank($link)) {
            if (! $formats->has('link')) {
                $errors['link'] = 'This homework does not accept links.';
            } elseif (! filter_var($link, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $link) || mb_strlen($link) > 1000) {
                $errors['link'] = 'Enter a valid http or https link.';
            }
        }

        if (count($uploads) > 10) {
            $errors['files'] = 'Send at most 10 files.';
        }

        foreach ($uploads as $index => $file) {
            $format = $this->formatOf($file);

            if (! $file->isValid()) {
                $errors['files'] = 'A file could not be uploaded.';
            } elseif (! $format || ! $formats->has($format)) {
                $errors['files'] = 'This homework does not accept '.$file->getClientOriginalName().'.';
            } elseif ($file->getSize() > ($formats[$format] ?: config('pathshala.homework.max_file_mb')) * 1024 * 1024) {
                $errors['files'] = $file->getClientOriginalName().' is too large.';
            } else {
                $fileTypes[$index] = self::FILE_TYPES[$format];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $fileTypes;
    }

    private function formatOf(UploadedFile $file): ?string
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        foreach (self::EXTENSIONS as $format => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $format;
            }
        }

        return null;
    }
}
