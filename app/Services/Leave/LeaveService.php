<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Module 11, leave applications, following the web system's slv_leave_model and leave_api_model.
 * An approved leave writes 3 (Leave) into attendance_info, only over cells that are empty or
 * absent, and every changed cell is kept for undo.
 */
class LeaveService
{
    private const PAGE_SIZE = 20;

    private const LEAVE_CODE = 3;

    private const REPLACEABLE_CODES = [0, 2];

    public function __construct(private LeaveSupport $support) {}

    public function overview(HomeworkCaller $caller, int $studentId, array $filters): array
    {
        $db = $caller->db;
        $this->support->settings($db);

        $year = (string) ($filters['year'] ?? substr(HomeworkTime::today(), 0, 4));
        $offset = $this->offsetFrom($filters['cursor'] ?? null);
        $typeId = null;

        if (! empty($filters['type'])) {
            $typeId = $this->support->findLookup($db, LeaveSupport::KIND_LEAVE_TYPE, $filters['type'])?->id
                ?? $this->support->fail(['type' => 'Unknown leave type. Use an id from types.']);
        }

        $filter = fn (Builder $query) => $query
            ->where('a.student_id', $studentId)
            ->whereYear('a.from_date', $year)
            ->when($filters['month'] ?? null, fn (Builder $q, $month) => $q->where(fn (Builder $inner) => $inner->whereMonth('a.from_date', $month)->orWhereMonth('a.to_date', $month)))
            ->when($typeId, fn (Builder $q) => $q->where('a.leave_type_id', $typeId))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('a.status', $status));

        $rows = $filter($this->rows($db))->orderByDesc('a.created_at')->orderByDesc('a.id')->offset($offset)->limit(self::PAGE_SIZE + 1)->get();
        $page = $rows->take(self::PAGE_SIZE);

        $data = [
            'year' => $year,
            'years' => $this->support->years($db, 'slv_applications', 'from_date', $studentId, $year),
            'types' => $this->support->lookups($db, LeaveSupport::KIND_LEAVE_TYPE),
        ];

        if ($offset === 0) {
            $data['summary'] = $this->summaryOf($filter($db->table('slv_applications as a')));
        }

        return $data + [
            'applications' => $page->map(fn (object $row) => $this->present($db, $row, true, false))->values()->all(),
            'next_cursor' => $rows->count() > self::PAGE_SIZE ? $this->cursor($offset + self::PAGE_SIZE) : null,
        ];
    }

    public function application(HomeworkCaller $caller, int $studentId, int $id): array
    {
        $this->support->settings($caller->db);

        $row = $this->rows($caller->db)->where('a.student_id', $studentId)->where('a.id', $id)->first()
            ?? throw new ApiException('Application not found.', 404);

        return $this->present($caller->db, $row);
    }

    public function apply(HomeworkCaller $caller, int $studentId, array $input, ?UploadedFile $file): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);
        $student = $this->support->student($db, $studentId) ?? throw new ApiException('Student not found.', 404);

        $type = $this->support->findLookup($db, LeaveSupport::KIND_LEAVE_TYPE, (string) $input['type_id']);
        $reason = $this->support->cleanText($input['reason'], 1000);
        $from = Carbon::parse($input['from']);
        $to = Carbon::parse($input['to']);
        $errors = [];

        if (! $type) {
            $errors['type_id'] = 'Unknown leave type. Use an id from types.';
        }

        if ($reason === '') {
            $errors['reason'] = 'Required.';
        }

        $today = Carbon::parse(HomeworkTime::today());

        if ($from->lt($today->copy()->subDays((int) $settings->slv_past_days))) {
            $errors['from'] = $settings->slv_past_days > 0 ? "Leave can start at most {$settings->slv_past_days} day(s) before today." : 'Leave cannot start before today.';
        } elseif ($from->gt($today->copy()->addDays((int) $settings->slv_future_days))) {
            $errors['from'] = "Leave can start at most {$settings->slv_future_days} day(s) from today.";
        }

        $days = 0;

        if (! $errors) {
            $days = $this->countDays($settings, $from, $to);
            // A type's own limit can only make the school's limit smaller.
            $max = $type->max_days ? min((int) $type->max_days, (int) $settings->slv_max_days) : (int) $settings->slv_max_days;

            if ($days < 1) {
                $errors['to'] = 'These dates have no school days.';
            } elseif ($days > $max) {
                $errors['to'] = "One application can cover at most {$max} day(s) for this leave type.";
            }
        }

        if ($problem = $this->support->attachmentProblem($settings, $file)) {
            $errors['attachment'] = $problem;
        } elseif (! $file && $type?->needs_attachment) {
            $errors['attachment'] = 'This leave type needs an attachment.';
        }

        if (! $errors && ($overlap = $this->findOverlap($db, $studentId, $from, $to))) {
            $errors['from'] = "These dates overlap {$overlap->application_no} ({$overlap->from_date} to {$overlap->to_date}, {$overlap->status}).";
        }

        if ($errors) {
            $this->support->fail($errors);
        }

        $stored = $this->support->storeAttachment($db, $file, 'leave', 'lv_'.$studentId);
        $writer = $this->support->writer($caller);
        $role = $caller->user->role;

        try {
            $id = $writer->transaction(function () use ($writer, $db, $student, $studentId, $type, $from, $to, $days, $reason, $stored, $role) {
                $saved = $this->support->insertNumbered($writer, 'slv_applications', 'application_no', 'LV', HomeworkTime::today(), [
                    'student_id' => $studentId,
                    'session_code' => $student->session_code,
                    'class_code' => $student->class_code,
                    'section_code' => $student->section_code,
                    'shift_code' => $student->shift_code,
                    'group_code' => $student->group_code,
                    'roll_number' => $student->roll_number,
                    'leave_type_id' => $type->id,
                    'from_date' => $from->toDateString(),
                    'to_date' => $to->toDateString(),
                    'total_days' => $days,
                    'reason' => $reason,
                    'attachment_path' => $stored['path'] ?? null,
                    'attachment_name' => $stored['name'] ?? null,
                    'attachment_size' => $stored['size'] ?? null,
                    'applied_by_type' => $role,
                    'applied_by_id' => null,
                    'applied_via' => 'app',
                    'status' => 'pending',
                    'created_at' => HomeworkTime::now(),
                ]);
                $id = $saved['id'];

                $this->support->log($writer, LeaveSupport::MODULE_LEAVE, $id, 'submitted', null, 'pending', "{$type->name_en}, {$from->toDateString()} to {$to->toDateString()}", $role, null);

                $text = "{$student->student_name} applied for {$type->name_en} leave {$this->support->shortDate($from->toDateString())} to {$this->support->fullDate($to->toDateString())} ({$days} day(s)). ID {$saved['number']}.";
                $this->support->notify($writer, LeaveSupport::MODULE_LEAVE, $id, 'submitted', 'guardian', $studentId, $student->contact_number, "$text Status: Pending.");

                foreach ($this->support->classTeacherCodes($db, $student) as $code) {
                    $this->support->notify($writer, LeaveSupport::MODULE_LEAVE, $id, 'new_request', 'teacher', $code, null, "New leave request: $text");
                }

                $this->support->notify($writer, LeaveSupport::MODULE_LEAVE, $id, 'new_request', 'office', null, null, "New leave request: $text");

                return $id;
            });
        } catch (Throwable $e) {
            $this->support->deleteAttachment($stored);

            throw $e;
        }

        return $this->application($caller, $studentId, $id);
    }

    public function withdraw(HomeworkCaller $caller, int $studentId, int $id, ?string $remarks): array
    {
        $settings = $this->support->settings($caller->db);

        $caller->db->table('slv_applications')->where('student_id', $studentId)->where('id', $id)->exists()
            ?: throw new ApiException('Application not found.', 404);

        if (! $settings->slv_allow_withdraw) {
            throw new ApiException('This school does not allow withdrawing an application.');
        }

        $writer = $this->support->writer($caller);

        $writer->transaction(function () use ($writer, $caller, $id, $remarks) {
            $changed = $writer->table('slv_applications')->where('id', $id)->where('status', 'pending')
                ->update(['status' => 'withdrawn', 'updated_at' => HomeworkTime::now()]);

            if (! $changed) {
                throw new ApiException('Only a pending application can be withdrawn.');
            }

            $this->support->log($writer, LeaveSupport::MODULE_LEAVE, $id, 'withdrawn', 'pending', 'withdrawn', $this->support->cleanText($remarks, 500), $caller->user->role, null);
        });

        return $this->application($caller, $studentId, $id);
    }

    public function reviewQueue(HomeworkCaller $caller, string $status): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);

        if ($settings->slv_sync_attendance) {
            $this->retryWaitingDays($caller, $settings);
        }

        $counts = $this->support->limitToClassTeacher($db->table('slv_applications as a'), $caller, 'a')
            ->selectRaw('a.status, COUNT(*) AS total')
            ->groupBy('a.status')
            ->pluck('total', 'status');

        $query = $this->support->limitToClassTeacher($this->rows($db), $caller, 'a')->where('a.status', $status);

        $status === 'pending'
            ? $query->orderBy('a.from_date')->orderBy('a.id')
            : $query->orderByDesc('a.decided_at')->orderByDesc('a.id');

        return [
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
            'applications' => $query->limit(500)->get()->map(fn (object $row) => $this->present($db, $row, false))->all(),
        ];
    }

    public function decide(HomeworkCaller $caller, int $id, string $decision, ?string $remarks): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);
        $row = $this->rows($db)->where('a.id', $id)->first() ?? throw new ApiException('Application not found.', 404);
        $isAdmin = $this->support->isAdmin($caller);

        if (! $isAdmin) {
            if ($settings->slv_reviewer_mode !== 'either') {
                throw new ApiException('Leave is decided by the school office in this school.', 403);
            }

            if (! $this->support->isClassTeacherOf($db, $caller->teacherCode, $row)) {
                throw new ApiException('This student is not in a section where you are class teacher.', 403);
            }
        }

        $remarks = $this->support->cleanText($remarks, 1000);

        if ($decision === 'reject' && $remarks === '') {
            $this->support->fail(['remarks' => 'Required when rejecting.']);
        }

        if ($row->status !== 'pending') {
            throw new ApiException('This application is already decided.');
        }

        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $byType = $isAdmin ? 'office' : 'teacher';
        $student = $this->support->student($db, (int) $row->student_id);
        $writer = $this->support->writer($caller);

        $writer->transaction(function () use ($writer, $settings, $caller, $row, $id, $status, $byType, $remarks, $student) {
            $changed = $writer->table('slv_applications')->where('id', $id)->where('status', 'pending')->update([
                'status' => $status,
                'decided_by' => $caller->userId,
                'decided_by_type' => $byType,
                'decided_at' => HomeworkTime::now(),
                'decision_remarks' => $remarks !== '' ? $remarks : null,
                'updated_at' => HomeworkTime::now(),
            ]);

            // Two reviewers can open the same application, and only the first decision counts.
            if (! $changed) {
                throw new ApiException('This application is already decided.');
            }

            $this->support->log($writer, LeaveSupport::MODULE_LEAVE, $id, $status, 'pending', $status, $remarks, $byType, $caller->userId);

            if ($status === 'approved' && $settings->slv_sync_attendance) {
                $done = $this->syncAttendance($writer, $settings, $row, (int) $caller->userId);

                $this->support->log($writer, LeaveSupport::MODULE_LEAVE, $id, 'attendance_synced', null, null,
                    "{$done['applied']} day(s) marked Leave, {$done['skipped']} skipped, {$done['waiting']} waiting for the month sheet", 'system', $caller->userId, 'system');
            }

            $message = "Leave {$row->application_no} for {$row->student_name} ({$this->support->shortDate($row->from_date)} to {$this->support->fullDate($row->to_date)}) was {$status}."
                .($remarks !== '' ? " Remarks: $remarks" : '');

            $this->support->notify($writer, LeaveSupport::MODULE_LEAVE, $id, $status, 'guardian', (int) $row->student_id, $student?->contact_number, $message);
            $this->support->notify($writer, LeaveSupport::MODULE_LEAVE, $id, $status, 'student', (int) $row->student_id, null, $message);
        });

        return $this->present($db, $this->rows($db)->where('a.id', $id)->first());
    }

    public function summary(HomeworkCaller $caller, string $year, ?int $month): array
    {
        $db = $caller->db;
        $this->support->settings($db);

        $base = fn () => $db->table('slv_applications as a')
            ->whereYear('a.from_date', $year)
            ->when($month, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->whereMonth('a.from_date', $month)->orWhereMonth('a.to_date', $month)));

        $totals = 'COUNT(*) AS total, SUM(a.status = "approved") AS approved, SUM(a.status = "pending") AS pending, SUM(a.status = "rejected") AS rejected, '
            .'SUM(CASE WHEN a.status = "approved" THEN a.total_days ELSE 0 END) AS leave_days';

        $line = fn (object $row) => [
            'total' => (int) $row->total,
            'approved' => (int) $row->approved,
            'pending' => (int) $row->pending,
            'rejected' => (int) $row->rejected,
            'leave_days' => $this->support->number($row->leave_days),
        ];

        return [
            'year' => $year,
            'month' => $month,
            'by_class' => $base()
                ->leftJoin('class_info as class', 'class.class_code', '=', 'a.class_code')
                ->leftJoin('section_info as section', 'section.section_code', '=', 'a.section_code')
                ->selectRaw("class.class_name, section.section_name, COUNT(DISTINCT a.student_id) AS students, $totals")
                ->groupBy('a.class_code', 'a.section_code', 'class.class_name', 'section.section_name', 'class.class_position', 'section.sec_position')
                ->orderBy('class.class_position')
                ->orderBy('section.sec_position')
                ->get()
                ->map(fn (object $row) => ['class_label' => $this->support->classLabel($row->class_name, $row->section_name), 'students' => (int) $row->students] + $line($row))
                ->all(),
            'by_type' => $base()
                ->leftJoin('sl_lookups as type', 'type.id', '=', 'a.leave_type_id')
                ->selectRaw("type.name_en, type.name_bn, $totals")
                ->groupBy('a.leave_type_id', 'type.name_en', 'type.name_bn')
                ->get()
                ->map(fn (object $row) => ['type' => ['id' => $this->support->slug($row->name_en), 'name' => $row->name_bn ?: (string) $row->name_en]] + $line($row))
                ->all(),
        ];
    }

    private function summaryOf(Builder $query): array
    {
        $row = $query->selectRaw('COUNT(*) AS total, IFNULL(SUM(a.status = "approved"), 0) AS approved, IFNULL(SUM(a.status = "pending"), 0) AS pending, '
            .'IFNULL(SUM(a.status = "rejected"), 0) AS rejected, IFNULL(SUM(CASE WHEN a.status = "approved" THEN a.total_days ELSE 0 END), 0) AS leave_days')->first();

        return [
            'total' => (int) $row->total,
            'approved' => (int) $row->approved,
            'pending' => (int) $row->pending,
            'rejected' => (int) $row->rejected,
            'leave_days' => $this->support->number($row->leave_days),
            'approved_percent' => (int) $row->total > 0 ? round($row->approved * 100 / $row->total, 1) : 0,
        ];
    }

    private function rows(Connection $db): Builder
    {
        return $db->table('slv_applications as a')
            ->leftJoin('sl_lookups as type', 'type.id', '=', 'a.leave_type_id')
            ->leftJoin('student_current_status_info as current', 'current.current_status_info_id', '=', 'a.student_id')
            ->leftJoin('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'a.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'a.section_code')
            ->select('a.*', 'type.name_en as type_en', 'type.name_bn as type_bn', 'student.student_name', 'class.class_name', 'section.section_name');
    }

    private function present(Connection $db, object $row, bool $full = true, bool $withStudent = true): array
    {
        $data = [
            'id' => (string) $row->id,
            'application_no' => $row->application_no,
            'type' => ['id' => $this->support->slug($row->type_en), 'name' => $row->type_bn ?: (string) $row->type_en],
            'from' => $row->from_date,
            'to' => $row->to_date,
            'days' => $this->support->number($row->total_days),
            'reason' => $row->reason,
            'applied_at' => HomeworkTime::toApi($row->created_at),
            'status' => $row->status,
            'remarks' => $row->status === 'pending' ? null : ($row->decision_remarks ?: null),
            'decided_at' => HomeworkTime::toApi($row->decided_at),
            'decided_by' => $this->support->userName($db, $row->decided_by),
        ] + $this->support->attachmentFields($db, $row) + [
            'history' => $full ? $this->history($db, $row) : [],
        ];

        if ($withStudent) {
            $data += [
                'student_id' => (string) $row->student_id,
                'student_name' => $row->student_name,
                'class_label' => $this->support->classLabel($row->class_name, $row->section_name),
                'roll' => (string) $row->roll_number,
            ];
        }

        return $data;
    }

    /**
     * Only submit, decide and withdraw events, oldest first.
     */
    private function history(Connection $db, object $row): array
    {
        $student = $this->support->student($db, (int) $row->student_id);

        return $db->table('sl_logs')->where('module', LeaveSupport::MODULE_LEAVE)->where('ref_id', $row->id)->orderBy('id')->get()
            ->map(function (object $log) use ($db, $row, $student) {
                $kind = $log->action === 'corrected' ? $log->to_status : $log->action;

                if (! in_array($kind, ['submitted', 'approved', 'rejected', 'withdrawn'], true)) {
                    return null;
                }

                return [
                    'kind' => $kind,
                    'at' => HomeworkTime::toApi($log->created_at),
                    'by' => match ($log->actor_type) {
                        'student' => $row->student_name,
                        'guardian' => $student ? $this->support->firstGuardianName($student) : 'Guardian',
                        'system' => 'System',
                        default => $this->support->userName($db, $log->actor_id) ?? ucfirst($log->actor_type),
                    },
                    'remarks' => $kind === 'submitted' ? null : ($log->remarks ?: null),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function countDays(object $settings, Carbon $from, Carbon $to): int
    {
        $days = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($settings->slv_day_count !== 'working' || ! $this->support->isOffDay($settings, $day)) {
                $days++;
            }
        }

        return $days;
    }

    private function findOverlap(Connection $db, int $studentId, Carbon $from, Carbon $to): ?object
    {
        return $db->table('slv_applications')
            ->where('student_id', $studentId)
            ->whereIn('status', ['pending', 'approved'])
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->first(['application_no', 'from_date', 'to_date', 'status']);
    }

    /**
     * Every day gets a row. A weekly off day is skipped, whichever way the school counts days.
     *
     * @return array{applied: int, skipped: int, waiting: int}
     */
    private function syncAttendance(Connection $writer, object $settings, object $row, int $userId): array
    {
        $done = ['applied' => 0, 'skipped' => 0, 'waiting' => 0];
        $to = Carbon::parse($row->to_date);

        for ($day = Carbon::parse($row->from_date); $day->lte($to); $day->addDay()) {
            $cell = $this->attendanceCell($writer, $settings, (int) $row->student_id, $day, $userId);

            $writer->table('slv_attendance_sync')->insert($cell + [
                'application_id' => $row->id,
                'student_id' => $row->student_id,
                'att_date' => $day->toDateString(),
                'created_at' => HomeworkTime::now(),
                'updated_at' => HomeworkTime::now(),
            ]);

            $done[$cell['state'] === 'waiting_month' ? 'waiting' : $cell['state']]++;
        }

        $writer->table('slv_applications')->where('id', $row->id)->update(['attendance_synced' => 1]);

        return $done;
    }

    /**
     * Changes one day to Leave if it is empty or absent. A day marked present or a holiday stays.
     */
    private function attendanceCell(Connection $writer, object $settings, int $studentId, Carbon $day, int $userId): array
    {
        $empty = ['attendance_id' => null, 'prev_value' => null, 'new_value' => null];

        if ($this->support->isOffDay($settings, $day)) {
            return $empty + ['state' => 'skipped'];
        }

        $column = 'd'.$day->day;

        $sheet = $writer->table('attendance_info')
            ->where('attendance_student_id', $studentId)
            ->where('attendance_month', $day->month)
            ->where('attendance_year', $day->year)
            ->lockForUpdate()
            ->first(['attendance_id', $column]);

        if (! $sheet) {
            return $empty + ['state' => 'waiting_month'];
        }

        $current = (int) $sheet->{$column};
        $previous = (string) $sheet->{$column};

        if (! in_array($current, self::REPLACEABLE_CODES, true)) {
            return ['attendance_id' => $sheet->attendance_id, 'prev_value' => $previous, 'new_value' => null, 'state' => 'skipped'];
        }

        $writer->table('attendance_info')->where('attendance_id', $sheet->attendance_id)->update([
            $column => self::LEAVE_CODE,
            'attendance_modifier' => $userId,
        ]);

        return ['attendance_id' => $sheet->attendance_id, 'prev_value' => $previous, 'new_value' => (string) self::LEAVE_CODE, 'state' => 'applied'];
    }

    /**
     * Days approved before the month's attendance sheet existed are written once it does.
     */
    private function retryWaitingDays(HomeworkCaller $caller, object $settings): void
    {
        $waiting = $caller->db->table('slv_attendance_sync as sync')
            ->join('slv_applications as a', function ($join) {
                $join->on('a.id', '=', 'sync.application_id')->where('a.status', 'approved');
            })
            ->join('attendance_info as sheet', function ($join) {
                $join->on('sheet.attendance_student_id', '=', 'sync.student_id')
                    ->whereRaw('sheet.attendance_month = MONTH(sync.att_date)')
                    ->whereRaw('sheet.attendance_year = YEAR(sync.att_date)');
            })
            ->where('sync.state', 'waiting_month')
            ->limit(200)
            ->get(['sync.id', 'sync.student_id', 'sync.att_date']);

        if ($waiting->isEmpty()) {
            return;
        }

        $writer = $this->support->writer($caller);

        foreach ($waiting as $row) {
            $writer->transaction(function () use ($writer, $settings, $caller, $row) {
                $cell = $this->attendanceCell($writer, $settings, (int) $row->student_id, Carbon::parse($row->att_date), (int) $caller->userId);

                $writer->table('slv_attendance_sync')->where('id', $row->id)->update($cell + ['updated_at' => HomeworkTime::now()]);
            });
        }
    }

    private function cursor(int $offset): string
    {
        return rtrim(strtr(base64_encode(json_encode(['o' => $offset])), '+/', '-_'), '=');
    }

    private function offsetFrom(?string $cursor): int
    {
        if (blank($cursor)) {
            return 0;
        }

        $json = json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);

        if (! is_array($json) || ! is_int($json['o'] ?? null) || $json['o'] < 0) {
            $this->support->fail(['cursor' => 'Not a cursor from this list.']);
        }

        return $json['o'];
    }
}
