<?php

namespace App\Services\Attendance;

use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use App\Services\Sms\SmsGateway;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 10.6 Guardian SMS for the register, as the web register (attendance/attendance_update) sends it:
 *
 * - only for today's register, to the student's contact_number;
 * - one template per status, the school's latest active message_info row of type 6 (present),
 *   7 (absent) or 8 (fugitive). A status without a template gets no SMS;
 * - tags @student_name, @student_id, @student_class, @student_roll, @student_section,
 *   @attendance_status, @attendance_date are filled in;
 * - every SMS is logged in message_receiver_info, which the web system's SMS balance counts.
 *
 * The web teacher ticks "Send SMS?" on each submit; the app has no such field, so the school's
 * templates decide (contract 10.6: "as the school has configured"). Never throws: the register is
 * already saved.
 */
class AttendanceSms
{
    // attendance_info day code => message_info.message_type
    private const TEMPLATE_TYPES = [1 => 6, 2 => 7, 5 => 8];

    private const STATUS_NAMES = [1 => 'PRESENT', 2 => 'ABSENT', 3 => 'LEAVE', 4 => 'HOLIDAY', 5 => 'FUGITIVE'];

    public function __construct(
        private SchoolDbService $schools,
        private SmsGateway $gateway,
    ) {}

    /**
     * @param  array<int, int>  $changed  current_status_info_id => the new day code
     */
    public function send(string $dbName, int $senderUserId, string $date, array $changed): void
    {
        if (! config('pathshala.sms.enabled') || $date !== HomeworkTime::today() || ! $changed) {
            return;
        }

        try {
            $db = $this->schools->connectForAttendance($dbName);

            $templates = [];
            foreach (self::TEMPLATE_TYPES as $code => $type) {
                $template = $db->table('message_info')->where('message_type', $type)->where('message_status', 1)->orderByDesc('message_id')->first(['message_id', 'message_description']);

                if ($template) {
                    $templates[$code] = $template;
                }
            }

            $changed = array_filter($changed, fn (int $code) => isset($templates[$code]));
            if (! $changed) {
                return;
            }

            $settings = $this->gateway->settings($db);
            if (! $settings) {
                return;
            }

            $balance = $this->gateway->balance($db, $settings['token']);
            $day = Carbon::parse($date)->format('d-m-Y');

            foreach ($this->students($db, array_keys($changed)) as $student) {
                $number = $this->number($student->contact_number);
                if (! $number) {
                    continue;
                }

                $code = $changed[(int) $student->current_status_info_id];
                $template = $templates[$code];

                $text = str_replace(
                    ['@student_name', '@student_id', '@student_class', '@student_roll', '@student_section', '@attendance_status', '@attendance_date'],
                    [$student->student_name, sprintf('%06d', $student->student_registration_code), $student->class_name, $student->roll_number, $student->section_name, self::STATUS_NAMES[$code], $day],
                    $template->message_description,
                );
                $size = $this->gateway->size($text);

                if ($balance < $size) {
                    break;
                }

                $result = $this->gateway->send($settings, $number, $text);

                // Not reached at all: nothing was sent, so nothing is logged or counted.
                if (! $result['sent'] && $result['error'] === null) {
                    continue;
                }

                $balance -= $size;

                $db->table('message_receiver_info')->insert([
                    'message_id' => $template->message_id,
                    'message_response_id' => mb_substr((string) ($result['id'] ?? $result['error']), 0, 50),
                    'receiver_id' => $student->current_status_info_id,
                    'receiver_type' => 1,
                    'receiver_counter' => 1,
                    'msg_counter' => $size,
                    // 5 = waiting for the delivery report, when the school uses one; 1 = done.
                    'msg_status' => $result['sent'] && $settings['delivery_report'] ? 5 : 1,
                    'msg_delivered' => $result['sent'] ? $size : 0,
                    'msg_pending' => 0,
                    'msg_failed' => $result['sent'] ? 0 : $size,
                    'msg_sent_doc' => HomeworkTime::now(),
                    'msg_sent_dom' => '',
                    'msg_sender' => $senderUserId,
                    'sms_api_version_id' => $settings['version'],
                    'sms_api_error_code' => null,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The students with what the template needs, as attendance_model::findStudentInformation reads them.
     */
    private function students(Connection $db, array $ids): array
    {
        return $db->table('student_current_status_info as s')
            ->join('student_info as si', 'si.student_registration_code', '=', 's.student_registration_code')
            ->leftJoin('class_info as class', 'class.class_code', '=', 's.class_code')
            ->leftJoin('section_info as section', 'section.section_code', '=', 's.section_code')
            ->whereIn('s.current_status_info_id', $ids)
            ->get(['s.current_status_info_id', 's.roll_number', 'si.student_name', 'si.student_registration_code', 'si.contact_number', 'class.class_name', 'section.section_name'])
            ->all();
    }

    /**
     * The number as the web sends it: an 11-digit number gets 88 in front; anything that is not
     * 13 or 14 characters with the "+" is skipped.
     */
    private function number(?string $contact): ?string
    {
        $contact = trim((string) $contact);
        $full = strlen($contact) === 11 ? '+88'.$contact : $contact;

        if ($contact === '' || ! in_array(strlen($full), [13, 14], true)) {
            return null;
        }

        return str_replace('+', '', $full);
    }
}
