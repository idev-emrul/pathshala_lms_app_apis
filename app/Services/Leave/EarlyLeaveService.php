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
 * Module 12, early leave, following the web system's el_leave_model and leave_api_model: parent,
 * then class teacher, then the office if the school asks for it. The last approval gives the
 * guardian a release code, which the gate checks.
 */
class EarlyLeaveService
{
    private const PENDING = ['pending_parent', 'pending_teacher', 'pending_final'];

    private const OPEN = [...self::PENDING, 'approved'];

    public function __construct(private LeaveSupport $support) {}

    /**
     * Pending requests past their leave time expire on the next early leave call, as there is no cron.
     */
    public function expireOld(HomeworkCaller $caller): void
    {
        $this->support->settings($caller->db);

        $old = $caller->db->table('el_requests')
            ->whereIn('status', self::PENDING)
            ->where('expires_at', '<', HomeworkTime::now())
            ->limit(200)
            ->get(['id', 'status', 'request_no', 'student_id', 'guardian_mobile', 'expected_time']);

        if ($old->isEmpty()) {
            return;
        }

        $writer = $this->support->writer($caller);

        foreach ($old as $row) {
            $writer->transaction(function () use ($writer, $row) {
                $step = str_replace('pending_', '', $row->status).'_status';

                $changed = $writer->table('el_requests')->where('id', $row->id)->where('status', $row->status)
                    ->update(['status' => 'expired', $step => 'expired', 'updated_at' => HomeworkTime::now()]);

                if ($changed) {
                    $this->support->log($writer, LeaveSupport::MODULE_EARLY, (int) $row->id, 'expired', $row->status, 'expired', 'Not fully approved by the leave time', 'system', null, 'system');
                    $this->support->notify($writer, LeaveSupport::MODULE_EARLY, (int) $row->id, 'expired', 'guardian', (int) $row->student_id, $row->guardian_mobile,
                        "Early leave request {$row->request_no} expired because it was not fully approved by {$this->support->clock($row->expected_time)}.");
                }
            });
        }
    }

    public function overview(HomeworkCaller $caller, int $studentId, array $filters): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);
        $this->expireOld($caller);

        $student = $this->support->student($db, $studentId) ?? throw new ApiException('Student not found.', 404);
        $isGuardian = $this->support->isGuardian($caller);
        $mask = ! $isGuardian;
        $today = Carbon::parse(HomeworkTime::today());
        $year = (string) ($filters['year'] ?? $today->year);

        // The overview cards are always this month, whatever the history is filtered to.
        $month = $db->table('el_requests')->where('student_id', $studentId)->whereYear('leave_date', $today->year)->whereMonth('leave_date', $today->month)->pluck('status');

        $history = $this->rows($db)
            ->where('r.student_id', $studentId)
            ->whereYear('r.leave_date', $year)
            ->when($filters['month'] ?? null, fn (Builder $q, $value) => $q->whereMonth('r.leave_date', $value))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('r.status', $status))
            ->orderByDesc('r.leave_date')
            ->orderByDesc('r.id')
            ->limit(200)
            ->get();

        $todayRow = $this->rows($db)->where('r.student_id', $studentId)->where('r.leave_date', $today->toDateString())->orderByDesc('r.id')->first();

        return [
            'year' => $year,
            'years' => $this->support->years($db, 'el_requests', 'leave_date', $studentId, $year),
            'closing_time' => substr($this->closingTime($db, $settings, $student, $today), 0, 5),
            'class_label' => $this->support->classLabel($student->class_name, $student->section_name),
            'guardians' => array_map(fn (array $guardian, string $key) => [
                'id' => $key,
                'name' => $guardian['name'],
                'relation' => $guardian['relation_bn'],
                'mobile' => $mask ? $this->support->maskMobile($guardian['mobile']) : ($guardian['mobile'] ?: null),
            ], $guardians = $this->support->guardians($student), array_keys($guardians)),
            'pickups' => array_map(fn (array $pickup, string $key) => ['id' => $key, 'name' => $pickup['name'], 'relation' => $pickup['relation']], $pickups = $this->pickups($db, $student), array_keys($pickups)),
            'reasons' => $this->support->lookups($db, LeaveSupport::KIND_EL_REASON),
            'stages' => $settings->el_final_approval ? ['parent', 'teacher', 'final'] : ['parent', 'teacher'],
            'overview' => [
                'pending_parent' => $month->filter(fn ($status) => $status === 'pending_parent')->count(),
                'pending_teacher' => $month->filter(fn ($status) => in_array($status, ['pending_teacher', 'pending_final'], true))->count(),
                'approved' => $month->filter(fn ($status) => in_array($status, ['approved', 'released'], true))->count(),
                'rejected' => $month->filter(fn ($status) => $status === 'rejected')->count(),
                'total' => $month->count(),
            ],
            'today' => $todayRow ? $this->present($db, $todayRow, $mask, false, $isGuardian) : null,
            'history' => $history->map(fn (object $row) => $this->present($db, $row, $mask, false, $isGuardian))->all(),
        ];
    }

    public function request(HomeworkCaller $caller, int $studentId, int $id): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        $row = $this->rows($db)->where('r.student_id', $studentId)->where('r.id', $id)->first()
            ?? throw new ApiException('Request not found.', 404);
        $isGuardian = $this->support->isGuardian($caller);

        return $this->present($db, $row, ! $isGuardian, true, $isGuardian);
    }

    public function create(HomeworkCaller $caller, int $studentId, array $input, ?UploadedFile $file): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);
        $this->expireOld($caller);

        $student = $this->support->student($db, $studentId) ?? throw new ApiException('Student not found.', 404);
        $isGuardian = $this->support->isGuardian($caller);
        $errors = [];

        $leaveAt = Carbon::parse(HomeworkTime::fromApi($input['leave_at']));
        $date = $leaveAt->toDateString();
        $time = $leaveAt->format('H:i:00');
        $closing = $this->closingTime($db, $settings, $student, $leaveAt);

        if ($problem = $this->timeProblem($settings, $leaveAt, $time, $closing)) {
            $errors['leave_at'] = $problem;
        }

        $reason = $this->support->findLookup($db, LeaveSupport::KIND_EL_REASON, (string) $input['reason']);

        if (! $reason) {
            $errors['reason'] = 'Use one of the reasons from 12.1.';
        }

        // A guardian who leaves this out is the first guardian on the record.
        $guardians = $this->support->guardians($student);
        $guardianKey = strtolower(trim((string) ($input['guardian_id'] ?? '')));

        if ($isGuardian && $guardianKey === '') {
            $guardianKey = (string) array_key_first($guardians);
        }

        if (! $guardians) {
            $errors['guardian_id'] = 'This student has no guardian on record.';
        } elseif ($guardianKey === '') {
            $errors['guardian_id'] = 'Required. Use an id from guardians.';
        } elseif (! isset($guardians[$guardianKey])) {
            $errors['guardian_id'] = 'Not one of this student\'s linked guardians.';
        }

        $pickups = $this->pickups($db, $student);
        $pickupValue = trim(strip_tags((string) $input['pickup']));

        if ($pickupValue === '') {
            $errors['pickup'] = 'Required.';
        }

        // An id from pickups, or the person written out, such as "চাচা (রফিক)".
        $pickup = $pickups[$pickupValue] ?? ['name' => mb_substr($pickupValue, 0, 100), 'relation' => '', 'mobile' => '', 'pickup_id' => null];

        if ($problem = $this->support->attachmentProblem($settings, $file)) {
            $errors['attachment'] = $problem;
        }

        if (! $errors) {
            $open = $db->table('el_requests')->where('student_id', $studentId)->where('leave_date', $date)->whereIn('status', self::OPEN)->value('request_no');

            if ($open) {
                $errors['leave_at'] = "Request $open for this day is still open.";
            }
        }

        if ($errors) {
            $this->support->fail($errors);
        }

        $details = $this->support->cleanText($input['details'] ?? null, 1000);
        $guardian = $guardians[$guardianKey];
        $final = (int) $settings->el_final_approval;
        $status = $isGuardian ? 'pending_teacher' : 'pending_parent';
        $stored = $this->support->storeAttachment($db, $file, 'early_leave', 'el_'.$studentId);
        $writer = $this->support->writer($caller);
        $role = $caller->user->role;

        try {
            $id = $writer->transaction(function () use ($writer, $db, $student, $studentId, $date, $time, $closing, $reason, $details, $guardianKey, $guardian, $pickup, $stored, $role, $isGuardian, $status, $final) {
                $now = HomeworkTime::now();

                $saved = $this->support->insertNumbered($writer, 'el_requests', 'request_no', 'EL', $date, [
                    'student_id' => $studentId,
                    'session_code' => $student->session_code,
                    'class_code' => $student->class_code,
                    'section_code' => $student->section_code,
                    'shift_code' => $student->shift_code,
                    'roll_number' => $student->roll_number,
                    'leave_date' => $date,
                    'expected_time' => $time,
                    'closing_time' => $closing,
                    'reason_id' => $reason->id,
                    'details' => $details !== '' ? $details : null,
                    'guardian_relation' => $guardianKey,
                    'guardian_name' => $guardian['name'],
                    'guardian_mobile' => $guardian['mobile'] ?: null,
                    'pickup_id' => $pickup['pickup_id'],
                    'pickup_name' => $pickup['name'],
                    'pickup_relation' => $pickup['relation'],
                    'pickup_mobile' => $pickup['mobile'] ?: null,
                    'attachment_path' => $stored['path'] ?? null,
                    'attachment_name' => $stored['name'] ?? null,
                    'attachment_size' => $stored['size'] ?? null,
                    'raised_by_type' => $role,
                    'raised_by_id' => null,
                    'raised_via' => 'app',
                    'status' => $status,
                    // A guardian's own request counts as the parent's approval.
                    'parent_status' => $isGuardian ? 'approved' : 'pending',
                    'parent_via' => $isGuardian ? 'app' : null,
                    'parent_at' => $isGuardian ? $now : null,
                    'teacher_status' => $isGuardian ? 'pending' : 'not_reached',
                    'final_required' => $final,
                    'final_status' => $final ? 'not_reached' : 'skipped',
                    'expires_at' => "$date $time",
                    'created_at' => $now,
                ]);
                $id = $saved['id'];
                $number = $saved['number'];

                $this->support->log($writer, LeaveSupport::MODULE_EARLY, $id, 'submitted', null, $status, "{$reason->name_en}, $date ".substr($time, 0, 5), $role, null);

                $when = $this->support->shortDate($date).' '.$this->support->clock($time);

                if ($isGuardian) {
                    $this->support->log($writer, LeaveSupport::MODULE_EARLY, $id, 'parent_approved', null, 'pending_teacher', 'Raised by the guardian', 'guardian', null);

                    foreach ($this->support->classTeacherCodes($db, $student) as $code) {
                        $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'teacher_approval_required', 'teacher', $code, null,
                            "Early leave $number: {$student->student_name} at $when ({$reason->name_en}). Parent approved, your approval is needed.");
                    }
                } else {
                    $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'parent_approval_required', 'guardian', $studentId, $guardian['mobile'],
                        "{$student->student_name} asked to leave school early at $when ({$reason->name_en}). Request $number needs your approval.");
                }

                return $id;
            });
        } catch (Throwable $e) {
            $this->support->deleteAttachment($stored);

            throw $e;
        }

        return $this->present($db, $this->rows($db)->where('r.id', $id)->first(), ! $isGuardian);
    }

    /**
     * Requests whose pending step belongs to this teacher or admin.
     */
    public function staffQueue(HomeworkCaller $caller): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        $query = $this->support->isAdmin($caller)
            ? $this->rows($db)->where('r.status', 'pending_final')
            : $this->support->limitToClassTeacher($this->rows($db), $caller, 'r')->where('r.status', 'pending_teacher');

        return $query->orderBy('r.leave_date')->orderBy('r.expected_time')->get()
            ->map(fn (object $row) => $this->present($db, $row))
            ->all();
    }

    public function guardianQueueForChild(HomeworkCaller $caller, int $studentId): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        return $this->rows($db)
            ->where('r.student_id', $studentId)
            ->where('r.status', 'pending_parent')
            ->orderBy('r.leave_date')
            ->orderBy('r.expected_time')
            ->get()
            ->map(fn (object $row) => $this->present($db, $row))
            ->all();
    }

    /**
     * The step comes from who is calling and the request's status, never from the body.
     */
    public function decide(HomeworkCaller $caller, int $id, string $decision, ?string $remarks): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        $isGuardian = $this->support->isGuardian($caller);
        $row = $this->rows($db)->where('r.id', $id)
            ->when($isGuardian, fn (Builder $q) => $q->whereIn('r.student_id', $caller->studentIds))
            ->first() ?? throw new ApiException('Request not found.', 404);

        $step = $this->stepFor($caller, $row);
        $remarks = $this->support->cleanText($remarks, 1000) ?: null;
        $from = 'pending_'.$step;
        $now = HomeworkTime::now();
        $changes = [$step.'_at' => $now, $step.'_remarks' => $remarks];

        if ($step === 'parent') {
            $changes['parent_via'] = 'app';
        } else {
            $changes[$step.'_by'] = $caller->userId;
        }

        $code = null;

        if ($decision === 'reject') {
            $to = 'rejected';
            $changes[$step.'_status'] = 'rejected';
        } elseif ($step === 'parent') {
            $to = 'pending_teacher';
            $changes += ['parent_status' => 'approved', 'teacher_status' => 'pending'];
        } elseif ($step === 'teacher' && $row->final_required) {
            $to = 'pending_final';
            $changes += ['teacher_status' => 'approved', 'final_status' => 'pending'];
        } else {
            $to = 'approved';
            $code = (string) random_int(100000, 999999);
            $changes += [$step.'_status' => 'approved', 'release_code' => $code];
        }

        $writer = $this->support->writer($caller);

        $writer->transaction(function () use ($writer, $db, $caller, $row, $id, $step, $from, $to, $decision, $changes, $remarks, $code, $now) {
            $changed = $writer->table('el_requests')->where('id', $id)->where('status', $from)
                ->update($changes + ['status' => $to, 'updated_at' => $now]);

            if (! $changed) {
                throw new ApiException('This request changed a moment ago. Load it again.');
            }

            $actor = match (true) {
                $step === 'parent' => 'guardian',
                $step === 'final' || $this->support->isAdmin($caller) => 'office',
                default => 'teacher',
            };

            $this->support->log($writer, LeaveSupport::MODULE_EARLY, $id, $step.'_'.($decision === 'approve' ? 'approved' : 'rejected'), $from, $to, (string) $remarks,
                $actor, $step === 'parent' ? null : $caller->userId);

            $this->notifyDecision($writer, $db, $row, $id, $step, $to, $remarks, $code);
        });

        return $this->present($db, $this->rows($db)->where('r.id', $id)->first(), false, true, $isGuardian);
    }

    public function register(HomeworkCaller $caller, string $date): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        return [
            'date' => $date,
            'requests' => $this->rows($db)->where('r.leave_date', $date)->orderBy('r.expected_time')->orderBy('r.id')->get()
                ->map(fn (object $row) => $this->present($db, $row))
                ->all(),
        ];
    }

    /**
     * Approved and released requests of a day, for any teacher or admin on gate duty. The release
     * code is left out, because the guardian must show it.
     */
    public function gate(HomeworkCaller $caller, string $date): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        return [
            'date' => $date,
            'requests' => $this->rows($db)
                ->where('r.leave_date', $date)
                ->whereIn('r.status', ['approved', 'released'])
                ->orderByRaw('(r.status = "approved") DESC')
                ->orderBy('r.expected_time')
                ->get()
                ->map(fn (object $row) => $this->present($db, $row))
                ->all(),
        ];
    }

    public function release(HomeworkCaller $caller, int $id, array $input): array
    {
        $db = $caller->db;
        $settings = $this->support->settings($db);
        $this->expireOld($caller);

        $row = $this->rows($db)->where('r.id', $id)->first() ?? throw new ApiException('Request not found.', 404);

        if ($row->status !== 'approved') {
            throw new ApiException($row->status === 'released' ? 'This student was already released.' : "This request is not fully approved ({$row->status}).");
        }

        if ($row->leave_date !== HomeworkTime::today()) {
            throw new ApiException("This early leave is for {$row->leave_date}, not today.");
        }

        $needsCode = in_array($settings->el_pickup_verify, ['code', 'code_and_list'], true);

        if ($needsCode && ! hash_equals((string) $row->release_code, (string) preg_replace('/\D/', '', (string) ($input['code'] ?? '')))) {
            $this->support->fail(['code' => 'The release code does not match.']);
        }

        // Left out or the same person means the pickup on record. Anyone else is an override.
        $handed = trim(strip_tags((string) ($input['handed_to'] ?? '')));
        $isSame = $handed === '' || strcasecmp($handed, $row->pickup_name) === 0 || strcasecmp($handed, "{$row->pickup_relation} ({$row->pickup_name})") === 0;
        $overrideReason = null;

        if ($isSame) {
            $toName = $row->pickup_name;
            $toRelation = $row->pickup_relation;
            $method = $settings->el_pickup_verify;
        } else {
            if (! $settings->el_allow_unregistered) {
                $this->support->fail(['handed_to' => "Only {$row->pickup_name} may collect this student."]);
            }

            $overrideReason = $this->support->cleanText($input['override_reason'] ?? null, 500);

            if ($overrideReason === '') {
                $this->support->fail(['override_reason' => 'Required when a different person collects the student.']);
            }

            $toName = mb_substr($handed, 0, 100);
            $toRelation = mb_substr(trim((string) ($input['handed_to_relation'] ?? '')), 0, 40);
            $method = 'override';
        }

        $writer = $this->support->writer($caller);

        $writer->transaction(function () use ($writer, $caller, $row, $id, $toName, $toRelation, $method, $overrideReason) {
            $now = HomeworkTime::now();

            $changed = $writer->table('el_requests')->where('id', $id)->where('status', 'approved')->update([
                'status' => 'released',
                'released_at' => $now,
                'released_by' => $caller->userId,
                'handed_to_name' => $toName,
                'handed_to_relation' => $toRelation,
                'verify_method' => $method,
                'override_reason' => $overrideReason,
                'updated_at' => $now,
            ]);

            if (! $changed) {
                throw new ApiException('This request changed a moment ago. Load it again.');
            }

            $person = $toName.($toRelation ? " ($toRelation)" : '');

            $this->support->log($writer, LeaveSupport::MODULE_EARLY, $id, $method === 'override' ? 'override' : 'released', 'approved', 'released',
                "Handed to $person".($overrideReason ? ". Reason: $overrideReason" : ''), 'gate', $caller->userId);

            $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'released', 'guardian', (int) $row->student_id, $row->guardian_mobile,
                "{$row->student_name} left school at {$this->support->clock($now)} with $person. Request {$row->request_no}.");
        });

        return $this->present($db, $this->rows($db)->where('r.id', $id)->first());
    }

    public function summary(HomeworkCaller $caller, string $year, int $month): array
    {
        $db = $caller->db;
        $this->expireOld($caller);

        $rows = $db->table('el_requests as r')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'r.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'r.section_code')
            ->whereYear('r.leave_date', $year)
            ->whereMonth('r.leave_date', $month)
            ->selectRaw('class.class_name, section.section_name, COUNT(*) AS total, SUM(r.status IN ("pending_parent","pending_teacher","pending_final")) AS pending, '
                .'SUM(r.status = "approved") AS approved, SUM(r.status = "released") AS released, SUM(r.status = "rejected") AS rejected, SUM(r.status IN ("expired","cancelled")) AS closed')
            ->groupBy('r.class_code', 'r.section_code', 'class.class_name', 'section.section_name', 'class.class_position', 'section.sec_position')
            ->orderBy('class.class_position')
            ->orderBy('section.sec_position')
            ->get();

        return [
            'year' => $year,
            'month' => $month,
            'by_class' => $rows->map(fn (object $row) => [
                'class_label' => $this->support->classLabel($row->class_name, $row->section_name),
                'total' => (int) $row->total,
                'pending' => (int) $row->pending,
                'approved' => (int) $row->approved,
                'released' => (int) $row->released,
                'rejected' => (int) $row->rejected,
                'expired_or_cancelled' => (int) $row->closed,
            ])->all(),
        ];
    }

    private function stepFor(HomeworkCaller $caller, object $row): string
    {
        if ($this->support->isGuardian($caller)) {
            if ($row->status !== 'pending_parent') {
                throw new ApiException("This request is not waiting for the parent ({$row->status}).");
            }

            return 'parent';
        }

        $isAdmin = $this->support->isAdmin($caller);

        return match ($row->status) {
            'pending_teacher' => $isAdmin || $this->support->isClassTeacherOf($caller->db, $caller->teacherCode, $row)
                ? 'teacher'
                : throw new ApiException('This student is not in a section where you are class teacher.', 403),
            'pending_final' => $isAdmin ? 'final' : throw new ApiException('Final approval is given by a school admin.', 403),
            'pending_parent' => throw new ApiException('The parent/guardian has not approved yet.', 403),
            default => throw new ApiException("This request is already closed ({$row->status})."),
        };
    }

    private function notifyDecision(Connection $writer, Connection $db, object $row, int $id, string $step, string $to, ?string $remarks, ?string $code): void
    {
        $when = $this->support->clock($row->expected_time);
        $name = $row->student_name;
        $number = $row->request_no;
        $studentId = (int) $row->student_id;

        if ($to === 'pending_teacher') {
            foreach ($this->support->classTeacherCodes($db, $row) as $teacherCode) {
                $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'teacher_approval_required', 'teacher', $teacherCode, null, "Early leave $number: $name at $when. Parent approved, your approval is needed.");
            }
        } elseif ($to === 'pending_final') {
            $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'final_approval_required', 'office', null, null, "Early leave $number ($name, $when) needs final approval.");
        } elseif ($to === 'approved') {
            $this->support->log($writer, LeaveSupport::MODULE_EARLY, $id, 'code_sent', null, null, 'Release code sent to the guardian', 'system', null, 'system');
            $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'approved', 'guardian', $studentId, $row->guardian_mobile,
                "Early leave $number for $name at $when is approved. Pickup: {$row->pickup_name}. Show code $code at the school gate.");
            $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'approved', 'student', $studentId, null, "Your early leave at $when is approved. Wait at the gate for {$row->pickup_name}.");
        } else {
            $this->support->notify($writer, LeaveSupport::MODULE_EARLY, $id, 'rejected', 'guardian', $studentId, $row->guardian_mobile,
                "Early leave $number for $name was rejected at the $step step.".($remarks ? " $remarks" : ''));
        }
    }

    /**
     * The weekday's row, then the every-day row, then the school setting.
     */
    private function closingTime(Connection $db, object $settings, object $student, Carbon $date): string
    {
        $times = $db->table('el_closing_times')->where('shift_code', $student->shift_code)->where('is_active', 1)->get();
        $time = $times->first(fn (object $row) => $row->weekday !== null && (int) $row->weekday === $date->dayOfWeek)
            ?? $times->first(fn (object $row) => $row->weekday === null);

        return $time->closing_time ?? $settings->el_closing_time;
    }

    private function timeProblem(object $settings, Carbon $leaveAt, string $time, string $closing): ?string
    {
        $today = Carbon::parse(HomeworkTime::today());
        $date = Carbon::parse($leaveAt->toDateString());
        $last = $today->copy()->addDays((int) $settings->el_max_advance_days);

        if ($date->lt($today) || $date->gt($last)) {
            return $settings->el_max_advance_days > 0 ? "Choose today or a day up to {$last->toDateString()}." : 'Early leave can be requested for today only.';
        }

        if ($this->support->isOffDay($settings, $date)) {
            return 'School is closed on that day.';
        }

        if ($time >= $closing) {
            return 'Leave time must be before school closing time ('.substr($closing, 0, 5).').';
        }

        if ($date->eq($today) && $leaveAt->lt(Carbon::parse(HomeworkTime::now())->addMinutes((int) $settings->el_min_lead_minutes))) {
            return "For today, leave time must be at least {$settings->el_min_lead_minutes} minutes from now.";
        }

        return null;
    }

    /**
     * Linked guardians and registered pickup people, by the id the app sends back.
     *
     * @return array<string, array{name: string, relation: string, mobile: string, pickup_id: ?int}>
     */
    private function pickups(Connection $db, object $student): array
    {
        $list = [];

        foreach ($this->support->guardians($student) as $key => $guardian) {
            $list['g_'.$key] = ['name' => $guardian['name'], 'relation' => $guardian['relation'], 'mobile' => $guardian['mobile'], 'pickup_id' => null];
        }

        $people = $db->table('el_authorized_pickups')->where('student_id', $student->student_id)->where('status', 'active')->orderBy('person_name')->get();

        foreach ($people as $person) {
            $list['p_'.$person->id] = ['name' => $person->person_name, 'relation' => $person->relation, 'mobile' => (string) $person->mobile, 'pickup_id' => (int) $person->id];
        }

        return $list;
    }

    private function rows(Connection $db): Builder
    {
        return $db->table('el_requests as r')
            ->leftJoin('sl_lookups as reason', 'reason.id', '=', 'r.reason_id')
            ->leftJoin('student_current_status_info as current', 'current.current_status_info_id', '=', 'r.student_id')
            ->leftJoin('student_info as student', 'student.student_registration_code', '=', 'current.student_registration_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 'r.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 'r.section_code')
            ->select('r.*', 'reason.name_en as reason_en', 'student.student_name', 'class.class_name', 'section.section_name');
    }

    /**
     * $mask hides the guardian's mobile from a student. $showCode adds the release code for a
     * guardian, which only has a value once the request is approved.
     */
    private function present(Connection $db, object $row, bool $mask = false, bool $withStudent = true, bool $showCode = false): array
    {
        $data = [
            'id' => (string) $row->id,
            'number' => $row->request_no,
            'leave_at' => HomeworkTime::toApi($row->leave_date.' '.$row->expected_time),
            'reason' => $this->support->slug($row->reason_en),
            'details' => $row->details ?: null,
            'guardian_name' => $row->guardian_name,
            'guardian_mobile' => $mask ? $this->support->maskMobile($row->guardian_mobile) : $row->guardian_mobile,
            'pickup' => $row->pickup_relation ? "{$row->pickup_relation} ({$row->pickup_name})" : $row->pickup_name,
        ] + $this->support->attachmentFields($db, $row) + [
            'status' => $row->status,
            'steps' => $this->steps($db, $row),
            'created_at' => HomeworkTime::toApi($row->created_at),
            'released_at' => HomeworkTime::toApi($row->released_at),
            'released_by' => $this->support->userName($db, $row->released_by),
            'handed_to' => $row->handed_to_name ? $row->handed_to_name.($row->handed_to_relation ? " ({$row->handed_to_relation})" : '') : null,
        ];

        if ($showCode) {
            $data['release_code'] = $row->status === 'approved' && $row->release_code ? $row->release_code : null;
        }

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

    private function steps(Connection $db, object $row): array
    {
        $steps = [
            $this->step('parent', $row, in_array($row->parent_status, ['approved', 'rejected'], true) ? $row->guardian_name : null),
            $this->step('teacher', $row, $this->support->userName($db, $row->teacher_by)),
        ];

        if ($row->final_required) {
            $steps[] = $this->step('final', $row, $this->support->userName($db, $row->final_by));
        }

        return $steps;
    }

    private function step(string $stage, object $row, ?string $by): array
    {
        $status = $row->{$stage.'_status'};

        return [
            'stage' => $stage,
            'status' => in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'not_reached',
            'by' => $by,
            'at' => HomeworkTime::toApi($row->{$stage.'_at'}),
            'remarks' => $row->{$stage.'_remarks'} ?: null,
        ];
    }
}
