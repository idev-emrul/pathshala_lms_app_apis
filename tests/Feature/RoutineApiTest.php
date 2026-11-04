<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The routine is read from pathshala_app_test_school, a copy of the real tables' structure with
 * made-up rows. The real school database is never written to.
 */
class RoutineApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 78;
    private const SESSION = 20;
    private const OLD_SESSION = 19;
    private const CLASS_ID = 8;
    private const OTHER_CLASS_ID = 9;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const TEACHER_USER = 600;
    private const TEACHER_CODE = 800;
    private const OTHER_TEACHER_USER = 601;
    private const OTHER_TEACHER_CODE = 801;
    private const STUDENT_ROW = 5001;
    private const OTHER_STUDENT_ROW = 5002;

    // Sunday and Friday in the test week; Friday is a weekly off day.
    private const SUNDAY = '2026-10-18';
    private const MONDAY = '2026-10-19';
    private const TUESDAY = '2026-10-20';
    private const FRIDAY = '2026-10-23';

    private const COPIED_TABLES = [
        'clr_routines', 'clr_periods', 'clr_days', 'clr_class_rooms', 'subject_group', 'academic_calendar_info',
        'users', 'teacher_info', 'student_info', 'student_current_status_info', 'session_info', 'class_info', 'section_info',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $otherTeacherToken;
    private string $studentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000020', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000021', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000022', 'student', '9101');
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('school_information')->where('schl_id', self::TEST_SCHOOL_ID)->delete();

        parent::tearDown();
    }

    public function test_tests_never_use_the_real_school(): void
    {
        $this->assertSame(self::TEST_SCHOOL_DB, $this->school()->getDatabaseName());
    }

    public function test_no_token_is_refused(): void
    {
        $this->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::SUNDAY)->assertStatus(401);
    }

    public function test_a_student_gets_their_periods_for_a_date_in_time_order(): void
    {
        $response = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::SUNDAY)
            ->assertOk()
            ->assertJsonPath('status', true);

        // Section A's own period and the "all sections" period, earliest first. Not section B, not
        // another class, not an inactive period, not an old session.
        $this->assertSame(['2', '1'], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0', [
            'id' => '2',
            'type' => 'lesson',
            'start_time' => '09:00',
            'duration_minutes' => 45,
            'title' => 'Math',
            'teacher_name' => 'Hasan',
            'room' => 'Room 101',
            'status' => null,
            'status_reason' => null,
        ]);
    }

    public function test_a_weekly_off_day_has_no_periods(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::FRIDAY)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_holiday_has_no_periods_but_another_classs_holiday_does_not_count(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::MONDAY)
            ->assertOk()
            ->assertJsonPath('data', []);

        // Tuesday is a holiday for the other class only.
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::TUESDAY)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_period_that_ends_before_it_starts_has_no_duration(): void
    {
        $response = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::TUESDAY)
            ->assertOk();

        $response->assertJsonPath('data.0.id', '4');
        $response->assertJsonPath('data.0.duration_minutes', null);
    }

    public function test_a_student_cannot_see_another_students_routine(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/routine?date='.self::SUNDAY)
            ->assertStatus(403);
    }

    public function test_the_date_is_required_and_must_be_a_real_date(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date']]);

        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date=2026-02-30')
            ->assertStatus(422);
    }

    public function test_a_teacher_gets_their_own_periods_in_running_sessions(): void
    {
        $response = $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teachers/'.$this->appUserId($this->teacherToken).'/routine?date='.self::SUNDAY)
            ->assertOk();

        // Their two periods in the running session. Not the old session's period.
        $this->assertSame(['2', '1'], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0.class_label', 'Eight');
        $response->assertJsonPath('data.0.section', 'A');
        $response->assertJsonPath('data.1.section', null);
        $this->assertArrayNotHasKey('teacher_name', $response->json('data.0'));
    }

    public function test_a_teacher_cannot_see_another_teachers_routine(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teachers/'.$this->appUserId($this->otherTeacherToken).'/routine?date='.self::SUNDAY)
            ->assertStatus(403);

        // The school's users.id is not the id the app knows, so it is refused too.
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teachers/'.self::TEACHER_USER.'/routine?date='.self::SUNDAY)
            ->assertStatus(403);
    }

    public function test_roles_cannot_use_each_others_endpoints(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::SUNDAY)
            ->assertStatus(403);

        $this->withToken($this->studentToken)
            ->getJson('/api/v1/teachers/'.$this->appUserId($this->studentToken).'/routine?date='.self::SUNDAY)
            ->assertStatus(403);
    }

    public function test_guests_have_no_routine(): void
    {
        $guestToken = $this->tokenFor('01700000023', 'student', null, verified: false);

        $this->withToken($guestToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/routine?date='.self::SUNDAY)
            ->assertStatus(403);
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
                // Only the columns the API reads are seeded.
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
            'schl_name' => 'Routine Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'routine_test',
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
        config(['database.connections.test_school' => config('database.connections.master')]);
        config(['database.connections.test_school.database' => self::TEST_SCHOOL_DB]);

        $connection = DB::connection('test_school');

        if ($connection->getDatabaseName() !== self::TEST_SCHOOL_DB) {
            throw new RuntimeException('Routine tests must only use the test school database.');
        }

        // Old tables have zero-date defaults, which strict mode refuses when seeding.
        $connection->statement("SET SESSION sql_mode = ''");

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('session_info')->insert([
            ['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1],
            ['session_code' => self::OLD_SESSION, 'session_name' => '2025', 'running_session' => 0],
        ]);
        $db->table('class_info')->insert([
            ['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1],
            ['class_code' => self::OTHER_CLASS_ID, 'class_name' => 'Nine', 'class_activation_status' => 1, 'class_position' => 2],
        ]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_A, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => self::SECTION_B, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1],
            ['teacher_code' => self::OTHER_TEACHER_CODE, 'teacher_name' => 'Karim', 'teacher_active_status' => 1],
        ]);
        $db->table('users')->insert([
            ['id' => self::TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::OTHER_TEACHER_USER, 'username' => 'karim', 'user_full_name' => 'Karim', 'teacher_code' => self::OTHER_TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
        ]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9101, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9102, 'student_name' => 'Maria'],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9101, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'institute_medium_id' => null, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => self::OTHER_STUDENT_ROW, 'student_registration_code' => 9102, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_B, 'group_code' => 0, 'shift_code' => 0, 'institute_medium_id' => 0, 'roll_number' => 7, 'active_status' => 1],
        ]);

        $days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($days as $i => $name) {
            $db->table('clr_days')->insert(['day_id' => $i + 1, 'day_name' => $name, 'status' => in_array($name, ['Saturday', 'Friday'], true) ? 0 : 1]);
        }

        $db->table('clr_periods')->insert([
            ['period_id' => 1, 'session_code' => self::SESSION, 'shift_code' => 0, 'period_name' => '2nd', 'start_time' => '10:00:00', 'end_time' => '10:40:00', 'status' => 1],
            ['period_id' => 2, 'session_code' => self::SESSION, 'shift_code' => 0, 'period_name' => '1st', 'start_time' => '09:00:00', 'end_time' => '09:45:00', 'status' => 1],
            ['period_id' => 3, 'session_code' => self::SESSION, 'shift_code' => 0, 'period_name' => 'Old', 'start_time' => '11:00:00', 'end_time' => '11:40:00', 'status' => 0],
            ['period_id' => 4, 'session_code' => self::SESSION, 'shift_code' => 0, 'period_name' => 'Wrong', 'start_time' => '22:10:00', 'end_time' => '11:10:00', 'status' => 1],
        ]);
        $db->table('clr_class_rooms')->insert(['class_room_id' => 1, 'class_room_name' => 'Room 101', 'status' => 1]);
        $db->table('subject_group')->insert([
            ['subject_group_id' => 10, 'subject_group_name' => 'Math'],
            ['subject_group_id' => 11, 'subject_group_name' => 'English'],
        ]);

        $sunday = 2;
        $tuesday = 4;
        $routine = fn (array $row) => $row + ['session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => 0, 'group_code' => 0, 'shift_code' => 0, 'institute_medium_id' => 0, 'class_room_id' => 1, 'teacher_code' => self::TEACHER_CODE, 'subject_group_code' => 10, 'status' => 1];

        $db->table('clr_routines')->insert([
            // Section A's own period and an "all sections" period.
            $routine(['routine_id' => 1, 'day_id' => $sunday, 'period_id' => 2, 'section_code' => self::SECTION_A]),
            $routine(['routine_id' => 2, 'day_id' => $sunday, 'period_id' => 1, 'subject_group_code' => 11]),
            // Not shown: section B, another class, an inactive period, an old session.
            $routine(['routine_id' => 3, 'day_id' => $sunday, 'period_id' => 2, 'section_code' => self::SECTION_B, 'teacher_code' => self::OTHER_TEACHER_CODE]),
            $routine(['routine_id' => 4, 'day_id' => $sunday, 'period_id' => 1, 'class_code' => self::OTHER_CLASS_ID, 'teacher_code' => self::OTHER_TEACHER_CODE]),
            $routine(['routine_id' => 5, 'day_id' => $sunday, 'period_id' => 3]),
            $routine(['routine_id' => 6, 'day_id' => $sunday, 'period_id' => 1, 'session_code' => self::OLD_SESSION]),
            // Monday has a period but is a school holiday; Tuesday's period has a bad end time.
            $routine(['routine_id' => 7, 'day_id' => 3, 'period_id' => 2]),
            $routine(['routine_id' => 8, 'day_id' => $tuesday, 'period_id' => 4]),
        ]);

        $db->table('academic_calendar_info')->insert([
            ['event_id' => 1, 'event_title_en' => 'Holiday', 'student_class' => 'All', 'event_attendance' => 0, 'event_status' => 1, 'start_date' => self::MONDAY, 'end_date' => self::MONDAY],
            ['event_id' => 2, 'event_title_en' => 'Nine trip', 'student_class' => (string) self::OTHER_CLASS_ID, 'event_attendance' => 0, 'event_status' => 1, 'start_date' => self::TUESDAY, 'end_date' => self::TUESDAY],
        ]);
    }

    /**
     * The app_user id behind a token: the user.id the app gets from Set PIN and /auth/me.
     */
    private function appUserId(string $token): int
    {
        return (int) AppUser::where('auth_token', $token)->value('id');
    }

    private function tokenFor(string $mobile, string $role, ?string $verifiedId, bool $verified = true): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => $verified,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Routine Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }
}
