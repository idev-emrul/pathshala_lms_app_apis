<?php

namespace Tests\Feature;

use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Behaviour writes run against pathshala_app_test_behaviour, a copy of the real tables' structure
 * with made-up rows. The real school database is never written to. The clock is fixed at
 * Wednesday 4 November 2026, 10:00 in Dhaka.
 */
class BehaviourApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_behaviour';
    private const TEST_SCHOOL_ID = 83;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const OTHER_CLASS_ID = 9;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const SUBJECT_ID = 143;
    private const CLASS_TEACHER_USER = 500;
    private const CLASS_TEACHER_CODE = 700;
    private const SUBJECT_TEACHER_USER = 501;
    private const SUBJECT_TEACHER_CODE = 701;
    private const OTHER_TEACHER_USER = 502;
    private const ADMIN_USER = 503;
    private const RAHIM = 4723;
    private const MARIA = 4724;
    private const SECTION_B_STUDENT = 4725;
    private const OTHER_CLASS_STUDENT = 4726;
    private const INACTIVE_STUDENT = 4727;
    private const RAHIM_CONTACT = '01811111111';

    private const COPIED_TABLES = [
        'bh_observations', 'bh_ratings', 'bh_recognitions', 'bh_incidents', 'bh_incident_history', 'bh_goals', 'bh_terms', 'bh_options',
        'sl_notifications', 'hw_api_idempotency', 'users', 'teacher_info', 'student_info', 'student_current_status_info',
        'session_info', 'class_info', 'section_info', 'shift_info', 'group_info', 'subject_info', 'class_teacher_info', 'subject_teacher_info',
    ];

    private static bool $schoolCreated = false;

    private string $studentToken;
    private string $otherStudentToken;
    private string $classTeacherToken;
    private string $subjectTeacherToken;
    private string $otherTeacherToken;
    private string $adminToken;
    private string $guardianToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atUtc('2026-11-04 04:00:00');
        $this->prepareSchool();

        $this->studentToken = $this->tokenFor('01700000030', 'student', '9001');
        $this->otherStudentToken = $this->tokenFor('01700000031', 'student', '9002');
        $this->classTeacherToken = $this->tokenFor('01700000032', 'teacher', (string) self::CLASS_TEACHER_USER);
        $this->subjectTeacherToken = $this->tokenFor('01700000033', 'teacher', (string) self::SUBJECT_TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000034', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->adminToken = $this->tokenFor('01700000035', 'admin', (string) self::ADMIN_USER);

        $guardian = AppUser::create(['mobile_number' => '01700000036', 'role' => 'guardian', 'is_verified' => true, 'pin' => 'x']);
        AppGuardianChild::create(['app_user_id' => $guardian->id, 'schl_id' => self::TEST_SCHOOL_ID, 'student_id' => '9001']);
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
            'schl_name' => 'Behaviour Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'bh_test',
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
        config(['database.connections.test_behaviour_school' => ['database' => self::TEST_SCHOOL_DB] + config('database.connections.master')]);

        $connection = DB::connection('test_behaviour_school');

        if ($connection->getDatabaseName() !== self::TEST_SCHOOL_DB) {
            throw new RuntimeException('Behaviour tests must only write to the test school database.');
        }

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('session_info')->insert(['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1]);
        $db->table('class_info')->insert([
            ['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1],
            ['class_code' => self::OTHER_CLASS_ID, 'class_name' => 'Nine', 'class_activation_status' => 1, 'class_position' => 2],
        ]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_A, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => self::SECTION_B, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('subject_info')->insert(['subject_code' => self::SUBJECT_ID, 'subject_name' => 'Math']);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::CLASS_TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1],
            ['teacher_code' => self::SUBJECT_TEACHER_CODE, 'teacher_name' => 'Karim', 'teacher_active_status' => 1],
            ['teacher_code' => 702, 'teacher_name' => 'Nobody', 'teacher_active_status' => 1],
        ]);
        $db->table('users')->insert([
            ['id' => self::CLASS_TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::CLASS_TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::SUBJECT_TEACHER_USER, 'username' => 'karim', 'user_full_name' => 'Karim', 'teacher_code' => self::SUBJECT_TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::OTHER_TEACHER_USER, 'username' => 'nobody', 'user_full_name' => 'Nobody', 'teacher_code' => 702, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::ADMIN_USER, 'username' => 'office', 'user_full_name' => 'Office Admin', 'teacher_code' => 0, 'user_type' => 'Admin', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
        ]);
        // Class teacher of Eight A only, and a subject teacher of all of Eight.
        $db->table('class_teacher_info')->insert(['teacher_code' => self::CLASS_TEACHER_CODE, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'shift_code' => 1, 'session_code' => self::SESSION]);
        $db->table('subject_teacher_info')->insert(['teacher_code' => self::SUBJECT_TEACHER_CODE, 'subject_code' => self::SUBJECT_ID, 'class_code' => self::CLASS_ID, 'section_code' => 0, 'shift_code' => 0, 'session_code' => self::SESSION]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9001, 'student_name' => 'Rahim', 'contact_number' => self::RAHIM_CONTACT],
            ['student_registration_code' => 9002, 'student_name' => 'Maria', 'contact_number' => null],
            ['student_registration_code' => 9003, 'student_name' => 'Nadia', 'contact_number' => null],
            ['student_registration_code' => 9004, 'student_name' => 'Arif', 'contact_number' => null],
            ['student_registration_code' => 9005, 'student_name' => 'Old', 'contact_number' => null],
        ]);
        $row = fn (int $id, int $code, int $class, int $section, int $roll, int $active = 1) => [
            'current_status_info_id' => $id, 'student_registration_code' => $code, 'session_code' => self::SESSION, 'class_code' => $class,
            'section_code' => $section, 'shift_code' => 1, 'roll_number' => $roll, 'active_status' => $active,
        ];
        $db->table('student_current_status_info')->insert([
            $row(self::RAHIM, 9001, self::CLASS_ID, self::SECTION_A, 12),
            $row(self::MARIA, 9002, self::CLASS_ID, self::SECTION_A, 3),
            $row(self::SECTION_B_STUDENT, 9003, self::CLASS_ID, self::SECTION_B, 1),
            $row(self::OTHER_CLASS_STUDENT, 9004, self::OTHER_CLASS_ID, self::SECTION_A, 1),
            $row(self::INACTIVE_STUDENT, 9005, self::CLASS_ID, self::SECTION_A, 9, 3),
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
            'school_name' => 'Behaviour Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }

    private function quickEntry(array|string $ratings, array $extra = [], ?string $token = null, int $student = self::RAHIM): TestResponse
    {
        return $this->withToken($token ?? $this->classTeacherToken)->postJson('/api/v1/behaviour/quick-entry', $extra + ['student_id' => (string) $student, 'ratings' => $ratings]);
    }

    private function incident(array $overrides = [], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->classTeacherToken)->postJson('/api/v1/behaviour/incidents', $overrides + [
            'student_id' => (string) self::RAHIM,
            'occurred_at' => '2026-11-03T06:30:00Z',
            'location' => 'Room 204',
            'category' => 'Disturbance',
            'description' => 'Private details of what happened',
            'action_taken' => 'Talked after class',
            'parent_contacted' => false,
        ]);
    }

    private function overview(?string $token = null, int $student = self::RAHIM): TestResponse
    {
        return $this->withToken($token ?? $this->studentToken)->getJson("/api/v1/students/$student/behaviour");
    }

    private function incidentRow(string $id): object
    {
        return $this->school()->table('bh_incidents')->where('id', str_replace('inc-', '', $id))->first();
    }

    public function test_tests_never_use_the_real_school_for_writes(): void
    {
        $this->assertSame(self::TEST_SCHOOL_DB, $this->school()->getDatabaseName());
        $this->assertNotSame(self::SCHOOL_DB, self::TEST_SCHOOL_DB);
    }

    public function test_quick_entry_stores_what_the_web_pages_read(): void
    {
        $this->quickEntry(['responsibility' => 'excellent', 'punctuality' => 'good'], ['comment' => 'Well done', 'subject_id' => (string) self::SUBJECT_ID])
            ->assertCreated()
            ->assertJsonPath('error', null);

        $db = $this->school();
        $row = $db->table('bh_observations')->first();

        $this->assertSame(self::RAHIM, $row->student_id);
        $this->assertSame(self::SESSION, $row->session_id);
        $this->assertSame(self::CLASS_ID, $row->class_id);
        $this->assertSame(self::SECTION_A, $row->section_id);
        $this->assertSame(self::SUBJECT_ID, $row->subject_id);
        $this->assertSame(self::CLASS_TEACHER_CODE, $row->teacher_id);
        $this->assertSame(self::CLASS_TEACHER_USER, $row->created_by);
        $this->assertSame('2026-11-04', $row->observed_on);
        $this->assertSame(0, $row->support_required);

        $this->assertSame(
            ['punctuality' => 75, 'responsibility' => 100],
            $db->table('bh_ratings')->where('observation_id', $row->id)->orderBy('indicator')->pluck('score', 'indicator')->all(),
        );

        // A rating never tells the family anything by itself.
        $this->assertSame(0, $db->table('sl_notifications')->count());
    }

    public function test_quick_entry_rules(): void
    {
        $this->quickEntry([])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['ratings']]]);
        $this->quickEntry(['kindness' => 'good'])->assertStatus(422)->assertJsonPath('error.details', ['ratings.kindness' => 'Unknown indicator.']);
        $this->quickEntry(['respect' => 'great'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['ratings.respect']]]);
        $this->quickEntry(['respect' => 'good'], ['observed_on' => '2026-11-05'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['observed_on']]]);
        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/quick-entry', ['ratings' => ['respect' => 'good']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['student_id']]]);

        $this->assertSame(0, $this->school()->table('bh_observations')->count());

        // ratings can arrive as one JSON string, and an earlier date is fine.
        $this->quickEntry('{"respect":"good"}', ['observed_on' => '2026-11-01'])->assertCreated();
        $this->assertSame('2026-11-01', $this->school()->table('bh_observations')->value('observed_on'));
    }

    public function test_support_required_is_only_sent_when_ticked_and_gives_no_details(): void
    {
        $this->quickEntry(['respect' => 'needs_support'])->assertCreated();
        $this->assertSame(0, $this->school()->table('sl_notifications')->count());

        $this->quickEntry(['respect' => 'good'], ['support_required' => true, 'comment' => 'Private comment'])->assertCreated();

        $message = $this->school()->table('sl_notifications')->first();
        $this->assertSame('bh', $message->module);
        $this->assertSame('support_required', $message->event);
        $this->assertSame('guardian', $message->recipient_type);
        $this->assertSame(self::RAHIM_CONTACT, $message->mobile);
        $this->assertSame('sms', $message->channel);
        $this->assertStringNotContainsString('Private comment', $message->message);
    }

    public function test_recognition(): void
    {
        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/recognition', ['student_id' => (string) self::RAHIM])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['tag']]]);
        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/recognition', ['student_id' => (string) self::RAHIM, 'tag' => 'nothing'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['tag']]]);

        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/recognition', ['student_id' => 'student-'.self::RAHIM, 'tag' => 'helpful_student', 'note' => 'Helped in Math'])
            ->assertCreated();

        $this->overview()->assertOk()
            ->assertJsonPath('data.recognitions.0.tag', 'helpful_student')
            ->assertJsonPath('data.recognitions.0.date', '2026-11-04')
            ->assertJsonPath('data.recognitions.0.given_by', 'Hasan')
            ->assertJsonPath('data.recognitions.0.note', 'Helped in Math');

        $this->assertSame(
            [['positive_recognition', 'guardian', 'sms'], ['positive_recognition', 'student', 'inapp']],
            $this->school()->table('sl_notifications')->orderBy('id')->get()->map(fn ($n) => [$n->event, $n->recipient_type, $n->channel])->all(),
        );
    }

    public function test_a_teacher_only_reaches_their_assigned_students(): void
    {
        // Class teacher of Eight A.
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/teacher/behaviour/roster')->assertOk()
            ->assertJsonPath('data.0', ['student_id' => (string) self::MARIA, 'name' => 'Maria', 'roll' => '3', 'class' => 'Eight', 'section' => 'A'])
            ->assertJsonPath('data.1.student_id', (string) self::RAHIM)
            ->assertJsonCount(2, 'data');

        // A subject teacher with section 0 teaches all of Eight, but not Nine.
        $this->withToken($this->subjectTeacherToken)->getJson('/api/v1/teacher/behaviour/roster')->assertOk()->assertJsonCount(3, 'data');
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/teacher/behaviour/roster')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->adminToken)->getJson('/api/v1/teacher/behaviour/roster')->assertOk()->assertJsonCount(4, 'data');

        $this->withToken($this->adminToken)->getJson('/api/v1/teacher/behaviour/roster?class_id='.self::OTHER_CLASS_ID)->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->adminToken)->getJson('/api/v1/teacher/behaviour/roster?search=Arif')->assertOk()->assertJsonCount(1, 'data');

        $this->quickEntry(['respect' => 'good'], [], $this->classTeacherToken, self::SECTION_B_STUDENT)->assertStatus(403);
        $this->quickEntry(['respect' => 'good'], [], $this->subjectTeacherToken, self::SECTION_B_STUDENT)->assertCreated();
        $this->quickEntry(['respect' => 'good'], [], $this->subjectTeacherToken, self::OTHER_CLASS_STUDENT)->assertStatus(403);
        $this->quickEntry(['respect' => 'good'], [], $this->otherTeacherToken)->assertStatus(403);

        // Not active any more.
        $this->quickEntry(['respect' => 'good'], [], $this->classTeacherToken, self::INACTIVE_STUDENT)->assertStatus(403);
        $this->quickEntry(['respect' => 'good'], [], $this->adminToken, self::INACTIVE_STUDENT)->assertStatus(403);
    }

    public function test_who_can_read_an_overview(): void
    {
        $this->overview($this->studentToken)->assertOk();
        $this->overview($this->otherStudentToken)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->overview($this->guardianToken)->assertOk();
        $this->overview($this->guardianToken, self::MARIA)->assertStatus(403)->assertJsonPath('error.code', 'not_linked');
        $this->overview($this->classTeacherToken)->assertOk();
        $this->overview($this->otherTeacherToken)->assertStatus(403);
        $this->overview($this->adminToken)->assertOk();

        $staff = $this->tokenFor('01700000037', 'staff', (string) self::ADMIN_USER);
        $this->overview($staff)->assertStatus(403);
    }

    public function test_overview_without_any_ratings_is_empty_but_has_all_ten_indicators(): void
    {
        $this->overview()->assertOk()
            ->assertJsonPath('data.overall_percent', null)
            ->assertJsonPath('data.overall_rating', null)
            ->assertJsonPath('data.support_required', false)
            ->assertJsonCount(10, 'data.indicators')
            ->assertJsonPath('data.indicators.0', ['type' => 'responsibility', 'rating' => null, 'percent' => null, 'previous_percent' => null])
            ->assertJsonPath('data.trend', [])
            ->assertJsonPath('data.recognitions', [])
            ->assertJsonPath('data.feedback', [])
            ->assertJsonPath('data.goals', [])
            ->assertJsonPath('data.period.label', 'নভেম্বর 2026')
            ->assertJsonPath('data.previous_period.label', 'অক্টোবর 2026');
    }

    public function test_percents_follow_the_plans_sums_by_term(): void
    {
        $db = $this->school();
        $db->table('bh_terms')->insert([
            ['session_id' => self::SESSION, 'name' => '1st term', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30', 'sort_order' => 1],
            ['session_id' => self::SESSION, 'name' => '2nd term', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'sort_order' => 2],
        ]);

        $this->quickEntry(['responsibility' => 'needs_support'], ['observed_on' => '2026-08-01'])->assertCreated();

        // Responsibility (100 + 75) / 2 = 87.5, respect 50, so overall (87.5 + 50) / 2 = 68.8.
        $this->quickEntry(['responsibility' => 'excellent', 'respect' => 'developing'], ['observed_on' => '2026-11-01'])->assertCreated();
        $this->quickEntry(['responsibility' => 'good'], ['observed_on' => '2026-11-02'])->assertCreated();

        $data = $this->overview()->assertOk()
            ->assertJsonPath('data.period', ['label' => '2nd term', 'from' => '2026-10-01', 'to' => '2026-12-31'])
            ->assertJsonPath('data.previous_period.label', '1st term')
            ->json('data');

        $this->assertEquals(68.8, $data['overall_percent']);
        $this->assertSame('developing', $data['overall_rating']);
        $this->assertSame('responsibility', $data['indicators'][0]['type']);
        $this->assertEquals(87.5, $data['indicators'][0]['percent']);
        $this->assertSame('excellent', $data['indicators'][0]['rating']);
        $this->assertEquals(25, $data['indicators'][0]['previous_percent']);
        $this->assertEquals(50, $data['indicators'][1]['percent']);
        $this->assertSame('needs_support', $data['indicators'][1]['rating']);
        $this->assertNull($data['indicators'][1]['previous_percent']);

        $this->assertCount(2, $data['trend']);
        $this->assertSame('1st term', $data['trend'][0]['label']);
        $this->assertEquals(25, $data['trend'][0]['percent']);
        $this->assertSame('2nd term', $data['trend'][1]['label']);
        $this->assertEquals(68.8, $data['trend'][1]['percent']);
    }

    public function test_deleted_entries_and_turned_off_indicators_are_left_out(): void
    {
        $db = $this->school();

        $this->quickEntry(['responsibility' => 'excellent', 'respect' => 'needs_support'])->assertCreated();
        $this->quickEntry(['teamwork' => 'needs_support'])->assertCreated();
        $db->table('bh_observations')->orderByDesc('id')->limit(1)->update(['is_deleted' => 1]);

        $db->table('bh_options')->insert([
            ['type' => 'indicator', 'code' => 'responsibility', 'name_en' => 'Responsibility', 'sort_order' => 1, 'is_active' => 1],
            ['type' => 'indicator', 'code' => 'respect', 'name_en' => 'Respect', 'sort_order' => 2, 'is_active' => 0],
            ['type' => 'indicator', 'code' => 'kindness', 'name_en' => 'Kindness', 'sort_order' => 3, 'is_active' => 1],
        ]);

        $data = $this->overview()->assertOk()->assertJsonCount(2, 'data.indicators')->json('data');

        $this->assertSame(['responsibility', 'kindness'], array_column($data['indicators'], 'type'));
        $this->assertEquals(100, $data['overall_percent']);

        // The school's own list decides what can be rated.
        $this->quickEntry(['kindness' => 'good'])->assertCreated();
        $this->quickEntry(['respect' => 'good'])->assertStatus(422);
    }

    public function test_incidents_never_reach_students_or_guardians(): void
    {
        $this->incident()->assertCreated();

        foreach ([$this->studentToken, $this->guardianToken] as $token) {
            $content = $this->overview($token)->assertOk()->assertJsonMissingPath('data.incidents')->getContent();

            $this->assertStringNotContainsString('Private details', $content);
            $this->assertStringNotContainsString('Disturbance', $content);
        }

        $this->withToken($this->studentToken)->getJson('/api/v1/behaviour/incidents')->assertStatus(403);
        $this->withToken($this->guardianToken)->getJson('/api/v1/behaviour/incidents')->assertStatus(403);
    }

    public function test_overview_lists_feedback_and_goals(): void
    {
        $this->quickEntry(['respect' => 'good'], ['comment' => 'Good work', 'subject_id' => (string) self::SUBJECT_ID])->assertCreated();
        $this->school()->table('bh_goals')->insert([
            ['student_id' => self::RAHIM, 'title' => 'Done goal', 'status' => 'achieved'],
            ['student_id' => self::RAHIM, 'title' => 'Open goal', 'status' => 'active'],
        ]);

        $this->overview()->assertOk()
            ->assertJsonPath('data.feedback.0.subject', 'Math')
            ->assertJsonPath('data.feedback.0.teacher', 'Hasan')
            ->assertJsonPath('data.feedback.0.comment', 'Good work')
            ->assertJsonPath('data.feedback.0.date', '2026-11-04')
            ->assertJsonPath('data.goals.0.title', 'Open goal')
            ->assertJsonPath('data.goals.0.status', 'active')
            ->assertJsonPath('data.goals.1.status', 'achieved');
    }

    public function test_log_and_list_an_incident(): void
    {
        $id = $this->incident(['parent_contacted' => true])->assertCreated()->json('data.id');
        $this->assertMatchesRegularExpression('/^inc-\d+$/', $id);

        // The status and reporter come from the server, whatever the app sends.
        $second = $this->incident(['status' => 'resolved', 'reported_by' => 'Someone else', 'occurred_at' => '2026-11-04T01:00:00Z'])->assertCreated()->json('data.id');

        $list = $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/incidents')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.status', 'reported')
            ->assertJsonPath('data.0.reported_by', 'Hasan')
            ->assertJsonPath('data.1', [
                'id' => $id,
                'student_id' => (string) self::RAHIM,
                'student_name' => 'Rahim',
                'occurred_at' => '2026-11-03T06:30:00Z',
                'location' => 'Room 204',
                'category' => 'Disturbance',
                'description' => 'Private details of what happened',
                'reported_by' => 'Hasan',
                'status' => 'reported',
                'action_taken' => 'Talked after class',
                'parent_contacted' => true,
                'follow_up_note' => '',
                'support_required' => false,
                'history' => [[
                    'at' => '2026-11-04T04:00:00Z',
                    'by' => 'Hasan',
                    'status' => 'reported',
                    'note' => 'Incident reported. Action taken: Talked after class',
                ]],
            ]);

        // Stored in school time.
        $this->assertSame('2026-11-03 12:30:00', $this->incidentRow($id)->occurred_at);
        $this->assertSame(2, count($list->json('data')));
    }

    public function test_incident_rules_and_scope(): void
    {
        $this->incident(['occurred_at' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['occurred_at']]]);
        $this->incident(['occurred_at' => 'not a date'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['occurred_at']]]);
        $this->incident(['occurred_at' => '2026-11-05T01:00:00Z'])->assertStatus(422)->assertJsonPath('error.details.occurred_at', 'Cannot be in the future.');
        $this->incident(['category' => ' '])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['category']]]);
        $this->incident(['description' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['description']]]);
        $this->incident([], $this->otherTeacherToken)->assertStatus(403);

        $this->incident()->assertCreated();
        $this->incident(['student_id' => (string) self::OTHER_CLASS_STUDENT], $this->adminToken)->assertCreated();

        // A teacher sees their students' incidents, and an admin the whole school.
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/incidents')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/behaviour/incidents')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents')->assertOk()->assertJsonCount(2, 'data');

        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents?status=resolved')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents?status=bad')->assertStatus(422);
        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents?student_id='.self::RAHIM)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_incident_with_support_required_tells_the_guardian_nothing_of_the_details(): void
    {
        $this->incident(['support_required' => true])->assertCreated();

        $message = $this->school()->table('sl_notifications')->first();
        $this->assertSame('support_required', $message->event);
        $this->assertStringNotContainsString('Private details', $message->message);
        $this->assertStringNotContainsString('Disturbance', $message->message);

        $this->overview()->assertOk()->assertJsonPath('data.support_required', true);
    }

    public function test_only_an_admin_can_change_an_incident_and_it_only_moves_forward(): void
    {
        $id = $this->incident()->assertCreated()->json('data.id');
        $url = "/api/v1/behaviour/incidents/$id";
        $patch = fn (array $body, ?string $token = null) => $this->withToken($token ?? $this->adminToken)->patchJson($url, $body);

        $patch(['status' => 'under_review'], $this->classTeacherToken)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $patch(['status' => 'under_review'], $this->studentToken)->assertStatus(403);

        $patch(['status' => 'bad'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['status']]]);
        $patch([])->assertStatus(422)->assertJsonPath('message', 'Nothing to change: send a new status or a follow_up_note.');
        $patch(['status' => 'resolved'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['follow_up_note']]]);

        $patch(['status' => 'under_review', 'follow_up_note' => 'First note'])->assertOk();
        $this->assertSame('under_review', $this->incidentRow($id)->status);

        $patch(['status' => 'reported'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['status']]]);

        // A note alone keeps the status.
        $patch(['follow_up_note' => 'Second note'])->assertOk();

        $patch(['status' => 'resolved', 'follow_up_note' => 'Final note', 'parent_contacted' => true])->assertOk();

        $row = $this->incidentRow($id);
        $this->assertSame('resolved', $row->status);
        $this->assertSame('Final note', $row->follow_up_note);
        $this->assertSame(1, $row->parent_contacted);
        $this->assertSame(self::ADMIN_USER, $row->updated_by);

        // Earlier notes are never overwritten in the trail.
        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents')->assertOk()
            ->assertJsonPath('data.0.follow_up_note', 'Final note')
            ->assertJsonCount(4, 'data.0.history')
            ->assertJsonPath('data.0.history.1', ['at' => '2026-11-04T04:00:00Z', 'by' => 'Office Admin', 'status' => 'under_review', 'note' => "Status: Reported → Under Review.\nFirst note"])
            ->assertJsonPath('data.0.history.2.note', 'Second note')
            ->assertJsonPath('data.0.history.3.note', "Status: Under Review → Resolved.\nFinal note\nParent contacted.");

        $this->withToken($this->adminToken)->patchJson('/api/v1/behaviour/incidents/999', ['status' => 'resolved'])->assertStatus(404);
    }

    public function test_the_same_note_twice_in_one_second_still_adds_to_the_trail(): void
    {
        $id = $this->incident()->assertCreated()->json('data.id');
        $url = "/api/v1/behaviour/incidents/$id";

        $this->withToken($this->adminToken)->patchJson($url, ['follow_up_note' => 'Same note'])->assertOk();
        $this->withToken($this->adminToken)->patchJson($url, ['follow_up_note' => 'Same note'])->assertOk();

        $this->assertSame(3, $this->school()->table('bh_incident_history')->where('incident_id', str_replace('inc-', '', $id))->count());
    }

    public function test_guardian_messages_when_an_admin_flags_or_resolves(): void
    {
        $id = $this->incident()->assertCreated()->json('data.id');
        $url = "/api/v1/behaviour/incidents/$id";
        $db = $this->school();

        $this->withToken($this->adminToken)->patchJson($url, ['support_required' => true])->assertOk();
        $this->assertSame(1, $db->table('sl_notifications')->where('event', 'support_required')->count());

        // Setting it again changes nothing, so no second message and nothing to log.
        $this->withToken($this->adminToken)->patchJson($url, ['support_required' => true])->assertStatus(422);
        $this->assertSame(1, $db->table('sl_notifications')->where('event', 'support_required')->count());

        $this->withToken($this->adminToken)->patchJson($url, ['status' => 'resolved', 'follow_up_note' => 'Closed'])->assertOk();
        $this->assertSame(0, $db->table('sl_notifications')->where('event', 'important_update')->count());

        $other = $this->incident()->assertCreated()->json('data.id');
        $this->withToken($this->adminToken)->patchJson("/api/v1/behaviour/incidents/$other", ['status' => 'resolved', 'follow_up_note' => 'Closed', 'notify_guardian' => true])->assertOk();
        $this->assertSame(1, $db->table('sl_notifications')->where('event', 'important_update')->count());
    }

    public function test_the_same_idempotency_key_changes_nothing(): void
    {
        $key = ['Idempotency-Key' => 'bh-1'];
        $body = ['student_id' => (string) self::RAHIM, 'ratings' => ['respect' => 'good']];

        $this->withToken($this->classTeacherToken)->withHeaders($key)->postJson('/api/v1/behaviour/quick-entry', $body)->assertCreated();
        $this->withToken($this->classTeacherToken)->withHeaders($key)->postJson('/api/v1/behaviour/quick-entry', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->school()->table('bh_observations')->count());

        $first = $this->withToken($this->classTeacherToken)->withHeaders(['Idempotency-Key' => 'bh-2'])->postJson('/api/v1/behaviour/incidents', [
            'student_id' => (string) self::RAHIM, 'occurred_at' => '2026-11-03T06:30:00Z', 'category' => 'A', 'description' => 'B',
        ])->assertCreated();
        $again = $this->withToken($this->classTeacherToken)->withHeaders(['Idempotency-Key' => 'bh-2'])->postJson('/api/v1/behaviour/incidents', [
            'student_id' => (string) self::RAHIM, 'occurred_at' => '2026-11-03T06:30:00Z', 'category' => 'A', 'description' => 'B',
        ])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, $this->school()->table('bh_incidents')->count());
    }

    public function test_a_failed_request_frees_its_key(): void
    {
        $key = ['Idempotency-Key' => 'bh-3'];

        $this->withToken($this->classTeacherToken)->withHeaders($key)->postJson('/api/v1/behaviour/quick-entry', ['student_id' => (string) self::RAHIM, 'ratings' => []])->assertStatus(422);
        $this->withToken($this->classTeacherToken)->withHeaders($key)->postJson('/api/v1/behaviour/quick-entry', ['student_id' => (string) self::RAHIM, 'ratings' => ['respect' => 'good']])->assertCreated();
    }

    public function test_dashboard_is_for_admins(): void
    {
        $this->quickEntry(['respect' => 'needs_support'], ['observed_on' => '2026-10-10'])->assertCreated();
        $this->quickEntry(['respect' => 'excellent', 'teamwork' => 'good'], ['support_required' => true])->assertCreated();
        $this->quickEntry(['respect' => 'good'], ['observed_on' => '2026-10-11'], null, self::MARIA)->assertCreated();
        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/recognition', ['student_id' => (string) self::RAHIM, 'tag' => 'team_player'])->assertCreated();

        $open = $this->incident(['support_required' => true])->assertCreated()->json('data.id');
        $closed = $this->incident()->assertCreated()->json('data.id');
        $this->withToken($this->adminToken)->patchJson("/api/v1/behaviour/incidents/$closed", ['status' => 'resolved', 'follow_up_note' => 'Done'])->assertOk();

        $data = $this->withToken($this->adminToken)->getJson('/api/v1/admin/behaviour/dashboard')->assertOk()
            ->assertJsonPath('data.period.key', 'month-2026-11')
            ->assertJsonPath('data.previous_period.key', 'month-2026-10')
            ->assertJsonCount(12, 'data.periods')
            ->assertJsonPath('data.total_observations', 1)
            ->assertJsonPath('data.positive_recognition', 1)
            ->assertJsonPath('data.improvement_cases', 1)
            ->assertJsonPath('data.support_required', 2)
            ->assertJsonPath('data.under_review', 0)
            ->assertJsonPath('data.resolved_cases', 1)
            ->assertJsonPath('data.recognition_tags', ['team_player' => 1])
            ->assertJsonPath('data.class_summary.0.name', 'Eight · A')
            ->assertJsonPath('data.class_summary.0.students', 1)
            ->assertJsonPath('data.class_summary.0.by_rating.excellent', 1)
            ->assertJsonCount(1, 'data.follow_up')
            ->assertJsonPath('data.follow_up.0.id', $open)
            ->assertJsonPath('data.follow_up.0.follow_up_due', false)
            ->json('data');

        $this->assertEquals(100, $data['indicator_average']['respect']);
        $this->assertEquals(75, $data['indicator_average']['teamwork']);

        // No change for more than 3 days.
        $this->atUtc('2026-11-08 04:00:00');
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/behaviour/dashboard')->assertOk()->assertJsonPath('data.follow_up.0.follow_up_due', true);

        // A period key from the list can be chosen.
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/behaviour/dashboard?current=month-2026-10')->assertOk()
            ->assertJsonPath('data.total_observations', 2)
            ->assertJsonPath('data.previous_period.key', 'month-2026-09');

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/admin/behaviour/dashboard')->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/admin/behaviour/dashboard')->assertStatus(403);
    }

    public function test_student_report(): void
    {
        $this->quickEntry(['respect' => 'needs_support'], ['observed_on' => '2026-10-10'])->assertCreated();
        $this->quickEntry(['respect' => 'excellent'], ['subject_id' => (string) self::SUBJECT_ID])->assertCreated();
        $this->quickEntry(['respect' => 'developing'])->assertCreated();

        $data = $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/reports?scope=student&period=month&student_id='.self::RAHIM)->assertOk()
            ->assertJsonPath('data.scope', 'student')
            ->assertJsonPath('data.student_name', 'Rahim')
            ->assertJsonPath('data.period.key', 'month-2026-11')
            ->json('data');

        $this->assertEquals(75, $data['overall_percent']);
        $this->assertEquals(25, $data['previous_percent']);
        $this->assertSame('respect', $data['indicators'][1]['type']);
        $this->assertSame(2, $data['indicators'][1]['ratings']);

        $bySubject = $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/reports?scope=student&period=month&subject_id='.self::SUBJECT_ID.'&student_id='.self::RAHIM)->assertOk()->json('data');
        $this->assertEquals(100, $bySubject['overall_percent']);

        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/behaviour/reports?scope=student&student_id='.self::RAHIM)->assertStatus(403);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/reports?scope=student')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['student_id']]]);
    }

    public function test_class_report_keeps_a_teacher_to_their_students(): void
    {
        $this->quickEntry(['respect' => 'excellent'])->assertCreated();
        $this->quickEntry(['respect' => 'good'], [], null, self::MARIA)->assertCreated();
        $this->quickEntry(['respect' => 'developing'], [], $this->adminToken, self::SECTION_B_STUDENT)->assertCreated();

        $data = $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/reports?period=month&class_id='.self::CLASS_ID)->assertOk()
            ->assertJsonPath('data.scope', 'class')
            ->assertJsonCount(3, 'data.students')
            ->json('data');

        $respect = collect($data['indicator_average'])->firstWhere('type', 'respect');
        $this->assertEquals(75, $respect['percent']);

        $this->withToken($this->classTeacherToken)->getJson('/api/v1/behaviour/reports?period=month&class_id='.self::CLASS_ID)->assertOk()
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.name', 'Maria');

        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/behaviour/reports?period=month&class_id='.self::CLASS_ID)->assertOk()->assertJsonCount(0, 'data.students');
        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/reports')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['class_id']]]);
    }

    public function test_a_banned_teacher_or_admin_loses_access_at_once(): void
    {
        $this->school()->table('users')->whereIn('id', [self::ADMIN_USER, self::CLASS_TEACHER_USER])->update(['banned' => 1]);

        $this->withToken($this->adminToken)->getJson('/api/v1/behaviour/incidents')->assertStatus(403);
        $this->withToken($this->classTeacherToken)->getJson('/api/v1/teacher/behaviour/roster')->assertStatus(403);
    }

    public function test_guests_and_the_staff_role_are_refused(): void
    {
        $staff = $this->tokenFor('01700000038', 'staff', (string) self::ADMIN_USER);

        $this->withToken($staff)->getJson('/api/v1/teacher/behaviour/roster')->assertStatus(403);
        $this->withToken($staff)->getJson('/api/v1/behaviour/incidents')->assertStatus(403);
        $this->withToken($staff)->getJson('/api/v1/admin/behaviour/dashboard')->assertStatus(403);

        AppUser::where('mobile_number', '01700000030')->update(['is_verified' => false]);
        $this->overview()->assertStatus(403);

        $this->withoutToken()->getJson('/api/v1/teacher/behaviour/roster')->assertStatus(401);
    }

    public function test_a_school_without_the_tables_answers_503(): void
    {
        $this->school()->statement('RENAME TABLE bh_terms TO bh_terms_hidden');

        try {
            $this->overview()->assertStatus(503)->assertJsonPath('error.code', 'not_set_up');
        } finally {
            $this->school()->statement('RENAME TABLE bh_terms_hidden TO bh_terms');
        }
    }

    public function test_cached_reads_answer_304(): void
    {
        $etag = $this->overview()->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withToken($this->studentToken)->withHeader('If-None-Match', $etag)->getJson('/api/v1/students/'.self::RAHIM.'/behaviour')->assertStatus(304);
    }

    public function test_changes_are_logged_without_any_content(): void
    {
        $this->quickEntry(['respect' => 'good'], ['comment' => 'Private comment'])->assertCreated();
        $this->withToken($this->classTeacherToken)->postJson('/api/v1/behaviour/recognition', ['student_id' => (string) self::RAHIM, 'tag' => 'team_player', 'note' => 'Private note'])->assertCreated();
        $id = $this->incident()->assertCreated()->json('data.id');
        $this->withToken($this->adminToken)->patchJson("/api/v1/behaviour/incidents/$id", ['follow_up_note' => 'Private follow up'])->assertOk();

        $log = file_get_contents(config('pathshala.activity_log_path').'/activity_2026_11.log');

        foreach (['behaviour_quick_entry', 'behaviour_recognition', 'create_incident', 'update_incident'] as $action) {
            $this->assertStringContainsString("\"action\":\"$action\"", $log);
        }

        foreach (['Private comment', 'Private note', 'Private details', 'Private follow up'] as $text) {
            $this->assertStringNotContainsString($text, $log);
        }
    }

    public function test_session_permissions_include_behaviour(): void
    {
        $this->assertContains('behaviour.view', config('pathshala.permissions.student'));
        $this->assertContains('behaviour.view', config('pathshala.permissions.guardian'));
        $this->assertContains('behaviour.manage', config('pathshala.permissions.teacher'));
        $this->assertNotContains('behaviour.resolve', config('pathshala.permissions.teacher'));
        $this->assertContains('behaviour.resolve', config('pathshala.permissions.admin'));
        $this->assertSame([], config('pathshala.permissions.staff'));
    }
}
