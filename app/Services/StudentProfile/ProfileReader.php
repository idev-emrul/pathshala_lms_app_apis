<?php

namespace App\Services\StudentProfile;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Read queries and the JSON shapes of the student profile API.
 *
 * student_current_status_info.active_status: 1 active, 2 transfer, 3 inactive, 4 promoted (an older
 * year), 5 deleted. A section / group / shift of 0 in a teacher assignment means "all".
 */
class ProfileReader
{
    private const STATUSES = [1 => 'active', 2 => 'transfer', 3 => 'inactive', 4 => 'inactive'];

    private const HISTORY_STATUSES = [1 => 'Current', 2 => 'Transfer', 3 => 'Inactive', 4 => 'Promoted'];

    // student_info.student_bg codes, as the web system shows them.
    private const BLOOD_GROUPS = [1 => 'A+', 2 => 'A-', 3 => 'B+', 4 => 'B-', 5 => 'O+', 6 => 'O-', 7 => 'AB+', 8 => 'AB-'];

    /**
     * A student_current_status_info row that is not deleted, or null.
     */
    public function findRow(Connection $db, int $studentId): ?object
    {
        return $db->table('student_current_status_info')
            ->where('current_status_info_id', $studentId)
            ->where('active_status', '<>', 5)
            ->first();
    }

    /**
     * 1.1 The student's full profile. For a teacher, guardian contact is left out and identifiers stay masked.
     */
    public function profile(Connection $db, object $row, bool $forTeacher): array
    {
        $student = $db->table('student_info')->where('student_registration_code', $row->student_registration_code)->first();
        $current = $this->currentPlacement($db, $row);
        $history = $this->history($db, $row->student_registration_code);

        return [
            'student_id' => (string) $current->current_status_info_id,
            'name_en' => $this->text($student->student_name),
            'name_bn' => $this->text($student->student_name_bn),
            'student_no' => sprintf('%06d', $student->student_registration_code),
            'roll_no' => $this->text($current->roll_number),
            'admission_no' => null,
            'status' => self::STATUSES[$current->active_status] ?? 'inactive',
            'session' => $current->session_name,
            'class' => $current->class_name,
            'section' => $current->section_name,
            'group' => $current->group_name,
            'shift' => $current->shift_name,
            'branch' => null,
            'class_teacher' => $this->classTeacherName($db, $current),
            'admission_date' => $this->date($student->student_admission_date),
            'admission_class' => $history[0]['class'] ?? null,
            'student_type' => $current->remission_name,
            'student_category' => $db->table('category_info')->where('category_code', $student->student_category)->value('category_name'),
            'date_of_birth' => $this->date($student->birth_date),
            'gender' => [1 => 'male', 2 => 'female', 3 => 'other'][(int) $student->student_gender] ?? null,
            'religion' => $db->table('religion_infos')->where('religion_code', $student->student_religion)->value('religion_name'),
            'blood_group' => self::BLOOD_GROUPS[(int) $student->student_bg] ?? null,
            'nationality' => $this->text($student->stu_nationality),
            'nid' => $this->sensitive(null),
            'birth_reg' => $this->sensitive($student->birth_cer_number),
            'birth_reg_date' => null,
            'present_address' => $this->text($student->student_present_address, $student->student_present_address_bn),
            'permanent_address' => $this->text($student->student_permanent_address, $student->student_permanent_address_bn),
            'photo_url' => $this->photoUrl($db, $student),
            'guardians' => $this->guardians($student, $forTeacher),
            'history' => $history,
            'documents' => [],
        ];
    }

    /**
     * True when the teacher is a class or subject teacher of the row's class, in a running session.
     */
    public function teacherTeaches(Connection $db, ?int $teacherCode, object $row): bool
    {
        if (! $teacherCode) {
            return false;
        }

        foreach (['class_teacher_info', 'subject_teacher_info'] as $table) {
            $found = $db->table("$table as t")
                ->join('session_info as session', function ($join) {
                    $join->on('session.session_code', '=', 't.session_code')->where('session.running_session', 1);
                })
                ->where('t.teacher_code', $teacherCode)
                ->where('t.session_code', $row->session_code)
                ->where('t.class_code', $row->class_code)
                ->whereIn('t.section_code', [(int) $row->section_code, 0])
                ->whereIn('t.group_code', [(int) $row->group_code, 0])
                ->whereIn('t.shift_code', [(int) $row->shift_code, 0])
                ->exists();

            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * 1.3 The students of the sections the teacher is class teacher of, in the running session(s).
     */
    public function classList(Connection $db, ?int $teacherCode): array
    {
        if (! $teacherCode) {
            return [];
        }

        return $db->table('class_teacher_info as cti')
            ->join('session_info as session', function ($join) {
                $join->on('session.session_code', '=', 'cti.session_code')->where('session.running_session', 1);
            })
            ->join('student_current_status_info as s', function ($join) {
                $join->on('s.session_code', '=', 'cti.session_code')
                    ->on('s.class_code', '=', 'cti.class_code')
                    ->where(fn ($q) => $q->where('cti.section_code', 0)->orWhereColumn('s.section_code', 'cti.section_code'))
                    ->where(fn ($q) => $q->where('cti.group_code', 0)->orWhereColumn('s.group_code', 'cti.group_code'))
                    ->where(fn ($q) => $q->where('cti.shift_code', 0)->orWhereColumn('s.shift_code', 'cti.shift_code'))
                    ->whereIn('s.active_status', [1, 2, 3]);
            })
            ->join('student_info as si', 'si.student_registration_code', '=', 's.student_registration_code')
            ->where('cti.teacher_code', $teacherCode)
            ->distinct()
            ->orderBy('s.class_code')
            ->orderBy('s.section_code')
            ->orderBy('s.roll_number')
            ->get(['s.current_status_info_id', 's.roll_number', 's.active_status', 's.class_code', 's.section_code', 'si.student_name', 'si.student_name_bn'])
            ->map(fn ($row) => [
                'student_id' => (string) $row->current_status_info_id,
                'name' => $this->text($row->student_name, $row->student_name_bn),
                'roll' => $this->text($row->roll_number),
                'status' => self::STATUSES[$row->active_status],
            ])
            ->all();
    }

    /**
     * The student's current placement: the latest active row, else the latest transfer or inactive
     * row, else the row asked for. With the lookup names.
     */
    public function currentPlacement(Connection $db, object $row): object
    {
        $current = $this->placements($db)
            ->where('s.student_registration_code', $row->student_registration_code)
            ->whereIn('s.active_status', [1, 2, 3])
            ->orderByRaw('s.active_status = 1 DESC')
            ->orderByDesc('s.session_code')
            ->orderByDesc('s.current_status_info_id')
            ->first();

        return $current ?? $this->placements($db)->where('s.current_status_info_id', $row->current_status_info_id)->first();
    }

    private function placements(Connection $db): Builder
    {
        return $db->table('student_current_status_info as s')
            ->leftJoin('session_info as session', 'session.session_code', '=', 's.session_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 's.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 's.section_code')
            ->leftJoin('group_info as group', 'group.group_code', '=', 's.group_code')
            ->leftJoin('shift_info as shift', 'shift.shift_code', '=', 's.shift_code')
            ->leftJoin('remission_info as remission', 'remission.remission_code', '=', 's.remission_code')
            ->select([
                's.current_status_info_id', 's.session_code', 's.class_code', 's.section_code', 's.group_code',
                's.shift_code', 's.roll_number', 's.active_status', 'session.session_name', 'class.class_name',
                'section.section_name', 'group.group_name', 'shift.shift_name', 'remission.remission_name',
            ]);
    }

    /**
     * The master class teacher of the placement's class and section (0 = all), if any.
     */
    private function classTeacherName(Connection $db, object $placement): ?string
    {
        return $db->table('class_teacher_info as cti')
            ->join('teacher_info as t', 't.teacher_code', '=', 'cti.teacher_code')
            ->where('cti.session_code', $placement->session_code)
            ->where('cti.class_code', $placement->class_code)
            ->whereIn('cti.section_code', [(int) $placement->section_code, 0])
            ->whereIn('cti.group_code', [(int) $placement->group_code, 0])
            ->whereIn('cti.shift_code', [(int) $placement->shift_code, 0])
            ->orderByDesc('cti.master_teacher')
            ->orderByDesc('cti.section_code')
            ->orderByDesc('cti.class_teacher_id')
            ->value('t.teacher_name');
    }

    /**
     * One row per academic year, oldest first. gpa is the year's final result, never calculated here.
     */
    private function history(Connection $db, int|string $registrationCode): array
    {
        return $db->table('student_current_status_info as s')
            ->leftJoin('session_info as session', 'session.session_code', '=', 's.session_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 's.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 's.section_code')
            ->where('s.student_registration_code', $registrationCode)
            ->where('s.active_status', '<>', 5)
            ->orderBy('s.session_code')
            ->orderBy('s.current_status_info_id')
            ->get([
                's.roll_number', 's.active_status', 'session.session_name', 'class.class_name', 'section.section_name',
                $db->raw('(SELECT r.average_gpa FROM exam_overall_result r WHERE r.stud_class_id = s.current_status_info_id AND r.final_result = 1 ORDER BY r.exam_overall_result_id DESC LIMIT 1) AS gpa'),
            ])
            ->map(fn ($row) => [
                'session' => $row->session_name,
                'class' => $row->class_name,
                'section' => $row->section_name,
                'roll' => $this->text($row->roll_number),
                // 5.33 is the web system's "golden A+" marker; the GPA itself is at most 5.
                'gpa' => $row->gpa === null ? null : min(5, (float) $row->gpa),
                'status' => self::HISTORY_STATUSES[$row->active_status] ?? null,
            ])
            ->all();
    }

    /**
     * Father, mother and guardian. The school keeps one phone number, given on the guardian block.
     * A teacher does not get guardian contact (mobile, email, NID).
     */
    private function guardians(object $student, bool $forTeacher): array
    {
        $blocks = [
            ['Father', $this->text($student->father_name, $student->father_name_bn), null, null, null, $student->father_nid_number],
            ['Mother', $this->text($student->mother_name, $student->mother_name_bn), null, null, null, $student->mother_nid_number],
            ['Guardian', $this->text($student->guardian_name, $student->guardian_name_bn), $this->text($student->contact_number), $this->text($student->guardian_occupation), $this->text($student->guardian_email), $student->guardian_nid_number],
        ];

        $guardians = [];
        foreach ($blocks as [$relation, $name, $mobile, $profession, $email, $nid]) {
            if ($name === null && $mobile === null) {
                continue;
            }

            $guardians[] = [
                'relation' => $relation,
                'name' => $name,
                'mobile' => $forTeacher ? null : $mobile,
                'profession' => $profession,
                'email' => $forTeacher ? null : $email,
                'nid' => $forTeacher ? $this->sensitive(null) : $this->sensitive($nid),
            ];
        }

        return $guardians;
    }

    /**
     * The photo URL, built as the web profile builds it. "-name.ext" files came in through the school
     * website's admission form.
     */
    private function photoUrl(Connection $db, object $student): ?string
    {
        $ext = trim((string) $student->img_ext);
        $institute = $db->table('institute_info')->first(['institute_EIIN', 'institute_storage_path', 'institute_website']);

        if ($ext === '' || ! $institute) {
            return null;
        }

        if (str_starts_with($ext, '-')) {
            return 'http://'.$institute->institute_website.'/images/application/'.substr($ext, 1);
        }

        return rtrim((string) $institute->institute_storage_path, '/').'/'.$institute->institute_EIIN
            .'/student/'.(int) ceil($student->student_registration_code / 200).'/'.$student->student_registration_code.$ext;
    }

    /**
     * { masked, full } as the contract wants: masked always when a value exists; full is never sent.
     */
    private function sensitive(?string $value): array
    {
        $value = preg_replace('/\s+/', '', (string) $value);

        if ($value === '' || $value === '0') {
            return ['masked' => null, 'full' => null];
        }

        $visible = strlen($value) > 4 ? substr($value, -4) : '';
        $masked = str_repeat('*', strlen($value) - strlen($visible)).$visible;

        // Groups of four from the right, e.g. "**** **** 5521".
        return ['masked' => strrev(trim(chunk_split(strrev($masked), 4, ' '))), 'full' => null];
    }

    /**
     * Trimmed text, or the fallback, or null. Placeholders such as "N/A" and "0" count as empty.
     */
    private function text(mixed $value, mixed $fallback = null): ?string
    {
        foreach ([$value, $fallback] as $item) {
            $item = trim((string) $item);

            if ($item !== '' && $item !== '0' && strtoupper($item) !== 'N/A') {
                return $item;
            }
        }

        return null;
    }

    private function date(?string $value): ?string
    {
        return ($value && ! str_starts_with($value, '0000') && strtotime($value) !== false) ? date('Y-m-d', strtotime($value)) : null;
    }
}
