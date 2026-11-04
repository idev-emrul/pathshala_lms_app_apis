<?php

namespace App\Services\Behaviour;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Incidents, following the web system's bh_incident_model. bh_incident_history is append only,
 * so an earlier note is never overwritten there. Students and guardians never see an incident.
 */
class BehaviourIncidentService
{
    public function __construct(private BehaviourSupport $support) {}

    public function list(HomeworkCaller $caller, array $filters): array
    {
        $db = $caller->db;

        $rows = $this->support->limitToCaller($this->rows($db), $caller, 'sc')
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('i.status', $status))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $id) => $q->where('i.student_id', $id))
            ->orderByDesc('i.occurred_at')
            ->orderByDesc('i.id')
            ->limit(300)
            ->get();

        $history = $db->table('bh_incident_history as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.created_by')
            ->whereIn('h.incident_id', $rows->pluck('id')->all())
            ->orderBy('h.created_at')
            ->orderBy('h.id')
            ->get(['h.incident_id', 'h.status', 'h.note', 'h.created_at', 'u.username', 'u.user_full_name'])
            ->groupBy('incident_id');

        return $rows->map(fn (object $row) => [
            'id' => 'inc-'.$row->id,
            'student_id' => (string) $row->student_id,
            'student_name' => (string) $row->student_name,
            'occurred_at' => HomeworkTime::toApi($row->occurred_at),
            'location' => (string) $row->location,
            'category' => $row->category,
            'description' => $row->description,
            'reported_by' => (string) $row->reported_by_name,
            'status' => $row->status,
            'action_taken' => (string) $row->action_taken,
            'parent_contacted' => (bool) $row->parent_contacted,
            'follow_up_note' => (string) $row->follow_up_note,
            'support_required' => (bool) $row->support_required,
            'history' => ($history[$row->id] ?? collect())->map(fn (object $h) => [
                'at' => HomeworkTime::toApi($h->created_at),
                'by' => $h->user_full_name ?: ($h->username ?: 'System'),
                'status' => $h->status,
                'note' => (string) $h->note,
            ])->values()->all(),
        ])->all();
    }

    /**
     * A new incident always starts as reported, and reported_by is the signed-in teacher.
     */
    public function create(HomeworkCaller $caller, object $student, array $data): int
    {
        $writer = $this->support->writer($caller);

        return $writer->transaction(function () use ($writer, $caller, $student, $data) {
            $now = HomeworkTime::now();

            $id = $writer->table('bh_incidents')->insertGetId([
                'student_id' => $student->student_id,
                'session_id' => $student->session_code,
                'class_id' => $student->class_code,
                'section_id' => $student->section_code,
                'occurred_at' => $data['occurred_at'],
                'location' => $data['location'] !== '' ? $data['location'] : null,
                'category' => $data['category'],
                'description' => $data['description'],
                'reported_by' => $caller->teacherCode,
                'action_taken' => $data['action_taken'] !== '' ? $data['action_taken'] : null,
                'parent_contacted' => $data['parent_contacted'] ? 1 : 0,
                'follow_up_note' => null,
                'status' => 'reported',
                'support_required' => $data['support_required'] ? 1 : 0,
                'is_deleted' => 0,
                'created_by' => $caller->userId,
                'created_at' => $now,
                'updated_by' => $caller->userId,
                'updated_at' => $now,
            ]);

            $note = 'Incident reported.'.($data['action_taken'] !== '' ? " Action taken: {$data['action_taken']}" : '');
            $this->addHistory($writer, $id, 'reported', $note, $caller->userId);

            if ($data['support_required']) {
                $this->support->notifySupportRequired($writer, $student, $id);
            }

            return $id;
        });
    }

    /**
     * The status only moves forward, and every change and note becomes a new history row.
     *
     * @param  array<string, mixed>  $input  Only the fields the app sent
     */
    public function update(HomeworkCaller $caller, int $id, array $input): void
    {
        $db = $caller->db;

        $incident = $this->rows($db)->where('i.id', $id)->first()
            ?? throw new ApiException('Incident not found.', 404);

        $to = trim((string) ($input['status'] ?? $incident->status));

        if ($this->support->statusIndex($to) < 0) {
            throw ValidationException::withMessages(['status' => 'status must be reported, under_review or resolved.']);
        }

        if ($this->support->statusIndex($to) < $this->support->statusIndex($incident->status)) {
            throw ValidationException::withMessages(['status' => "The status only moves forward: reported, under_review, resolved. It cannot go back to $to."]);
        }

        $note = BehaviourText::clean($input['follow_up_note'] ?? '', 2000);
        $action = array_key_exists('action_taken', $input) ? BehaviourText::clean($input['action_taken'], 1000) : (string) $incident->action_taken;
        $parent = array_key_exists('parent_contacted', $input) ? (int) BehaviourText::isTrue($input['parent_contacted']) : (int) $incident->parent_contacted;
        $flag = array_key_exists('support_required', $input) ? (int) BehaviourText::isTrue($input['support_required']) : (int) $incident->support_required;

        $isResolving = $to === 'resolved' && $incident->status !== 'resolved';

        if ($isResolving && $note === '') {
            throw ValidationException::withMessages(['follow_up_note' => 'follow_up_note is required when resolving.']);
        }

        $changes = [];
        $lines = [];

        if ($to !== $incident->status) {
            $lines[] = "Status: {$this->support->statusLabel($incident->status)} → {$this->support->statusLabel($to)}.";
        }

        if ($note !== '') {
            $changes['follow_up_note'] = $note;
            $lines[] = $note;
        }

        if ($action !== (string) $incident->action_taken) {
            $changes['action_taken'] = $action !== '' ? $action : null;
            $lines[] = 'Action taken: '.($action !== '' ? $action : '(cleared)');
        }

        if ($parent !== (int) $incident->parent_contacted) {
            $changes['parent_contacted'] = $parent;
            $lines[] = $parent ? 'Parent contacted.' : 'Parent contact cleared.';
        }

        if ($flag !== (int) $incident->support_required) {
            $changes['support_required'] = $flag;
            $lines[] = $flag ? 'Support Required flagged; guardian notified.' : 'Support Required cleared.';
        }

        if (! $lines) {
            throw new ApiException('Nothing to change: send a new status or a follow_up_note.');
        }

        $student = $this->support->student($db, (int) $incident->student_id);
        $writer = $this->support->writer($caller);

        $writer->transaction(function () use ($writer, $caller, $incident, $id, $to, $changes, $lines, $flag, $isResolving, $student, $input) {
            $query = fn () => $writer->table('bh_incidents')->where('id', $id)->where('status', $incident->status);

            // Saving identical values counts as 0 rows, so the row count cannot show who got here first.
            if (! $query()->lockForUpdate()->exists()) {
                throw new ApiException('Someone else changed this incident a moment ago. Reload it and try again.', 409);
            }

            $query()->update($changes + ['status' => $to, 'updated_by' => $caller->userId, 'updated_at' => HomeworkTime::now()]);

            $this->addHistory($writer, $id, $to, implode("\n", $lines), $caller->userId);

            if ($student && $flag && ! (int) $incident->support_required) {
                $this->support->notifySupportRequired($writer, $student, $id);
            }

            if ($student && $isResolving && BehaviourText::isTrue($input['notify_guardian'] ?? false)) {
                $this->support->notify($writer, $id, 'important_update', 'guardian', (int) $student->student_id, $student->contact_number,
                    "Pathshala: thank you for your support. We are glad to share that {$student->student_name} is doing well, and the matter we discussed is now closed.");
            }
        });
    }

    private function addHistory(Connection $writer, int $incidentId, string $status, string $note, ?int $userId): void
    {
        $writer->table('bh_incident_history')->insert([
            'incident_id' => $incidentId,
            'status' => $status,
            'note' => $note !== '' ? $note : null,
            'created_by' => $userId,
            'created_at' => HomeworkTime::now(),
        ]);
    }

    private function rows(Connection $db): Builder
    {
        return $db->table('bh_incidents as i')
            ->join('student_current_status_info as sc', 'sc.current_status_info_id', '=', 'i.student_id')
            ->leftJoin('student_info as si', 'si.student_registration_code', '=', 'sc.student_registration_code')
            ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'i.reported_by')
            ->where('i.is_deleted', 0)
            ->selectRaw('i.*, TRIM(si.student_name) AS student_name, t.teacher_name AS reported_by_name');
    }
}
