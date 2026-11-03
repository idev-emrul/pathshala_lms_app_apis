<?php

namespace App\Services\StudentProfile;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 1.2 A student asks the school to correct a profile field. The request is stored for the school to
 * review in app_stu_profile_change_request; the profile itself is never changed here.
 */
class ChangeRequestService
{
    private const TABLE = 'app_stu_profile_change_request';

    public function __construct(
        private SchoolDbService $schools,
        private ProfileReader $reader,
    ) {}

    /**
     * Stores the request with a snapshot of the student's placement on that day. The same
     * Idempotency-Key twice is one request. Returns false when the key was already used.
     */
    public function create(ProfileCaller $caller, object $row, array $request, ?string $idempotencyKey, ?string $ip): bool
    {
        $placement = $this->reader->currentPlacement($caller->db, $row);

        // Only an active student may ask for changes (CRS M1 §5).
        if ((int) $placement->active_status !== 1) {
            throw new ApiException('This student is not active', 403);
        }

        $db = $this->schools->connectForProfile($caller->dbName);

        if ($idempotencyKey && $db->table(self::TABLE)->where('idempotency_key', $idempotencyKey)->exists()) {
            return false;
        }

        try {
            $db->table(self::TABLE)->insert([
                'student_registration_code' => $row->student_registration_code,
                'current_status_info_id' => $placement->current_status_info_id,
                'session_code' => $placement->session_code,
                'session_name' => $placement->session_name,
                'class_code' => $placement->class_code,
                'class_name' => $placement->class_name,
                'section_code' => $placement->section_code,
                'group_code' => $placement->group_code,
                'shift_code' => $placement->shift_code,
                'roll_number' => $placement->roll_number,
                'student_status' => $placement->active_status,
                'request_data' => json_encode($request, JSON_UNESCAPED_UNICODE),
                'idempotency_key' => $idempotencyKey,
                'app_user_id' => $caller->user->id,
                'status' => 0,
                'created_ip' => $ip,
                'created_at' => HomeworkTime::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries arrived at the same moment; the first one is stored.
            return false;
        }

        return true;
    }
}
