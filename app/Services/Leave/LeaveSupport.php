<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkFiles;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What leave and early leave share. The web system's pages read the same rows, so log actions,
 * notification events and stored values follow its slv_common_model and leave_api_model.
 * Students are current_status_info_id here, like in homework.
 */
class LeaveSupport
{
    public const KIND_LEAVE_TYPE = 'leave_type';
    public const KIND_EL_REASON = 'el_reason';

    public const MODULE_LEAVE = 'slv';
    public const MODULE_EARLY = 'el';

    private const GUARDIANS = [
        'father' => ['column' => 'father_name', 'relation' => 'Father', 'relation_bn' => 'পিতা'],
        'mother' => ['column' => 'mother_name', 'relation' => 'Mother', 'relation_bn' => 'মাতা'],
        'guardian' => ['column' => 'guardian_name', 'relation' => 'Guardian', 'relation_bn' => 'অভিভাবক'],
    ];

    public function __construct(
        private SchoolDbService $schools,
        private HomeworkFiles $files,
    ) {}

    public function settings(Connection $db): object
    {
        $settings = $db->getSchemaBuilder()->hasTable('sl_settings') ? $db->table('sl_settings')->first() : null;

        return $settings ?? throw new ApiException('The leave module is not set up for this school yet.', 503, errorCode: 'not_set_up');
    }

    public function writer(HomeworkCaller $caller): Connection
    {
        return $this->schools->connectForLeave($caller->dbName);
    }

    public function isAdmin(HomeworkCaller $caller): bool
    {
        return $caller->user->role === AppUser::ROLE_ADMIN;
    }

    public function isGuardian(HomeworkCaller $caller): bool
    {
        return $caller->user->role === AppUser::ROLE_GUARDIAN;
    }

    public function lookups(Connection $db, string $kind): array
    {
        return $db->table('sl_lookups')
            ->where('kind', $kind)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (object $row) => ['id' => $this->slug($row->name_en), 'name' => $row->name_bn ?: $row->name_en])
            ->all();
    }

    /**
     * Finds a type or reason from its slug, or from its numeric id.
     */
    public function findLookup(Connection $db, string $kind, string $value): ?object
    {
        $value = strtolower(trim($value));

        return $db->table('sl_lookups')
            ->where('kind', $kind)
            ->where('is_active', 1)
            ->get()
            ->first(fn (object $row) => $value !== '' && ($this->slug($row->name_en) === $value || (string) $row->id === $value));
    }

    public function slug(?string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $name)), '_');

        return $slug === 'medical_appointment' ? 'medical' : $slug;
    }

    public function student(Connection $db, int $studentId): ?object
    {
        return $db->table('student_current_status_info as current')
            ->leftJoin('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'current.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'current.section_code')
            ->where('current.current_status_info_id', $studentId)
            ->first([
                'current.current_status_info_id as student_id', 'current.session_code', 'current.class_code', 'current.section_code',
                'current.shift_code', 'current.group_code', 'current.roll_number',
                'student.student_name', 'student.father_name', 'student.mother_name', 'student.guardian_name', 'student.contact_number',
                'class.class_name', 'section.section_name',
            ]);
    }

    /**
     * The people on the student's record, by key. All of them share one contact number.
     *
     * @return array<string, array{name: string, relation: string, relation_bn: string, mobile: string}>
     */
    public function guardians(object $student): array
    {
        $list = [];

        foreach (self::GUARDIANS as $key => $guardian) {
            $name = trim((string) ($student->{$guardian['column']} ?? ''));

            if ($name !== '') {
                $list[$key] = [
                    'name' => $name,
                    'relation' => $guardian['relation'],
                    'relation_bn' => $guardian['relation_bn'],
                    'mobile' => trim((string) $student->contact_number),
                ];
            }
        }

        return $list;
    }

    public function firstGuardianName(object $student): string
    {
        return array_values($this->guardians($student))[0]['name'] ?? 'Guardian';
    }

    public function maskMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        if (strlen($digits) < 8) {
            return $mobile ?: null;
        }

        return substr($digits, 0, 5).'-XXX-'.substr($digits, -3);
    }

    /**
     * An admin sees everything, and a teacher only the sections where they are class teacher.
     * A class_teacher_info row with no session or shift counts for every session or shift.
     */
    public function limitToClassTeacher(Builder $query, HomeworkCaller $caller, string $alias): Builder
    {
        if ($this->isAdmin($caller)) {
            return $query;
        }

        if (! $caller->teacherCode) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(fn (Builder $exists) => $exists
            ->selectRaw('1')
            ->from('class_teacher_info as ct')
            ->where('ct.teacher_code', $caller->teacherCode)
            ->whereColumn('ct.class_code', "$alias.class_code")
            ->whereColumn('ct.section_code', "$alias.section_code")
            ->where(fn (Builder $q) => $q->whereNull('ct.session_code')->orWhere('ct.session_code', 0)->orWhereColumn('ct.session_code', "$alias.session_code"))
            ->where(fn (Builder $q) => $q->whereNull('ct.shift_code')->orWhere('ct.shift_code', 0)->orWhereColumn('ct.shift_code', "$alias.shift_code")));
    }

    public function isClassTeacherOf(Connection $db, ?int $teacherCode, object $row): bool
    {
        return $teacherCode && $this->classTeachers($db, $row)->where('ct.teacher_code', $teacherCode)->exists();
    }

    public function classTeacherCodes(Connection $db, object $row): array
    {
        return $this->classTeachers($db, $row)->distinct()->pluck('ct.teacher_code')->map(fn ($code) => (int) $code)->all();
    }

    private function classTeachers(Connection $db, object $row): Builder
    {
        return $db->table('class_teacher_info as ct')
            ->where('ct.class_code', $row->class_code)
            ->where('ct.section_code', $row->section_code)
            ->where(fn (Builder $q) => $q->whereNull('ct.session_code')->orWhere('ct.session_code', 0)->orWhere('ct.session_code', $row->session_code))
            ->where(fn (Builder $q) => $q->whereNull('ct.shift_code')->orWhere('ct.shift_code', 0)->orWhere('ct.shift_code', $row->shift_code));
    }

    public function log(Connection $writer, string $module, int $refId, string $action, ?string $from, ?string $to, ?string $remarks, string $actorType, ?int $actorId, string $via = 'app'): void
    {
        $writer->table('sl_logs')->insert([
            'module' => $module,
            'ref_id' => $refId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks !== '' ? $remarks : null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'via' => $via,
            'ip' => request()->ip(),
            'created_at' => HomeworkTime::now(),
        ]);
    }

    /**
     * Only queued here. A sender still has to read this table.
     */
    public function notify(Connection $writer, string $module, int $refId, string $event, string $type, ?int $recipientId, ?string $mobile, string $message): void
    {
        $writer->table('sl_notifications')->insert([
            'module' => $module,
            'ref_id' => $refId,
            'event' => $event,
            'recipient_type' => $type,
            'recipient_id' => $recipientId,
            'mobile' => $mobile ?: null,
            'channel' => $mobile ? 'sms' : 'inapp',
            'message' => $message,
            'status' => 'queued',
            'created_at' => HomeworkTime::now(),
        ]);
    }

    /**
     * Inserts with the next PREFIX-YYYY-MM-DD-NNN, trying again if another request took that number.
     */
    public function insertNumbered(Connection $writer, string $table, string $column, string $prefix, string $date, array $data): array
    {
        $base = "{$prefix}-{$date}-";

        for ($try = 1; ; $try++) {
            $next = (int) $writer->table($table)->where($column, 'like', $base.'%')
                ->selectRaw("MAX(CAST(SUBSTRING($column, ?) AS UNSIGNED)) AS n", [strlen($base) + 1])
                ->value('n') + 1;
            $number = $base.str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            try {
                return ['id' => $writer->table($table)->insertGetId($data + [$column => $number]), 'number' => $number];
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return string|null What is wrong with the file.
     */
    public function attachmentProblem(object $settings, ?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        $types = array_map('trim', explode(',', strtolower((string) $settings->attachment_types)));

        if (! in_array(strtolower($file->getClientOriginalExtension()), $types, true)) {
            return 'Attachment must be one of: '.strtoupper(implode(', ', $types)).'.';
        }

        if ($file->getSize() > $settings->attachment_max_kb * 1024) {
            return 'Attachment is larger than '.round($settings->attachment_max_kb / 1024, 1).' MB.';
        }

        return null;
    }

    public function storeAttachment(Connection $db, ?UploadedFile $file, string $folder, string $prefix): ?array
    {
        return $file ? $this->files->store($db, [$file], $folder, $prefix, 'student_leave')[0] : null;
    }

    public function deleteAttachment(?array $stored): void
    {
        if ($stored) {
            $this->files->delete([$stored['path']]);
        }
    }

    public function attachmentFields(Connection $db, object $row): array
    {
        return [
            'attachment_name' => $row->attachment_name ?: null,
            'attachment_url' => $row->attachment_path ? $this->files->urlResolver($db)($row->attachment_path) : null,
        ];
    }

    public function userName(Connection $db, mixed $userId): ?string
    {
        $user = $userId ? $db->table('users')->where('id', $userId)->first(['username', 'user_full_name']) : null;

        return $user ? ($user->user_full_name ?: $user->username) : null;
    }

    public function isOffDay(object $settings, Carbon $date): bool
    {
        return in_array((string) $date->dayOfWeek, array_map('trim', explode(',', (string) $settings->weekly_off_days)), true);
    }

    /**
     * Years with rows, and the one asked for, newest first and as strings.
     */
    public function years(Connection $db, string $table, string $dateColumn, int $studentId, string $year): array
    {
        return $db->table($table)->where('student_id', $studentId)->selectRaw("DISTINCT YEAR($dateColumn) AS y")->pluck('y')
            ->map(fn ($value) => (string) $value)
            ->push($year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public function number(mixed $value): int|float
    {
        return (float) $value == (int) $value ? (int) $value : (float) $value;
    }

    public function classLabel(?string $className, ?string $sectionName): string
    {
        return trim($className.($sectionName ? ' '.$sectionName : ''));
    }

    public function cleanText(?string $text, int $length): string
    {
        return mb_substr(trim(strip_tags((string) $text)), 0, $length);
    }

    public function shortDate(string $date): string
    {
        return Carbon::parse($date)->format('d M');
    }

    public function fullDate(string $date): string
    {
        return Carbon::parse($date)->format('d M Y');
    }

    public function clock(string $time): string
    {
        return Carbon::parse($time)->format('h:i A');
    }

    public function fail(array $errors): never
    {
        throw ValidationException::withMessages($errors);
    }
}
