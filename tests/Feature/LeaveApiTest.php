<?php

namespace Tests\Feature;

use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Leave writes run against pathshala_app_test_leave, a copy of the real tables' structure with
 * made-up rows. The real school database is never written to. The clock is fixed at
 * Wednesday 4 November 2026, 10:00 in Dhaka. Friday is the weekly off day.
 */
class LeaveApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_leave';
    private const TEST_SCHOOL_ID = 82;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const SECTION_ID = 1;
    private const CLASS_TEACHER_USER = 500;
    private const CLASS_TEACHER_CODE = 700;
    private const OTHER_TEACHER_USER = 501;
    private const ADMIN_USER = 502;
    private const STUDENT_ROW = 4723;
    private const OTHER_STUDENT_ROW = 4724;
    private const INACTIVE_STUDENT_ROW = 4725;
    private const STUDENT_CONTACT = '01811111111';

    private const COPIED_TABLES = [
        'sl_settings', 'sl_lookups', 'sl_logs', 'sl_notifications', 'slv_applications', 'slv_attendance_sync',
        'el_requests', 'el_closing_times', 'el_authorized_pickups', 'hw_api_idempotency', 'attendance_info',
        'users', 'teacher_info', 'student_info', 'student_current_status_info', 'session_info', 'class_info',
        'section_info', 'shift_info', 'group_info', 'class_teacher_info', 'institute_info',
    ];

    private static bool $schoolCreated = false;

    private string $studentToken;
    private string $otherStudentToken;
    private string $inactiveStudentToken;
    private string $classTeacherToken;
    private string $otherTeacherToken;
    private string $adminToken;
    private string $guardianToken;
    private int $childId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atUtc('2026-11-04 04:00:00');
        $this->prepareSchool();
        Storage::fake(config('pathshala.homework.files_disk'));

        $this->studentToken = $this->tokenFor('01700000020', 'student', '9001');
        $this->otherStudentToken = $this->tokenFor('01700000021', 'student', '9002');
        $this->inactiveStudentToken = $this->tokenFor('01700000022', 'student', '9003');
        $this->classTeacherToken = $this->tokenFor('01700000023', 'teacher', (string) self::CLASS_TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000024', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->adminToken = $this->tokenFor('01700000025', 'admin', (string) self::ADMIN_USER);

        $guardian = AppUser::create(['mobile_number' => '01700000026', 'role' => 'guardian', 'is_verified' => true, 'pin' => 'x']);
        $this->childId = AppGuardianChild::create(['app_user_id' => $guardian->id, 'schl_id' => self::TEST_SCHOOL_ID, 'student_id' => '9001'])->id;
        $this->guardianToken = app(TokenService::class)->issueAppToken($guardian, null);
        $guardian->update(['auth_token' => $this->guardianToken]);
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('school_information')->where('schl_id', self::TEST_SCHOOL_ID)->delete();

        parent::tearDown();
    }

    private function atUtc(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'UTC'));
    }

    private function prepareSchool(): void
    {
        $master = DB::connection('master');

        if (! self::$schoolCreated) {
            $master->statement('CREATE DATABASE IF NOT EXISTS `'.self::TEST_SCHOOL_DB.'` CHARACTER SET utf8mb4');

            // Some old tables have zero-date defaults, which strict mode refuses to copy.
            $sqlMode = $master->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            $master->statement("SET SESSION sql_mode = ''");

            foreach (self::COPIED_TABLES as $table) {
                $master->statement('DROP TABLE IF EXISTS `'.self::TEST_SCHOOL_DB."`.`$table`");
                $master->statement('CREATE TABLE `'.self::TEST_SCHOOL_DB."`.`$table` LIKE `".self::SCHOOL_DB."`.`$table`");
                $this->relaxColumns($master, $table);
            }

            $master->statement('SET SESSION sql_mode = ?', [$sqlMode]);

            self::$schoolCreated = true;
        }

        foreach (self::COPIED_TABLES as $table) {
            $master->statement('TRUNCATE TABLE `'.self::TEST_SCHOOL_DB."`.`$table`");
        }

        $master->table('school_information')->where('schl_id', self::TEST_SCHOOL_ID)->delete();
        $master->table('school_information')->insert([
            'schl_id' => self::TEST_SCHOOL_ID,
            'schl_name' => 'Leave Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'lv_test',
            'api_code' => 'secret-api-code',
            'school_secret' => 'secret',
            'db_name' => self::TEST_SCHOOL_DB,
        ]);

        $this->seedSchool();
    }

    private function relaxColumns(Connection $master, string $table): void
    {
        $columns = $master->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND IS_NULLABLE = ? AND COLUMN_KEY <> ? AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE ?',
            [self::TEST_SCHOOL_DB, $table, 'NO', 'PRI', '%auto_increment%'],
        );

        foreach ($columns as $column) {
            $master->statement('ALTER TABLE `'.self::TEST_SCHOOL_DB."`.`$table` MODIFY `{$column->COLUMN_NAME}` {$column->COLUMN_TYPE} NULL");
        }
    }

    private function school(): Connection
    {
        config(['database.connections.test_leave_school' => ['database' => self::TEST_SCHOOL_DB] + config('database.connections.master')]);

        $connection = DB::connection('test_leave_school');

        if ($connection->getDatabaseName() !== self::TEST_SCHOOL_DB) {
            throw new RuntimeException('Leave tests must only write to the test school database.');
        }

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('institute_info')->insert(['institute_code' => 1, 'institute_EIIN' => '999999']);
        $db->table('session_info')->insert(['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1]);
        $db->table('class_info')->insert(['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_ID, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => 2, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::CLASS_TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1],
            ['teacher_code' => 701, 'teacher_name' => 'Karim', 'teacher_active_status' => 1],
        ]);
        $db->table('users')->insert([
            ['id' => self::CLASS_TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::CLASS_TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::OTHER_TEACHER_USER, 'username' => 'karim', 'user_full_name' => 'Karim', 'teacher_code' => 701, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::ADMIN_USER, 'username' => 'office', 'user_full_name' => 'Office Admin', 'teacher_code' => 0, 'user_type' => 'Admin', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
        ]);
        $db->table('class_teacher_info')->insert(['teacher_code' => self::CLASS_TEACHER_CODE, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'shift_code' => 1, 'session_code' => self::SESSION]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9001, 'student_name' => 'Rahim', 'father_name' => 'Karim Ahmed', 'mother_name' => 'Salma', 'contact_number' => self::STUDENT_CONTACT],
            ['student_registration_code' => 9002, 'student_name' => 'Maria', 'father_name' => 'Jamal', 'mother_name' => null, 'contact_number' => null],
            ['student_registration_code' => 9003, 'student_name' => 'Old', 'father_name' => null, 'mother_name' => null, 'contact_number' => null],
            ['student_registration_code' => 9004, 'student_name' => 'Nadia', 'father_name' => 'Rafiq', 'mother_name' => null, 'contact_number' => null],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9001, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'shift_code' => 1, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => self::OTHER_STUDENT_ROW, 'student_registration_code' => 9002, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => 2, 'shift_code' => 1, 'roll_number' => 7, 'active_status' => 1],
            ['current_status_info_id' => self::INACTIVE_STUDENT_ROW, 'student_registration_code' => 9003, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'shift_code' => 1, 'roll_number' => 3, 'active_status' => 0],
        ]);
        $db->table('sl_settings')->insert([
            'slv_reviewer_mode' => 'either', 'slv_day_count' => 'calendar', 'slv_allow_withdraw' => 1, 'slv_past_days' => 3, 'slv_future_days' => 30, 'slv_max_days' => 15,
            'slv_sync_attendance' => 1, 'weekly_off_days' => '5', 'el_final_approval' => 0, 'el_max_advance_days' => 1, 'el_min_lead_minutes' => 15, 'el_closing_time' => '14:30:00',
            'el_pickup_verify' => 'code_and_list', 'el_allow_unregistered' => 0, 'attachment_max_kb' => 2048, 'attachment_types' => 'pdf,jpg,jpeg,png',
        ]);
        $db->table('sl_lookups')->insert([
            ['id' => 1, 'kind' => 'leave_type', 'name_en' => 'Sick', 'name_bn' => 'অসুস্থতা', 'needs_attachment' => 0, 'max_days' => null, 'sort_order' => 1, 'is_active' => 1],
            ['id' => 2, 'kind' => 'leave_type', 'name_en' => 'Family', 'name_bn' => 'পারিবারিক', 'needs_attachment' => 1, 'max_days' => 2, 'sort_order' => 2, 'is_active' => 1],
            ['id' => 6, 'kind' => 'el_reason', 'name_en' => 'Medical Appointment', 'name_bn' => 'ডাক্তারের অ্যাপয়েন্টমেন্ট', 'needs_attachment' => 0, 'max_days' => null, 'sort_order' => 1, 'is_active' => 1],
            ['id' => 7, 'kind' => 'el_reason', 'name_en' => 'Personal Work', 'name_bn' => null, 'needs_attachment' => 0, 'max_days' => null, 'sort_order' => 2, 'is_active' => 1],
        ]);
    }

    private function tokenFor(string $mobile, string $role, string $verifiedId): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => true,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Leave Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }

    private function apply(array $overrides = [], ?string $token = null, int $student = self::STUDENT_ROW): TestResponse
    {
        return $this->withToken($token ?? $this->studentToken)->postJson("/api/v1/students/$student/leave", $overrides + [
            'type_id' => 'sick',
            'from' => '2026-11-05',
            'to' => '2026-11-07',
            'reason' => 'Fever',
        ]);
    }

    private function decide(string $id, string $decision = 'approve', ?string $remarks = null, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->classTeacherToken)->postJson("/api/v1/leave/review/$id/decision", ['decision' => $decision, 'remarks' => $remarks]);
    }

    private function earlyLeave(array $overrides = [], ?string $token = null, int $student = self::STUDENT_ROW): TestResponse
    {
        return $this->withToken($token ?? $this->studentToken)->postJson("/api/v1/students/$student/early-leave", $overrides + [
            'leave_at' => '2026-11-04T07:00:00Z',
            'reason' => 'medical',
            'guardian_id' => 'father',
            'pickup' => 'g_father',
        ]);
    }

    private function decideEarly(string $id, string $decision = 'approve', ?string $token = null, array $extra = []): TestResponse
    {
        return $this->withToken($token ?? $this->classTeacherToken)->postJson("/api/v1/early-leave/$id/decision", $extra + ['decision' => $decision]);
    }

    private function guardianApproves(string $id): TestResponse
    {
        return $this->decideEarly($id, 'approve', $this->guardianToken, ['child_id' => $this->childId]);
    }

    private function approvedEarlyLeave(): array
    {
        $id = $this->earlyLeave()->assertCreated()->json('data.id');
        $this->guardianApproves($id)->assertOk()->assertJsonPath('data.status', 'pending_teacher');
        $this->decideEarly($id)->assertOk()->assertJsonPath('data.status', 'approved');

        $code = $this->withToken($this->guardianToken)->getJson('/api/v1/students/'.self::STUDENT_ROW."/early-leave/$id")->assertOk()->json('data.release_code');

        return [$id, $code];
    }

    private function logActions(string $module, string $id): array
    {
        return $this->school()->table('sl_logs')->where('module', $module)->where('ref_id', $id)->orderBy('id')->pluck('action')->all();
    }

    public function test_tests_never_use_the_real_school_for_writes(): void
    {
        $this->assertSame(self::TEST_SCHOOL_DB, $this->school()->getDatabaseName());
        $this->assertNotSame(self::SCHOOL_DB, self::TEST_SCHOOL_DB);
    }

    public function test_apply_list_and_show_follow_the_web_shapes(): void
    {
        $created = $this->apply()->assertCreated()
            ->assertJsonPath('data.application_no', 'LV-2026-11-04-001')
            ->assertJsonPath('data.type', ['id' => 'sick', 'name' => 'অসুস্থতা'])
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.remarks', null)
            ->assertJsonPath('data.decided_by', null)
            ->assertJsonPath('data.attachment_name', null)
            ->assertJsonPath('data.attachment_url', null)
            ->assertJsonPath('data.applied_at', '2026-11-04T04:00:00Z')
            ->assertJsonPath('data.history.0.kind', 'submitted')
            ->assertJsonPath('data.history.0.by', 'Rahim')
            ->assertJsonPath('data.history.0.remarks', null)
            ->assertJsonPath('data.student_id', (string) self::STUDENT_ROW)
            ->assertJsonPath('data.student_name', 'Rahim')
            ->assertJsonPath('data.class_label', 'Eight A')
            ->assertJsonPath('data.roll', '12');

        $id = $created->json('data.id');
        $this->assertIsString($id);

        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';

        $this->withToken($this->studentToken)->getJson($url)->assertOk()
            ->assertJsonPath('data.year', '2026')
            ->assertJsonPath('data.years', ['2026'])
            ->assertJsonPath('data.types.0', ['id' => 'sick', 'name' => 'অসুস্থতা'])
            ->assertJsonPath('data.summary', ['total' => 1, 'approved' => 0, 'pending' => 1, 'rejected' => 0, 'leave_days' => 0, 'approved_percent' => 0])
            ->assertJsonPath('data.applications.0.id', $id)
            ->assertJsonMissingPath('data.applications.0.student_id')
            ->assertJsonPath('data.next_cursor', null);

        $this->withToken($this->studentToken)->getJson("$url/$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->withToken($this->studentToken)->getJson("$url/lv-$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->withToken($this->studentToken)->getJson("$url/999")->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_applying_stores_what_the_web_pages_read(): void
    {
        $id = $this->apply()->assertCreated()->json('data.id');
        $db = $this->school();
        $row = $db->table('slv_applications')->where('id', $id)->first();

        $this->assertSame(self::STUDENT_ROW, $row->student_id);
        $this->assertSame('student', $row->applied_by_type);
        $this->assertNull($row->applied_by_id);
        $this->assertSame('app', $row->applied_via);
        $this->assertSame(self::SESSION, $row->session_code);
        $this->assertSame(1, $row->shift_code);

        $log = $db->table('sl_logs')->where('ref_id', $id)->first();
        $this->assertSame('submitted', $log->action);
        $this->assertSame('student', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertSame('app', $log->via);
        $this->assertSame('Sick, 2026-11-05 to 2026-11-07', $log->remarks);

        $this->assertSame(
            [['submitted', 'guardian', 'sms'], ['new_request', 'teacher', 'inapp'], ['new_request', 'office', 'inapp']],
            $db->table('sl_notifications')->where('ref_id', $id)->orderBy('id')->get()->map(fn ($n) => [$n->event, $n->recipient_type, $n->channel])->all(),
        );
        $this->assertSame(self::STUDENT_CONTACT, $db->table('sl_notifications')->where('recipient_type', 'guardian')->value('mobile'));
    }

    public function test_application_rules(): void
    {
        $this->apply(['from' => '2026-10-31', 'to' => '2026-10-31'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['from']]]);
        $this->apply(['from' => '2026-12-05', 'to' => '2026-12-05'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['from']]]);
        $this->apply(['from' => '2026-11-05', 'to' => '2026-11-30'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['to']]]);
        $this->apply(['from' => '2026-11-06', 'to' => '2026-11-05'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['to']]]);
        $this->apply(['type_id' => 'nothing'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type_id']]]);
        $this->withToken($this->studentToken)->postJson('/api/v1/students/'.self::STUDENT_ROW.'/leave', ['from' => '2026-11-05', 'to' => '2026-11-05', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type_id']]]);

        // The type's own limit is smaller than the school's, and it needs a file.
        $this->apply(['type_id' => 'family'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['to']]]);
        $this->apply(['type_id' => 'family', 'to' => '2026-11-06'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['attachment']]]);

        // A type's limit can never be larger than the school's.
        $this->school()->table('sl_lookups')->where('name_en', 'Family')->update(['max_days' => 99, 'needs_attachment' => 0]);
        $this->apply(['type_id' => 'family', 'to' => '2026-11-30'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['to']]]);

        // The numeric id of the type works too.
        $this->apply(['type_id' => '1'])->assertCreated();
        $this->apply(['from' => '2026-11-07', 'to' => '2026-11-08'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['from']]]);
        $this->apply(['from' => '2026-11-08', 'to' => '2026-11-08'])->assertCreated()->assertJsonPath('data.application_no', 'LV-2026-11-04-002');
    }

    public function test_working_days_skip_the_weekly_off_day(): void
    {
        $this->school()->table('sl_settings')->update(['slv_day_count' => 'working']);

        $this->apply(['from' => '2026-11-05', 'to' => '2026-11-07'])->assertCreated()->assertJsonPath('data.days', 2);
        $this->apply(['from' => '2026-11-13', 'to' => '2026-11-13'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['to']]]);
    }

    public function test_attachment_rules_and_storage(): void
    {
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';
        $body = ['type_id' => 'sick', 'from' => '2026-11-05', 'to' => '2026-11-05', 'reason' => 'Fever'];

        $this->withToken($this->studentToken)->post($url, $body + ['attachment' => UploadedFile::fake()->create('note.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['attachment']]]);

        $this->withToken($this->studentToken)->post($url, $body + ['attachment' => UploadedFile::fake()->create('note.pdf', 3000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['attachment']]]);

        $created = $this->withToken($this->studentToken)->post($url, $body + ['attachment' => UploadedFile::fake()->create('note.pdf', 100)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachment_name', 'note.pdf');
        $this->assertNotNull($created->json('data.attachment_url'));

        // The same folder as the web system.
        $path = $this->school()->table('slv_applications')->where('id', $created->json('data.id'))->value('attachment_path');
        $this->assertStringStartsWith('999999/student_leave/leave/lv_'.self::STUDENT_ROW.'_', $path);
        Storage::disk(config('pathshala.homework.files_disk'))->assertExists($path);
    }

    public function test_only_the_student_or_a_linked_guardian_can_use_a_students_leave(): void
    {
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';

        $this->withToken($this->otherStudentToken)->getJson($url)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->withToken($this->classTeacherToken)->getJson($url)->assertStatus(403);
        $this->withToken($this->adminToken)->getJson($url)->assertStatus(403);

        $id = $this->apply(['from' => '2026-11-05', 'to' => '2026-11-05'], $this->guardianToken)->assertCreated()
            ->assertJsonPath('data.history.0.by', 'Karim Ahmed')
            ->json('data.id');
        $this->assertSame('guardian', $this->school()->table('slv_applications')->where('id', $id)->value('applied_by_type'));
        $this->withToken($this->guardianToken)->getJson($url)->assertOk()->assertJsonPath('data.summary.total', 1);

        $this->withToken($this->guardianToken)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/leave')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'not_linked');
    }

    public function test_an_inactive_student_can_read_but_not_apply(): void
    {
        $url = '/api/v1/students/'.self::INACTIVE_STUDENT_ROW.'/leave';

        $this->withToken($this->inactiveStudentToken)->getJson($url)->assertOk();
        $this->apply([], $this->inactiveStudentToken, self::INACTIVE_STUDENT_ROW)->assertStatus(403);
        $this->earlyLeave([], $this->inactiveStudentToken, self::INACTIVE_STUDENT_ROW)->assertStatus(403);
    }

    public function test_withdraw(): void
    {
        $id = $this->apply()->assertCreated()->json('data.id');
        $url = '/api/v1/students/'.self::STUDENT_ROW."/leave/$id/withdraw";

        $this->withToken($this->otherStudentToken)->postJson($url)->assertStatus(403);
        $this->withToken($this->studentToken)->postJson($url, ['remarks' => 'Better now'])->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.history.1.kind', 'withdrawn')
            ->assertJsonPath('data.history.1.remarks', 'Better now');
        $this->withToken($this->studentToken)->postJson($url)->assertStatus(422);

        // The same dates are free again.
        $this->apply()->assertCreated();

        $this->school()->table('sl_settings')->update(['slv_allow_withdraw' => 0]);
        $second = $this->apply(['from' => '2026-11-20', 'to' => '2026-11-20'])->assertCreated()->json('data.id');
        $this->withToken($this->studentToken)->postJson('/api/v1/students/'.self::STUDENT_ROW."/leave/$second/withdraw")->assertStatus(422);
    }

    public function test_review_queue_is_limited_to_the_class_teachers_sections(): void
    {
        $mine = $this->apply()->assertCreated()->json('data.id');
        $other = $this->apply([], $this->otherStudentToken, self::OTHER_STUDENT_ROW)->assertCreated()->json('data.id');

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk()
            ->assertJsonCount(1, 'data.applications')
            ->assertJsonPath('data.applications.0.id', $mine)
            ->assertJsonPath('data.applications.0.student_name', 'Rahim')
            ->assertJsonPath('data.applications.0.history', [])
            ->assertJsonPath('data.counts', ['pending' => 1, 'approved' => 0, 'rejected' => 0]);

        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/leave/review')->assertOk()->assertJsonCount(0, 'data.applications');
        $this->withToken($this->adminToken)->getJson('/api/v1/leave/review')->assertOk()->assertJsonCount(2, 'data.applications');
        $this->withToken($this->adminToken)->getJson('/api/v1/leave/review?status=withdrawn')->assertStatus(422);

        $this->withToken($this->studentToken)->getJson('/api/v1/leave/review')->assertStatus(403);
        $this->withToken($this->guardianToken)->getJson('/api/v1/leave/review')->assertStatus(403);

        $this->decide($other)->assertStatus(403);
        $this->decide($mine, 'approve', null, $this->otherTeacherToken)->assertStatus(403);
        $this->decide($other, 'approve', null, $this->adminToken)->assertOk()->assertJsonPath('data.decided_by', 'Office Admin');
        $this->assertSame('office', $this->school()->table('slv_applications')->where('id', $other)->value('decided_by_type'));
    }

    public function test_a_class_teacher_row_with_no_session_counts_for_every_session_but_shift_must_match(): void
    {
        $db = $this->school();
        $id = $this->apply()->assertCreated()->json('data.id');

        $db->table('class_teacher_info')->update(['session_code' => 0, 'shift_code' => 0]);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk()->assertJsonCount(1, 'data.applications');

        $db->table('class_teacher_info')->update(['shift_code' => 2]);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk()->assertJsonCount(0, 'data.applications');
        $this->decide($id)->assertStatus(403);

        // A session that is not running still counts, as it does on the web.
        $db->table('session_info')->update(['running_session' => 0]);
        $db->table('class_teacher_info')->update(['session_code' => self::SESSION, 'shift_code' => 1]);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk()->assertJsonCount(1, 'data.applications');
    }

    public function test_office_mode_only_refuses_teacher_decisions(): void
    {
        $id = $this->apply()->assertCreated()->json('data.id');
        $this->school()->table('sl_settings')->update(['slv_reviewer_mode' => 'office']);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk();
        $this->decide($id)->assertStatus(403);
        $this->decide($id, 'approve', null, $this->adminToken)->assertOk();
    }

    public function test_reject_needs_remarks_and_a_decision_is_final(): void
    {
        $id = $this->apply()->assertCreated()->json('data.id');

        $this->decide($id, 'reject')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['remarks']]]);
        $this->decide($id, 'maybe')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['decision']]]);
        $this->decide("lv-$id", 'reject', 'No proof')->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.remarks', 'No proof')
            ->assertJsonPath('data.decided_by', 'Hasan')
            ->assertJsonPath('data.history.1.kind', 'rejected')
            ->assertJsonPath('data.history.1.by', 'Hasan');

        $this->decide($id)->assertStatus(422)->assertJsonPath('message', 'This application is already decided.');
        $this->assertSame(0, $this->school()->table('slv_attendance_sync')->count());
        $this->assertSame(
            [['rejected', 'guardian'], ['rejected', 'student']],
            $this->school()->table('sl_notifications')->where('ref_id', $id)->where('event', 'rejected')->orderBy('id')->get()->map(fn ($n) => [$n->event, $n->recipient_type])->all(),
        );
    }

    public function test_approval_writes_leave_only_over_empty_or_absent_days_and_skips_the_off_day(): void
    {
        $db = $this->school();
        $db->table('attendance_info')->insert([
            'attendance_month' => 11, 'attendance_year' => 2026, 'attendance_class' => self::CLASS_ID, 'attendance_section' => self::SECTION_ID,
            'attendance_student_id' => self::STUDENT_ROW, 'd5' => 0, 'd6' => 2, 'd7' => 2, 'd8' => 1, 'd9' => 4, 'd10' => 5, 'd11' => 1,
        ]);

        $id = $this->apply(['from' => '2026-11-05', 'to' => '2026-11-10'])->assertCreated()->assertJsonPath('data.days', 6)->json('data.id');
        $this->decide($id)->assertOk()->assertJsonPath('data.status', 'approved');

        $row = $db->table('attendance_info')->where('attendance_student_id', self::STUDENT_ROW)->first();
        // 6 November is a Friday, the weekly off day, so it stays as it was.
        $this->assertSame([3, 2, 3, 1, 4, 5, 1], [$row->d5, $row->d6, $row->d7, $row->d8, $row->d9, $row->d10, $row->d11]);
        $this->assertSame(self::CLASS_TEACHER_USER, $row->attendance_modifier);

        $sync = $db->table('slv_attendance_sync')->where('application_id', $id)->orderBy('att_date')->get();
        $this->assertSame(['applied', 'skipped', 'applied', 'skipped', 'skipped', 'skipped'], $sync->pluck('state')->all());
        $this->assertSame('2', $sync[2]->prev_value);
        $this->assertSame('3', $sync[2]->new_value);
        $this->assertNull($sync[1]->attendance_id);
        $this->assertSame(1, $db->table('slv_applications')->where('id', $id)->value('attendance_synced'));

        $this->assertSame(['submitted', 'approved', 'attendance_synced'], $this->logActions('slv', $id));
        $this->assertSame('2 day(s) marked Leave, 4 skipped, 0 waiting for the month sheet', $db->table('sl_logs')->where('action', 'attendance_synced')->value('remarks'));

        // The history the app sees leaves the attendance row out.
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW."/leave/$id")->assertOk()->assertJsonCount(2, 'data.history');
    }

    public function test_attendance_of_another_student_or_month_is_left_alone(): void
    {
        $db = $this->school();
        $db->table('attendance_info')->insert([
            ['attendance_month' => 11, 'attendance_year' => 2026, 'attendance_student_id' => self::OTHER_STUDENT_ROW, 'd5' => 0],
            ['attendance_month' => 12, 'attendance_year' => 2026, 'attendance_student_id' => self::STUDENT_ROW, 'd5' => 0],
        ]);

        $id = $this->apply(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertCreated()->json('data.id');
        $this->decide($id)->assertOk();

        $this->assertSame([0, 0], $db->table('attendance_info')->orderBy('attendance_id')->pluck('d5')->all());
        $this->assertSame('waiting_month', $db->table('slv_attendance_sync')->where('application_id', $id)->value('state'));
    }

    public function test_a_day_waiting_for_its_month_sheet_is_written_when_the_queue_is_opened(): void
    {
        $db = $this->school();

        $id = $this->apply(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertCreated()->json('data.id');
        $this->decide($id)->assertOk();
        $this->assertSame('waiting_month', $db->table('slv_attendance_sync')->where('application_id', $id)->value('state'));

        $db->table('attendance_info')->insert(['attendance_month' => 11, 'attendance_year' => 2026, 'attendance_student_id' => self::STUDENT_ROW, 'd5' => 0]);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertOk();

        $this->assertSame(3, $db->table('attendance_info')->value('d5'));
        $this->assertSame('applied', $db->table('slv_attendance_sync')->where('application_id', $id)->value('state'));
    }

    public function test_attendance_is_not_touched_when_the_setting_is_off(): void
    {
        $db = $this->school();
        $db->table('sl_settings')->update(['slv_sync_attendance' => 0]);
        $db->table('attendance_info')->insert(['attendance_month' => 11, 'attendance_year' => 2026, 'attendance_student_id' => self::STUDENT_ROW, 'd5' => 0]);

        $id = $this->apply(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertCreated()->json('data.id');
        $this->decide($id)->assertOk();

        $this->assertSame(0, $db->table('attendance_info')->value('d5'));
        $this->assertSame(0, $db->table('slv_attendance_sync')->count());
    }

    public function test_cursor_pages_and_filters(): void
    {
        foreach (range(1, 22) as $day) {
            $this->school()->table('slv_applications')->insert([
                'application_no' => 'LV-2026-10-04-'.str_pad((string) $day, 3, '0', STR_PAD_LEFT),
                'student_id' => self::STUDENT_ROW, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'session_code' => self::SESSION,
                'leave_type_id' => $day === 1 ? 2 : 1, 'from_date' => "2026-10-$day", 'to_date' => "2026-10-$day", 'total_days' => 1, 'reason' => 'x',
                'status' => $day === 2 ? 'approved' : 'pending', 'created_at' => '2026-10-01 10:00:00',
            ]);
        }

        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';

        $first = $this->withToken($this->studentToken)->getJson($url)->assertOk()->assertJsonCount(20, 'data.applications')->assertJsonPath('data.summary.total', 22);
        $cursor = $first->json('data.next_cursor');
        $this->assertNotNull($cursor);

        $this->withToken($this->studentToken)->getJson("$url?cursor=$cursor")->assertOk()
            ->assertJsonCount(2, 'data.applications')
            ->assertJsonMissingPath('data.summary')
            ->assertJsonPath('data.next_cursor', null);

        $this->withToken($this->studentToken)->getJson("$url?cursor=bad")->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['cursor']]]);
        $this->withToken($this->studentToken)->getJson("$url?type=nothing")->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type']]]);

        $this->withToken($this->studentToken)->getJson("$url?status=approved")->assertOk()->assertJsonCount(1, 'data.applications')->assertJsonPath('data.summary.approved_percent', 100);
        $this->withToken($this->studentToken)->getJson("$url?type=family")->assertOk()->assertJsonCount(1, 'data.applications');
        $this->withToken($this->studentToken)->getJson("$url?month=9")->assertOk()->assertJsonCount(0, 'data.applications');
        $this->withToken($this->studentToken)->getJson("$url?year=2025")->assertOk()->assertJsonCount(0, 'data.applications')->assertJsonPath('data.years', ['2026', '2025']);
    }

    public function test_cached_reads_answer_304(): void
    {
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';

        $etag = $this->withToken($this->studentToken)->getJson($url)->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withToken($this->studentToken)->withHeader('If-None-Match', $etag)->getJson($url)->assertStatus(304);
    }

    public function test_the_same_idempotency_key_changes_nothing(): void
    {
        $key = ['Idempotency-Key' => 'leave-1'];
        $body = ['type_id' => 'sick', 'from' => '2026-11-05', 'to' => '2026-11-05', 'reason' => 'x'];
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/leave';

        $first = $this->withToken($this->studentToken)->withHeaders($key)->postJson($url, $body)->assertCreated();
        $again = $this->withToken($this->studentToken)->withHeaders($key)->postJson($url, $body)->assertCreated();

        $again->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, $this->school()->table('slv_applications')->count());
    }

    public function test_guardians_do_not_share_idempotency_keys(): void
    {
        $other = AppUser::create(['mobile_number' => '01700000027', 'role' => 'guardian', 'is_verified' => true, 'pin' => 'x']);
        AppGuardianChild::create(['app_user_id' => $other->id, 'schl_id' => self::TEST_SCHOOL_ID, 'student_id' => '9002']);
        $otherToken = app(TokenService::class)->issueAppToken($other, null);
        $other->update(['auth_token' => $otherToken]);

        $body = ['type_id' => 'sick', 'from' => '2026-11-05', 'to' => '2026-11-05', 'reason' => 'x'];

        $first = $this->withToken($this->guardianToken)->withHeaders(['Idempotency-Key' => 'same'])->postJson('/api/v1/students/'.self::STUDENT_ROW.'/leave', $body)->assertCreated();
        $second = $this->withToken($otherToken)->withHeaders(['Idempotency-Key' => 'same'])->postJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/leave', $body)->assertCreated();

        $second->assertHeaderMissing('Idempotent-Replayed');
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_leave_summary_is_for_admins(): void
    {
        $id = $this->apply()->assertCreated()->json('data.id');
        $this->decide($id)->assertOk();
        $this->apply([], $this->otherStudentToken, self::OTHER_STUDENT_ROW)->assertCreated();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/leave/summary?year=2026&month=11')->assertOk()
            ->assertJsonPath('data.year', '2026')
            ->assertJsonPath('data.month', 11)
            ->assertJsonPath('data.by_class.0', ['class_label' => 'Eight A', 'students' => 1, 'total' => 1, 'approved' => 1, 'pending' => 0, 'rejected' => 0, 'leave_days' => 3])
            ->assertJsonPath('data.by_class.1.class_label', 'Eight B')
            ->assertJsonPath('data.by_type.0.type', ['id' => 'sick', 'name' => 'অসুস্থতা'])
            ->assertJsonPath('data.by_type.0.total', 2);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/admin/leave/summary')->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/admin/leave/summary')->assertStatus(403);
    }

    public function test_a_banned_admin_or_teacher_loses_access_at_once(): void
    {
        $this->school()->table('users')->whereIn('id', [self::ADMIN_USER, self::CLASS_TEACHER_USER])->update(['banned' => 1]);

        $this->withToken($this->adminToken)->getJson('/api/v1/leave/review')->assertStatus(403);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/leave/review')->assertStatus(403);
    }

    public function test_a_school_without_the_leave_tables_answers_503(): void
    {
        $this->school()->statement('RENAME TABLE sl_settings TO sl_settings_hidden');

        try {
            $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/leave')
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'not_set_up');
        } finally {
            $this->school()->statement('RENAME TABLE sl_settings_hidden TO sl_settings');
        }
    }

    public function test_early_leave_page_follows_the_web_shape(): void
    {
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/early-leave';

        $this->withToken($this->studentToken)->getJson($url)->assertOk()
            ->assertJsonPath('data.year', '2026')
            ->assertJsonPath('data.closing_time', '14:30')
            ->assertJsonPath('data.class_label', 'Eight A')
            ->assertJsonPath('data.stages', ['parent', 'teacher'])
            ->assertJsonPath('data.guardians.0', ['id' => 'father', 'name' => 'Karim Ahmed', 'relation' => 'পিতা', 'mobile' => '01811-XXX-111'])
            ->assertJsonPath('data.pickups.0', ['id' => 'g_father', 'name' => 'Karim Ahmed', 'relation' => 'Father'])
            ->assertJsonPath('data.reasons.0', ['id' => 'medical', 'name' => 'ডাক্তারের অ্যাপয়েন্টমেন্ট'])
            ->assertJsonPath('data.reasons.1', ['id' => 'personal_work', 'name' => 'Personal Work'])
            ->assertJsonPath('data.overview', ['pending_parent' => 0, 'pending_teacher' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0])
            ->assertJsonPath('data.today', null)
            ->assertJsonPath('data.history', []);

        $this->withToken($this->guardianToken)->getJson($url)->assertOk()->assertJsonPath('data.guardians.0.mobile', self::STUDENT_CONTACT);

        $this->school()->table('el_closing_times')->insert([
            ['shift_code' => 1, 'weekday' => null, 'closing_time' => '13:00:00', 'is_active' => 1],
            ['shift_code' => 1, 'weekday' => 3, 'closing_time' => '12:00:00', 'is_active' => 1],
        ]);
        $this->withToken($this->studentToken)->getJson($url)->assertOk()->assertJsonPath('data.closing_time', '12:00');
    }

    public function test_early_leave_rules(): void
    {
        $this->earlyLeave(['leave_at' => '2026-11-03T07:00:00Z'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['leave_at']]]);
        $this->earlyLeave(['leave_at' => '2026-11-06T07:00:00Z'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['leave_at']]]);
        // After closing time, 15:00 in Dhaka.
        $this->earlyLeave(['leave_at' => '2026-11-04T09:00:00Z'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['leave_at']]]);
        // Less than 15 minutes from now.
        $this->earlyLeave(['leave_at' => '2026-11-04T04:10:00Z'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['leave_at']]]);
        $this->earlyLeave(['reason' => 'nothing'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        // This student has no guardian_name on record, and a student must choose.
        $this->earlyLeave(['guardian_id' => 'guardian'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['guardian_id']]]);
        $this->earlyLeave(['guardian_id' => null])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['guardian_id']]]);

        $this->earlyLeave()->assertCreated()->assertJsonPath('data.number', 'EL-2026-11-04-001');
        $this->earlyLeave(['leave_at' => '2026-11-04T08:00:00Z'])->assertStatus(422)
            ->assertJsonPath('error.details.leave_at', 'Request EL-2026-11-04-001 for this day is still open.');

        // Tomorrow is allowed, as a new request.
        $this->earlyLeave(['leave_at' => '2026-11-05T07:00:00Z'])->assertCreated()->assertJsonPath('data.number', 'EL-2026-11-05-001');
    }

    public function test_a_weekly_off_day_is_refused_within_the_advance_days(): void
    {
        $this->atUtc('2026-11-05 04:00:00');

        $this->earlyLeave(['leave_at' => '2026-11-06T07:00:00Z'])->assertStatus(422)
            ->assertJsonPath('error.details.leave_at', 'School is closed on that day.');
    }

    public function test_full_approval_flow_with_release(): void
    {
        $created = $this->earlyLeave()->assertCreated()
            ->assertJsonPath('data.status', 'pending_parent')
            ->assertJsonPath('data.leave_at', '2026-11-04T07:00:00Z')
            ->assertJsonPath('data.reason', 'medical')
            ->assertJsonPath('data.guardian_name', 'Karim Ahmed')
            ->assertJsonPath('data.guardian_mobile', '01811-XXX-111')
            ->assertJsonPath('data.pickup', 'Father (Karim Ahmed)')
            ->assertJsonPath('data.steps.0', ['stage' => 'parent', 'status' => 'pending', 'by' => null, 'at' => null, 'remarks' => null])
            ->assertJsonPath('data.steps.1.status', 'not_reached')
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonMissingPath('data.release_code')
            ->assertJsonPath('data.student_name', 'Rahim');

        $id = $created->json('data.id');

        $this->assertSame('skipped', $this->school()->table('el_requests')->where('id', $id)->value('final_status'));
        $this->assertSame('parent_approval_required', $this->school()->table('sl_notifications')->where('ref_id', $id)->value('event'));

        // Each person acts only at their own step.
        $this->decideEarly($id)->assertStatus(403)->assertJsonPath('message', 'The parent/guardian has not approved yet.');
        $this->withToken($this->guardianToken)->getJson('/api/v1/early-leave/queue')->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.id', $id)
            ->assertJsonPath('data.requests.0.child_id', (string) $this->childId);
        $this->decideEarly($id, 'approve', $this->guardianToken)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['child_id']]]);

        $this->guardianApproves("el-$id")->assertOk()->assertJsonPath('data.status', 'pending_teacher')->assertJsonPath('data.steps.0.by', 'Karim Ahmed');
        $this->guardianApproves($id)->assertStatus(422);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/early-leave/queue')->assertOk()->assertJsonPath('data.requests.0.id', $id);
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/early-leave/queue')->assertOk()->assertJsonCount(0, 'data.requests');
        $this->decideEarly($id, 'approve', $this->otherTeacherToken)->assertStatus(403);

        $this->decideEarly($id)->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.steps.1.by', 'Hasan')
            ->assertJsonMissingPath('data.release_code');

        // The code goes to the guardian only.
        $studentUrl = '/api/v1/students/'.self::STUDENT_ROW."/early-leave/$id";
        $this->withToken($this->studentToken)->getJson($studentUrl)->assertOk()->assertJsonMissingPath('data.release_code');
        $code = $this->withToken($this->guardianToken)->getJson($studentUrl)->assertOk()->json('data.release_code');
        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $code);

        $this->assertStringContainsString("Show code $code at the school gate.", $this->school()->table('sl_notifications')->where('ref_id', $id)->where('event', 'approved')->where('recipient_type', 'guardian')->value('message'));

        $gate = $this->withToken($this->classTeacherToken)->getJson('/api/v1/gate/early-leave')->assertOk()
            ->assertJsonPath('data.date', '2026-11-04')
            ->assertJsonPath('data.requests.0.id', $id)
            ->assertJsonMissingPath('data.requests.0.release_code');
        $this->assertStringNotContainsString($code, $gate->getContent());

        // Any teacher can be on gate duty, as on the web.
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/gate/early-leave')->assertOk()->assertJsonCount(1, 'data.requests');
        $this->withToken($this->otherTeacherToken)->postJson("/api/v1/early-leave/$id/release", ['code' => '000000'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['code']]]);

        $this->withToken($this->otherTeacherToken)->postJson("/api/v1/early-leave/$id/release", ['code' => $code])->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.released_by', 'Karim')
            ->assertJsonPath('data.handed_to', 'Karim Ahmed (Father)');

        $row = $this->school()->table('el_requests')->where('id', $id)->first();
        $this->assertSame('code_and_list', $row->verify_method);
        $this->assertSame(self::OTHER_TEACHER_USER, $row->released_by);

        $this->withToken($this->classTeacherToken)->postJson("/api/v1/early-leave/$id/release", ['code' => $code])->assertStatus(422)->assertJsonPath('message', 'This student was already released.');

        $this->withToken($this->guardianToken)->getJson($studentUrl)->assertOk()->assertJsonPath('data.release_code', null);

        $this->assertSame(['submitted', 'parent_approved', 'teacher_approved', 'code_sent', 'released'], $this->logActions('el', $id));
    }

    public function test_a_guardians_request_skips_the_parent_step_and_can_leave_out_the_guardian(): void
    {
        $id = $this->earlyLeave(['guardian_id' => null], $this->guardianToken)->assertCreated()
            ->assertJsonPath('data.status', 'pending_teacher')
            ->assertJsonPath('data.guardian_name', 'Karim Ahmed')
            ->assertJsonPath('data.guardian_mobile', self::STUDENT_CONTACT)
            ->assertJsonPath('data.steps.0.status', 'approved')
            ->assertJsonPath('data.steps.1.status', 'pending')
            ->json('data.id');

        $this->assertSame(['submitted', 'parent_approved'], $this->logActions('el', $id));
        $this->assertSame('guardian', $this->school()->table('el_requests')->where('id', $id)->value('raised_by_type'));
        $this->assertSame('teacher_approval_required', $this->school()->table('sl_notifications')->where('ref_id', $id)->value('event'));
    }

    public function test_final_approval_step(): void
    {
        $this->school()->table('sl_settings')->update(['el_final_approval' => 1]);

        $id = $this->earlyLeave([], $this->guardianToken)->assertCreated()->assertJsonCount(3, 'data.steps')->assertJsonPath('data.steps.2.stage', 'final')->json('data.id');
        $this->assertSame('not_reached', $this->school()->table('el_requests')->where('id', $id)->value('final_status'));

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/early-leave')->assertOk()->assertJsonPath('data.stages', ['parent', 'teacher', 'final']);

        // An admin's queue only has the final step.
        $this->withToken($this->adminToken)->getJson('/api/v1/early-leave/queue')->assertOk()->assertJsonCount(0, 'data.requests');

        $this->decideEarly($id)->assertOk()->assertJsonPath('data.status', 'pending_final')->assertJsonPath('data.steps.2.status', 'pending');
        $this->decideEarly($id)->assertStatus(403)->assertJsonPath('message', 'Final approval is given by a school admin.');

        $this->withToken($this->adminToken)->getJson('/api/v1/early-leave/queue')->assertOk()->assertJsonPath('data.requests.0.id', $id);
        $this->decideEarly($id, 'approve', $this->adminToken)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.steps.2.by', 'Office Admin');
    }

    public function test_an_admin_can_do_the_teacher_step(): void
    {
        $id = $this->earlyLeave([], $this->guardianToken)->assertCreated()->json('data.id');

        $this->decideEarly($id, 'approve', $this->adminToken)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('office', $this->school()->table('sl_logs')->where('action', 'teacher_approved')->value('actor_type'));
    }

    public function test_reject_closes_the_request(): void
    {
        $id = $this->earlyLeave()->assertCreated()->json('data.id');

        $this->decideEarly($id, 'reject', $this->guardianToken, ['child_id' => $this->childId, 'remarks' => 'No'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.steps.0.status', 'rejected')
            ->assertJsonPath('data.steps.0.remarks', 'No')
            ->assertJsonPath('data.steps.1.status', 'not_reached');

        $this->decideEarly($id)->assertStatus(422)->assertJsonPath('message', 'This request is already closed (rejected).');

        // The day is free again.
        $this->earlyLeave(['leave_at' => '2026-11-04T08:00:00Z'])->assertCreated();
    }

    public function test_a_guardian_cannot_decide_for_a_child_not_on_their_account(): void
    {
        $id = $this->earlyLeave([], $this->otherStudentToken, self::OTHER_STUDENT_ROW)->assertCreated()->json('data.id');

        $this->guardianApproves($id)->assertStatus(404);
        $this->decideEarly($id, 'approve', $this->guardianToken, ['child_id' => 999])->assertStatus(404);

        $this->withToken($this->studentToken)->getJson('/api/v1/early-leave/queue')->assertStatus(403);
        $this->decideEarly($id, 'approve', $this->studentToken)->assertStatus(403);
    }

    public function test_pending_requests_expire_after_their_leave_time(): void
    {
        $id = $this->earlyLeave()->assertCreated()->json('data.id');

        $this->atUtc('2026-11-04 08:00:00');

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW."/early-leave/$id")->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.steps.0.status', 'not_reached');

        $db = $this->school();
        $this->assertSame('expired', $db->table('el_requests')->where('id', $id)->value('parent_status'));
        $this->assertSame(['submitted', 'expired'], $this->logActions('el', $id));
        $this->assertSame(1, $db->table('sl_notifications')->where('ref_id', $id)->where('event', 'expired')->count());

        $this->guardianApproves($id)->assertStatus(422);
    }

    public function test_release_needs_the_right_day_and_the_pickup_on_record(): void
    {
        [$id, $code] = $this->approvedEarlyLeave();
        $url = "/api/v1/early-leave/$id/release";
        $release = fn (array $body) => $this->withToken($this->classTeacherToken)->postJson($url, $body);

        $this->withToken($this->studentToken)->postJson($url, ['code' => $code])->assertStatus(403);
        $this->withToken($this->guardianToken)->postJson($url, ['code' => $code])->assertStatus(403);

        // Even the student's mother counts as a different person, as only the pickup on record may collect.
        $release(['code' => $code, 'handed_to' => 'Salma'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['handed_to']]]);

        $this->school()->table('sl_settings')->update(['el_allow_unregistered' => 1]);
        $release(['code' => $code, 'handed_to' => 'Stranger'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['override_reason']]]);

        $this->atUtc('2026-11-05 04:00:00');
        $release(['code' => $code])->assertStatus(422)->assertJsonPath('message', 'This early leave is for 2026-11-04, not today.');

        $this->atUtc('2026-11-04 05:00:00');
        $release(['code' => $code, 'handed_to' => 'Stranger', 'handed_to_relation' => 'Uncle', 'override_reason' => 'Uncle came'])->assertOk()->assertJsonPath('data.handed_to', 'Stranger (Uncle)');

        $this->assertSame('override', $this->school()->table('el_requests')->where('id', $id)->value('verify_method'));
        $this->assertContains('override', $this->logActions('el', $id));
    }

    public function test_list_only_schools_do_not_need_the_code(): void
    {
        [$id] = $this->approvedEarlyLeave();
        $this->school()->table('sl_settings')->update(['el_pickup_verify' => 'list']);

        $this->withToken($this->classTeacherToken)->postJson("/api/v1/early-leave/$id/release", ['handed_to' => 'father (karim ahmed)'])->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertSame('list', $this->school()->table('el_requests')->where('id', $id)->value('verify_method'));
    }

    public function test_free_text_and_registered_pickups(): void
    {
        $person = $this->school()->table('el_authorized_pickups')->insertGetId([
            'student_id' => self::STUDENT_ROW, 'person_name' => 'Rafiq', 'relation' => 'Uncle', 'mobile' => self::STUDENT_CONTACT, 'status' => 'active', 'created_at' => '2026-10-01 10:00:00',
        ]);

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/early-leave')->assertOk()
            ->assertJsonPath('data.pickups.2', ['id' => "p_$person", 'name' => 'Rafiq', 'relation' => 'Uncle']);

        $id = $this->earlyLeave(['pickup' => "p_$person"])->assertCreated()->assertJsonPath('data.pickup', 'Uncle (Rafiq)')->json('data.id');
        $this->assertSame($person, $this->school()->table('el_requests')->where('id', $id)->value('pickup_id'));

        // Free text is kept as written, as it is on the web.
        $this->earlyLeave(['leave_at' => '2026-11-05T07:00:00Z', 'pickup' => 'চাচা (রফিক)'])->assertCreated()->assertJsonPath('data.pickup', 'চাচা (রফিক)');
    }

    public function test_register_and_summary_are_for_admins(): void
    {
        [$id] = $this->approvedEarlyLeave();

        $this->withToken($this->adminToken)->getJson('/api/v1/early-leave/register')->assertOk()
            ->assertJsonPath('data.date', '2026-11-04')
            ->assertJsonPath('data.requests.0.id', $id)
            ->assertJsonMissingPath('data.requests.0.release_code');

        $this->withToken($this->adminToken)->getJson('/api/v1/early-leave/register?date=2026-11-05')->assertOk()->assertJsonCount(0, 'data.requests');
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/early-leave/summary?year=2026')->assertOk()
            ->assertJsonPath('data.month', 11)
            ->assertJsonPath('data.by_class.0', ['class_label' => 'Eight A', 'total' => 1, 'pending' => 0, 'approved' => 1, 'released' => 0, 'rejected' => 0, 'expired_or_cancelled' => 0]);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/early-leave/register')->assertStatus(403);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/admin/early-leave/summary')->assertStatus(403);
    }

    public function test_staff_role_has_no_leave_access(): void
    {
        $token = $this->tokenFor('01700000028', 'staff', (string) self::ADMIN_USER);

        $this->withToken($token)->getJson('/api/v1/leave/review')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/early-leave/queue')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/leave/summary')->assertStatus(403);
    }

    public function test_changes_are_logged_without_the_reason(): void
    {
        $id = $this->apply(['reason' => 'Private illness'])->assertCreated()->json('data.id');
        $this->decide($id)->assertOk();

        $log = file_get_contents(config('pathshala.activity_log_path').'/activity_2026_11.log');

        $this->assertStringContainsString('"action":"apply_leave"', $log);
        $this->assertStringContainsString('"action":"decide_leave"', $log);
        $this->assertStringNotContainsString('Private illness', $log);
    }
}
