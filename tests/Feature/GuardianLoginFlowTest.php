<?php

namespace Tests\Feature;

use App\Models\AppGuardianChild;
use App\Models\AppLoginAttempt;
use App\Models\AppUser;
use App\Models\AppUserLog;
use App\Services\ActivityLogService;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A guardian's children can be in different schools, so two test schools are used, each a copy
 * of the real tables' structure with made-up rows. The real school database is never written to.
 */
class GuardianLoginFlowTest extends TestCase
{
    private const SCHOOL_A_DB = 'pathshala_app_test_guardian_a';
    private const SCHOOL_B_DB = 'pathshala_app_test_guardian_b';
    private const SCHOOL_A = 78;
    private const SCHOOL_B = 79;

    private const CLASS_A = 8;
    private const CLASS_B = 3;

    private const RAHIM = '9001';
    private const MARIA = '9002';
    private const NO_CONTACT = '9003';
    private const LEFT_SCHOOL = '9004';
    private const NADIA = '5001';

    private const RAHIM_CONTACT = '01811111111';
    private const NADIA_CONTACT = '01822222222';

    private const COPIED_TABLES = ['student_info', 'student_current_status_info', 'session_info', 'class_info', 'section_info', 'shift_info', 'group_info'];

    private static bool $schoolsCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchools();
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('school_information')->whereIn('schl_id', [self::SCHOOL_A, self::SCHOOL_B])->delete();

        parent::tearDown();
    }

    private function prepareSchools(): void
    {
        $master = DB::connection('master');

        if (! self::$schoolsCreated) {
            // Some old tables have zero-date defaults, which strict mode refuses to copy.
            $sqlMode = $master->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            $master->statement("SET SESSION sql_mode = ''");

            foreach ([self::SCHOOL_A_DB, self::SCHOOL_B_DB] as $database) {
                $master->statement("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4");

                foreach (self::COPIED_TABLES as $table) {
                    $master->statement("DROP TABLE IF EXISTS `$database`.`$table`");
                    $master->statement("CREATE TABLE `$database`.`$table` LIKE `".self::SCHOOL_DB."`.`$table`");
                    $this->relaxColumns($master, $database, $table);
                }
            }

            $master->statement('SET SESSION sql_mode = ?', [$sqlMode]);

            self::$schoolsCreated = true;
        }

        $master->table('school_information')->whereIn('schl_id', [self::SCHOOL_A, self::SCHOOL_B])->delete();

        foreach ([self::SCHOOL_A => [self::SCHOOL_A_DB, 'School A'], self::SCHOOL_B => [self::SCHOOL_B_DB, 'School B']] as $id => [$database, $name]) {
            $master->table('school_information')->insert([
                'schl_id' => $id,
                'schl_name' => $name,
                'schl_url' => 'http://example.test',
                'schl_code' => 'guardian_'.$id,
                'api_code' => 'secret-api-code',
                'school_secret' => 'secret',
                'db_name' => $database,
            ]);

            foreach (self::COPIED_TABLES as $table) {
                $master->statement("TRUNCATE TABLE `$database`.`$table`");
            }
        }

        $this->seedSchoolA();
        $this->seedSchoolB();
    }

    private function relaxColumns(Connection $master, string $database, string $table): void
    {
        $columns = $master->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND IS_NULLABLE = ? AND COLUMN_KEY <> ? AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE ?',
            [$database, $table, 'NO', 'PRI', '%auto_increment%'],
        );

        foreach ($columns as $column) {
            $master->statement("ALTER TABLE `$database`.`$table` MODIFY `{$column->COLUMN_NAME}` {$column->COLUMN_TYPE} NULL");
        }
    }

    private function school(string $database): Connection
    {
        if (! in_array($database, [self::SCHOOL_A_DB, self::SCHOOL_B_DB], true)) {
            throw new RuntimeException('Guardian tests must only write to the test school databases.');
        }

        $name = 'test_'.$database;
        config(["database.connections.$name" => ['database' => $database] + config('database.connections.master')]);

        return DB::connection($name);
    }

    private function seedSchoolA(): void
    {
        $db = $this->school(self::SCHOOL_A_DB);

        $db->table('session_info')->insert(['session_code' => 20, 'session_name' => '2026', 'running_session' => 1]);
        $db->table('class_info')->insert(['class_code' => self::CLASS_A, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1]);
        $db->table('section_info')->insert(['section_code' => 1, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1]);
        $db->table('student_info')->insert([
            ['student_registration_code' => self::RAHIM, 'student_name' => 'Rahim', 'contact_number' => self::RAHIM_CONTACT],
            ['student_registration_code' => self::MARIA, 'student_name' => 'Maria', 'contact_number' => self::MOBILE],
            ['student_registration_code' => self::NO_CONTACT, 'student_name' => 'Nobody', 'contact_number' => null],
            ['student_registration_code' => self::LEFT_SCHOOL, 'student_name' => 'Old', 'contact_number' => self::RAHIM_CONTACT],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => 1, 'student_registration_code' => self::RAHIM, 'session_code' => 20, 'class_code' => self::CLASS_A, 'section_code' => 1, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => 2, 'student_registration_code' => self::MARIA, 'session_code' => 20, 'class_code' => self::CLASS_A, 'section_code' => 1, 'roll_number' => 7, 'active_status' => 1],
            ['current_status_info_id' => 3, 'student_registration_code' => self::NO_CONTACT, 'session_code' => 20, 'class_code' => self::CLASS_A, 'section_code' => 1, 'roll_number' => 3, 'active_status' => 1],
            ['current_status_info_id' => 4, 'student_registration_code' => self::LEFT_SCHOOL, 'session_code' => 20, 'class_code' => self::CLASS_A, 'section_code' => 1, 'roll_number' => 9, 'active_status' => 0],
        ]);
    }

    private function seedSchoolB(): void
    {
        $db = $this->school(self::SCHOOL_B_DB);

        $db->table('session_info')->insert(['session_code' => 5, 'session_name' => '2027', 'running_session' => 1]);
        $db->table('class_info')->insert(['class_code' => self::CLASS_B, 'class_name' => 'Three', 'class_activation_status' => 1, 'class_position' => 1]);
        $db->table('section_info')->insert(['section_code' => 2, 'section_name' => 'Rose', 'section_activation_status' => 1, 'sec_position' => 1]);
        $db->table('student_info')->insert(['student_registration_code' => self::NADIA, 'student_name' => 'Nadia', 'contact_number' => self::NADIA_CONTACT]);
        $db->table('student_current_status_info')->insert(['current_status_info_id' => 1, 'student_registration_code' => self::NADIA, 'session_code' => 5, 'class_code' => self::CLASS_B, 'section_code' => 2, 'roll_number' => 4, 'active_status' => 1]);
    }

    private function mobileFlow(string $role = 'guardian', string $mobile = self::MOBILE, bool $resetPin = false): string
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => $mobile, 'role' => $role, 'reset_pin' => $resetPin])->assertOk();

        return $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => $mobile, 'otp' => '123456', 'role' => $role])
            ->assertOk()
            ->json('data.flow_token');
    }

    private function check(string $flow, int $school = self::SCHOOL_A, int $class = self::CLASS_A, string $studentId = self::RAHIM): TestResponse
    {
        return $this->postJson('/api/v1/auth/guardian/check', ['flow_token' => $flow, 'schl_id' => $school, 'class_code' => $class, 'student_id' => $studentId]);
    }

    private function sendChildOtp(string $flow): TestResponse
    {
        return $this->postJson('/api/v1/auth/guardian/send-child-otp', ['flow_token' => $flow]);
    }

    private function verifyChildOtp(string $flow, string $otp = '123456'): TestResponse
    {
        return $this->postJson('/api/v1/auth/guardian/verify-child-otp', ['flow_token' => $flow, 'otp' => $otp]);
    }

    private function setPin(string $flow, string $pin = '1234'): TestResponse
    {
        return $this->postJson('/api/v1/auth/set-pin', ['flow_token' => $flow, 'pin' => $pin, 'pin_confirmation' => $pin]);
    }

    private function readyFlow(string $flow, int $school = self::SCHOOL_A, int $class = self::CLASS_A, string $studentId = self::RAHIM): string
    {
        $found = $this->check($flow, $school, $class, $studentId)->assertOk()->json('data.flow_token');
        $this->sendChildOtp($found)->assertOk();

        return $this->verifyChildOtp($found)->assertOk()->json('data.flow_token');
    }

    private function loggedInGuardian(string $mobile = self::MOBILE, string $studentId = self::RAHIM): string
    {
        return $this->setPin($this->readyFlow($this->mobileFlow('guardian', $mobile), self::SCHOOL_A, self::CLASS_A, $studentId))
            ->assertOk()
            ->json('data.token');
    }

    private function addChild(string $token, int $school = self::SCHOOL_B, int $class = self::CLASS_B, string $studentId = self::NADIA): TestResponse
    {
        $found = $this->withToken($token)->postJson('/api/v1/guardian/children/check', ['schl_id' => $school, 'class_code' => $class, 'student_id' => $studentId])
            ->assertOk()
            ->json('data.flow_token');

        $this->withToken($token)->postJson('/api/v1/guardian/children/send-otp', ['flow_token' => $found])->assertOk();

        return $this->withToken($token)->postJson('/api/v1/guardian/children/verify-otp', ['flow_token' => $found, 'otp' => '123456']);
    }

    private function guardian(string $mobile = self::MOBILE): AppUser
    {
        return AppUser::where('mobile_number', $mobile)->where('role', 'guardian')->first();
    }

    public function test_guardian_full_flow_then_logout(): void
    {
        $found = $this->check($this->mobileFlow())->assertOk()
            ->assertJsonPath('data.masked_number', '018*****111')
            ->assertJsonPath('data.next', 'send_child_otp');

        // The name is not shown until the contact number is verified.
        $this->assertStringNotContainsString('Rahim', $found->getContent());

        $flow = $found->json('data.flow_token');

        $this->sendChildOtp($flow)->assertOk()->assertJsonPath('message', 'OTP sent to 018*****111');
        $this->assertSame(1, AppLoginAttempt::where('mobile_number', self::RAHIM_CONTACT)->where('purpose', 'guardian_child')->count());

        $ready = $this->verifyChildOtp($flow)->assertOk()->assertJsonPath('data.next', 'set_pin')->json('data.flow_token');

        $login = $this->setPin($ready)->assertOk()
            ->assertJsonPath('data.user.role', 'guardian')
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonPath('data.role', 'guardian')
            ->assertJsonPath('data.school_id', null)
            ->assertJsonPath('data.detail', '১ জন সন্তান: Rahim')
            ->assertJsonPath('data.linked_students', ['1'])
            ->assertJsonPath('data.permissions', config('pathshala.permissions.guardian'))
            ->assertJsonMissingPath('data.student_id')
            ->assertJsonMissingPath('data.user.student_name')
            ->assertJsonMissingPath('data.user.teacher_name');

        $user = $this->guardian();
        $this->assertSame('contact_otp', $user->verified_by);
        $this->assertNull($user->schl_id);
        $this->assertNull($user->verified_id);
        $this->assertSame(1, AppGuardianChild::where('app_user_id', $user->id)->where('schl_id', self::SCHOOL_A)->where('student_id', self::RAHIM)->count());

        $token = $login->json('data.token');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')));
        $this->assertNull($claims->db);
        $this->assertNull($claims->schl);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.children_count', 1)
            ->assertJsonPath('data.linked_students', ['1'])
            ->assertJsonMissingPath('data.placement');

        // Not their child, and the school header is not compared for a guardian.
        $this->withToken($token)->withHeader('X-School-Id', (string) self::SCHOOL_B)->getJson('/api/v1/students/999/quizzes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'not_linked');

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'guardian', 'pin' => '1234'])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertStatus(401);
    }

    public function test_the_contact_otp_is_needed_even_when_the_guardian_uses_the_contact_number(): void
    {
        $found = $this->check($this->mobileFlow(), self::SCHOOL_A, self::CLASS_A, self::MARIA)->assertOk()->json('data.flow_token');

        $this->verifyChildOtp($found)->assertStatus(422)->assertJsonPath('message', 'OTP expired. Please request a new OTP.');

        $this->sendChildOtp($found)->assertOk();
        $this->assertNotNull(AppLoginAttempt::where('mobile_number', self::MOBILE)->where('purpose', 'guardian_child')->first());

        $this->verifyChildOtp($found)->assertOk();
    }

    public function test_a_wrong_contact_otp_is_refused(): void
    {
        $found = $this->check($this->mobileFlow())->assertOk()->json('data.flow_token');
        $this->sendChildOtp($found)->assertOk();

        $this->verifyChildOtp($found, '000000')->assertStatus(422)->assertJsonPath('data.attempts_left', 1);
    }

    public function test_pin_cannot_be_set_before_the_contact_otp(): void
    {
        $flow = $this->mobileFlow();
        $found = $this->check($flow)->assertOk()->json('data.flow_token');

        $this->setPin($flow)->assertStatus(422);
        $this->setPin($found)->assertStatus(422);
    }

    public function test_wrong_id_and_wrong_class_look_the_same_and_are_limited(): void
    {
        $flow = $this->mobileFlow();

        $this->check($flow, self::SCHOOL_A, self::CLASS_A, '1111')->assertStatus(404)
            ->assertJsonPath('message', 'The information you entered is not valid')
            ->assertJsonMissingPath('data.can_continue_as_guest');

        $this->check($flow, self::SCHOOL_A, 99, self::RAHIM)->assertStatus(404)->assertJsonPath('message', 'The information you entered is not valid');
        $this->check($flow, self::SCHOOL_A, self::CLASS_A, self::LEFT_SCHOOL)->assertStatus(404);

        for ($i = 3; $i < config('pathshala.login_limit.attempts'); $i++) {
            $this->check($flow, self::SCHOOL_A, self::CLASS_A, '1111')->assertStatus(404);
        }

        $this->check($flow)->assertStatus(429);
    }

    public function test_a_child_without_a_contact_number_uses_the_login_number(): void
    {
        $found = $this->check($this->mobileFlow(), self::SCHOOL_A, self::CLASS_A, self::NO_CONTACT)->assertOk()
            ->assertJsonPath('data.masked_number', '017*****001')
            ->json('data.flow_token');

        // The OTP is still required, and has its own record apart from the mobile OTP.
        $this->verifyChildOtp($found)->assertStatus(422)->assertJsonPath('message', 'OTP expired. Please request a new OTP.');

        $this->sendChildOtp($found)->assertOk()->assertJsonPath('message', 'OTP sent to 017*****001');
        $this->assertSame(1, AppLoginAttempt::where('mobile_number', self::MOBILE)->where('purpose', 'guardian_child')->count());

        $this->setPin($this->verifyChildOtp($found)->assertOk()->json('data.flow_token'))->assertOk();
        $this->assertSame(1, AppGuardianChild::where('student_id', self::NO_CONTACT)->count());
    }

    public function test_a_badly_typed_contact_number_uses_the_login_number(): void
    {
        $this->school(self::SCHOOL_A_DB)->table('student_info')->where('student_registration_code', self::RAHIM)->update(['contact_number' => '12345']);

        $this->check($this->mobileFlow())->assertOk()->assertJsonPath('data.masked_number', '017*****001');
    }

    public function test_a_child_without_a_contact_number_can_be_added_after_login_with_the_login_number(): void
    {
        $token = $this->loggedInGuardian();

        $this->addChild($token, self::SCHOOL_A, self::CLASS_A, self::NO_CONTACT)->assertCreated();

        $this->assertSame(2, AppGuardianChild::count());
    }

    public function test_forgot_pin_with_a_child_without_a_contact_number_uses_the_login_number(): void
    {
        $this->loggedInGuardian(self::MOBILE, self::NO_CONTACT);

        $found = $this->check($this->mobileFlow('guardian', self::MOBILE, true), self::SCHOOL_A, self::CLASS_A, self::NO_CONTACT)->assertOk()->json('data.flow_token');
        $this->sendChildOtp($found)->assertOk()->assertJsonPath('message', 'OTP sent to 017*****001');

        $this->setPin($this->verifyChildOtp($found)->assertOk()->json('data.flow_token'), '5678')->assertOk();

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'guardian', 'pin' => '5678'])->assertOk();
    }

    public function test_child_otp_sends_are_limited_per_guardian(): void
    {
        $flow = $this->mobileFlow();
        $found = $this->check($flow)->assertOk()->json('data.flow_token');
        $limitKey = 'guardian_child_otp:'.self::MOBILE;

        $this->sendChildOtp($found)->assertOk();
        $this->assertSame(1, RateLimiter::attempts($limitKey));

        for ($i = 1; $i < config('pathshala.login_limit.attempts'); $i++) {
            RateLimiter::hit($limitKey, 900);
        }

        $this->sendChildOtp($found)->assertStatus(429);
    }

    public function test_one_child_shows_that_child_on_the_dashboard(): void
    {
        $this->withToken($this->loggedInGuardian())->getJson('/api/v1/guardian/dashboard')->assertOk()
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.child.student_name', 'Rahim')
            ->assertJsonPath('data.child.school_name', 'School A')
            ->assertJsonPath('data.child.session_name', '2026')
            ->assertJsonPath('data.child.class_name', 'Eight')
            ->assertJsonPath('data.child.section_name', 'A')
            ->assertJsonPath('data.child.roll_number', 12)
            ->assertJsonPath('data.child.is_available', true);
    }

    public function test_many_children_show_the_first_and_can_switch_between_schools(): void
    {
        $token = $this->loggedInGuardian();

        $added = $this->addChild($token)->assertCreated()->assertJsonPath('data.student_name', 'Nadia');
        $secondId = $added->json('data.id');

        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()
            ->assertJsonCount(2, 'data.children')
            ->assertJsonPath('data.child.student_name', 'Rahim')
            ->assertJsonPath('data.children.0.school_name', 'School A')
            ->assertJsonPath('data.children.1.school_name', 'School B');

        $this->withToken($token)->getJson("/api/v1/guardian/children/$secondId/dashboard")->assertOk()
            ->assertJsonPath('data.student_name', 'Nadia')
            ->assertJsonPath('data.school_name', 'School B')
            ->assertJsonPath('data.session_name', '2027')
            ->assertJsonPath('data.class_name', 'Three')
            ->assertJsonPath('data.section_name', 'Rose');

        $this->withToken($token)->getJson('/api/v1/guardian/children')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_adding_a_child_after_login_needs_the_otp_and_is_logged(): void
    {
        $token = $this->loggedInGuardian();

        $found = $this->withToken($token)->postJson('/api/v1/guardian/children/check', ['schl_id' => self::SCHOOL_B, 'class_code' => self::CLASS_B, 'student_id' => self::NADIA])
            ->assertOk();
        $this->assertStringNotContainsString('Nadia', $found->getContent());

        $flow = $found->json('data.flow_token');

        $this->withToken($token)->postJson('/api/v1/guardian/children/verify-otp', ['flow_token' => $flow, 'otp' => '123456'])->assertStatus(422);
        $this->assertSame(1, AppGuardianChild::count());

        $this->withToken($token)->postJson('/api/v1/guardian/children/send-otp', ['flow_token' => $flow])->assertOk();
        $this->assertSame(1, AppLoginAttempt::where('mobile_number', self::NADIA_CONTACT)->count());

        $id = $this->withToken($token)->postJson('/api/v1/guardian/children/verify-otp', ['flow_token' => $flow, 'otp' => '123456'])->assertCreated()->json('data.id');
        $this->assertSame(2, AppGuardianChild::count());

        $entries = app(ActivityLogService::class)->read((int) now()->year, (int) now()->month, $this->guardian()->id);
        $this->assertSame('add_child', $entries[0]['action']);
        $this->assertSame($id, $entries[0]['details']['childId']);
    }

    public function test_the_same_child_cannot_be_added_twice_but_two_guardians_can_share_a_child(): void
    {
        $token = $this->loggedInGuardian();

        $this->withToken($token)->postJson('/api/v1/guardian/children/check', ['schl_id' => self::SCHOOL_A, 'class_code' => self::CLASS_A, 'student_id' => self::RAHIM])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This child is already added.');

        $this->loggedInGuardian('01700000009');

        $this->assertSame(2, AppGuardianChild::where('student_id', self::RAHIM)->count());
    }

    public function test_a_guardian_can_only_add_up_to_the_limit(): void
    {
        config(['pathshala.guardian.max_children' => 1]);

        $this->withToken($this->loggedInGuardian())
            ->postJson('/api/v1/guardian/children/check', ['schl_id' => self::SCHOOL_B, 'class_code' => self::CLASS_B, 'student_id' => self::NADIA])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A guardian can add at most 1 children.');
    }

    public function test_another_guardians_child_cannot_be_seen_or_removed(): void
    {
        $this->loggedInGuardian();
        $childId = AppGuardianChild::first()->id;
        $other = $this->loggedInGuardian('01700000009', self::MARIA);

        $this->withToken($other)->getJson("/api/v1/guardian/children/$childId/dashboard")->assertStatus(404);
        $this->withToken($other)->deleteJson("/api/v1/guardian/children/$childId")->assertStatus(404);

        $this->assertNotNull(AppGuardianChild::find($childId));
    }

    public function test_removing_a_child_stops_access_at_once_and_leaves_an_empty_dashboard(): void
    {
        $token = $this->loggedInGuardian();
        $childId = AppGuardianChild::first()->id;

        $this->withToken($token)->deleteJson("/api/v1/guardian/children/$childId")->assertOk();

        $this->withToken($token)->getJson("/api/v1/guardian/children/$childId/dashboard")->assertStatus(404);
        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()
            ->assertJsonPath('data.child', null)
            ->assertJsonCount(0, 'data.children');

        $this->addChild($token)->assertCreated();
    }

    public function test_a_number_with_an_account_is_sent_to_pin_login(): void
    {
        $this->loggedInGuardian();

        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'guardian'])
            ->assertStatus(422)
            ->assertJsonPath('error.next', 'pin_login');
    }

    public function test_forgot_pin_needs_the_mobile_otp_and_the_otp_on_the_childs_contact_number(): void
    {
        $oldToken = $this->loggedInGuardian();

        $flow = $this->mobileFlow('guardian', self::MOBILE, true);
        $found = $this->check($flow)->assertOk()->json('data.flow_token');

        // The mobile OTP alone is not enough.
        $this->setPin($flow, '5678')->assertStatus(422);

        $this->sendChildOtp($found)->assertOk();
        $this->assertSame(1, AppLoginAttempt::where('mobile_number', self::RAHIM_CONTACT)->count());

        $newToken = $this->setPin($this->verifyChildOtp($found)->assertOk()->json('data.flow_token'), '5678')->assertOk()->json('data.token');

        $this->withToken($oldToken)->getJson('/api/v1/guardian/dashboard')->assertStatus(401);
        $this->withToken($newToken)->getJson('/api/v1/guardian/dashboard')->assertOk();
        $this->assertSame(1, AppUserLog::where('action', AppUserLog::PIN_RESET)->count());
        $this->assertSame(1, AppGuardianChild::count());

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'guardian', 'pin' => '5678'])->assertOk();
    }

    public function test_forgot_pin_cannot_use_a_child_not_on_the_account(): void
    {
        $this->loggedInGuardian();

        $this->check($this->mobileFlow('guardian', self::MOBILE, true), self::SCHOOL_B, self::CLASS_B, self::NADIA)
            ->assertStatus(404)
            ->assertJsonPath('message', 'The information you entered is not valid');

        $this->assertSame(0, AppLoginAttempt::where('mobile_number', self::NADIA_CONTACT)->count());
    }

    public function test_a_flow_started_before_the_pin_was_set_cannot_reset_it_with_another_child(): void
    {
        $staleReady = $this->readyFlow($this->mobileFlow(), self::SCHOOL_B, self::CLASS_B, self::NADIA);

        $this->setPin($this->readyFlow($this->mobileFlow()))->assertOk();

        $this->setPin($staleReady, '5678')->assertStatus(409);
        $this->assertSame(1, AppGuardianChild::count());
    }

    public function test_a_changed_contact_number_keeps_the_child_and_forgot_pin_uses_the_new_number(): void
    {
        $token = $this->loggedInGuardian();

        $this->school(self::SCHOOL_A_DB)->table('student_info')->where('student_registration_code', self::RAHIM)->update(['contact_number' => '01833333333']);

        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()->assertJsonPath('data.child.student_name', 'Rahim');

        $found = $this->check($this->mobileFlow('guardian', self::MOBILE, true))->assertOk()
            ->assertJsonPath('data.masked_number', '018*****333')
            ->json('data.flow_token');

        $this->sendChildOtp($found)->assertOk();
        $this->assertSame(1, AppLoginAttempt::where('mobile_number', '01833333333')->count());
        $this->assertSame(0, AppLoginAttempt::where('mobile_number', self::RAHIM_CONTACT)->count());
    }

    public function test_a_school_that_is_down_does_not_hide_the_other_children(): void
    {
        $token = $this->loggedInGuardian();
        $this->addChild($token)->assertCreated();

        DB::connection('master')->table('school_information')->where('schl_id', self::SCHOOL_A)->update(['db_name' => 'pathshala_app_no_such_database']);

        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()
            ->assertJsonPath('data.child.student_name', 'Nadia')
            ->assertJsonPath('data.children.0.is_available', false)
            ->assertJsonPath('data.children.0.student_name', null)
            ->assertJsonPath('data.children.1.is_available', true);
    }

    public function test_a_child_who_left_is_marked_as_not_available(): void
    {
        $token = $this->loggedInGuardian();

        $this->school(self::SCHOOL_A_DB)->table('student_current_status_info')->where('student_registration_code', self::RAHIM)->update(['active_status' => 0]);

        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()
            ->assertJsonPath('data.child.is_available', false)
            ->assertJsonPath('data.child.school_name', 'School A');
    }

    public function test_flow_tokens_cannot_cross_roles(): void
    {
        $guardianFlow = $this->mobileFlow();

        $this->postJson('/api/v1/auth/student/check', ['flow_token' => $guardianFlow, 'schl_id' => self::SCHOOL_A, 'class_code' => self::CLASS_A, 'student_id' => self::RAHIM])->assertStatus(422);
        $this->postJson('/api/v1/auth/teacher/verify', ['flow_token' => $guardianFlow, 'schl_id' => self::SCHOOL_A, 'username' => 'a', 'password' => 'b'])->assertStatus(422);

        $found = $this->check($guardianFlow)->assertOk()->json('data.flow_token');

        // A found child cannot be finished with the student's password route.
        $this->postJson('/api/v1/auth/student/verify-password', ['flow_token' => $found, 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Session expired or invalid. Please start again.');

        $this->check($this->mobileFlow('student'))->assertStatus(422)->assertJsonPath('message', 'Session expired or invalid. Please start again.');
    }

    public function test_a_flow_started_after_login_cannot_be_used_to_change_the_pin(): void
    {
        $token = $this->loggedInGuardian();

        $found = $this->withToken($token)->postJson('/api/v1/guardian/children/check', ['schl_id' => self::SCHOOL_B, 'class_code' => self::CLASS_B, 'student_id' => self::NADIA])
            ->assertOk()
            ->json('data.flow_token');

        $this->sendChildOtp($found)->assertStatus(422);
        $this->verifyChildOtp($found)->assertStatus(422);
        $this->check($found)->assertStatus(422);
    }

    public function test_a_flow_cannot_be_used_by_another_guardian(): void
    {
        $token = $this->loggedInGuardian();
        $other = $this->loggedInGuardian('01700000009', self::MARIA);

        $found = $this->withToken($token)->postJson('/api/v1/guardian/children/check', ['schl_id' => self::SCHOOL_B, 'class_code' => self::CLASS_B, 'student_id' => self::NADIA])
            ->assertOk()
            ->json('data.flow_token');

        $this->withToken($other)->postJson('/api/v1/guardian/children/send-otp', ['flow_token' => $found])->assertStatus(422);
    }

    public function test_other_roles_cannot_use_guardian_routes_and_guardians_cannot_use_homework(): void
    {
        $student = AppUser::create(['mobile_number' => '01700000008', 'role' => 'student', 'pin' => 'x']);
        $studentToken = app(TokenService::class)->issueAppToken($student, null);
        $student->update(['auth_token' => $studentToken]);

        $this->getJson('/api/v1/guardian/dashboard')->assertStatus(401);
        $this->withToken($studentToken)->getJson('/api/v1/guardian/dashboard')->assertStatus(403);
        $this->withToken($studentToken)->postJson('/api/v1/guardian/children/check', [])->assertStatus(403);

        $guardian = $this->loggedInGuardian();

        $this->withToken($guardian)->getJson('/api/v1/teacher/homework')->assertStatus(403);
        $this->withToken($guardian)->getJson('/api/v1/students/1/homework')->assertStatus(403);
    }

    public function test_same_number_keeps_separate_student_teacher_and_guardian_users(): void
    {
        $this->mobileFlow('student');
        $this->mobileFlow('teacher');
        $this->mobileFlow('guardian');

        $this->assertSame(3, AppUser::where('mobile_number', self::MOBILE)->count());
    }

    public function test_responses_never_contain_secrets(): void
    {
        $token = $this->loggedInGuardian();

        $content = $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()->getContent();

        foreach ([self::SCHOOL_A_DB, 'db_name', 'student_auth', 'contact_number', self::RAHIM_CONTACT] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
