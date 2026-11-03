<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Attendance is read from and written to pathshala_app_test_school, a copy of the real tables'
 * structure with made-up rows. The real school database is never written to.
 *
 * May 2026 for the student: Fridays and Saturdays are off (10 days) and the 20th is a holiday, so
 * 20 working days. Marked: present 3rd, 4th, 17th; absent 5th; leave 6th; fugitive 7th.
 */
class AttendanceApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 82;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const TEACHER_USER = 1000;
    private const TEACHER_CODE = 1200;
    private const OTHER_TEACHER_USER = 1001;
    private const OTHER_TEACHER_CODE = 1201;
    private const STUDENT_ROW = 9001;
    private const CLASSMATE_ROW = 9002;
    private const SECTION_B_ROW = 9003;
    private const REGISTER = '20-8-1-0-0';

    private const COPIED_TABLES = [
        'attendance_info', 'app_attendance_register_log', 'clr_days', 'academic_calendar_info', 'student_info',
        'student_current_status_info', 'session_info', 'class_info', 'section_info', 'class_teacher_info', 'teacher_info', 'users',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $otherTeacherToken;
    private string $studentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000060', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000061', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000062', 'student', '9501');
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
        $this->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance/summary')->assertStatus(401);
        $this->getJson('/api/v1/teacher/attendance/register?date=2026-05-10')->assertStatus(401);
    }

    public function test_the_report_counts_a_duration_as_the_web_profile_does(): void
    {
        $data = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance/report?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026', $data['academic_year']);
        $this->assertSame([], $data['terms']);
        $this->assertSame('Rahim', $data['student_name']);
        $this->assertSame('Eight - A', $data['class_label']);
        $this->assertSame(20, $data['working_days']);
        $this->assertSame(['present' => 3, 'absent' => 1, 'leave' => 1, 'fugitive' => 1, 'holiday' => 11, 'pending' => 16], $data['counts']);
        // Each count / (present + absent + leave + fugitive + pending) = 22.
        $this->assertSame(['present' => 13.6, 'absent' => 4.5, 'leave' => 4.5, 'fugitive' => 4.5], $data['percentages']);
        $this->assertSame(13.6, $data['rate']);
        // Latest marked days first.
        $this->assertSame(['2026-05-17', '2026-05-07', '2026-05-06', '2026-05-05', '2026-05-04', '2026-05-03'], array_column($data['recent'], 'date'));
    }

    public function test_the_month_has_every_day_with_its_status(): void
    {
        $data = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance?month=2026-05')
            ->assertOk()
            ->json('data');

        $this->assertSame(2026, $data['year']);
        $this->assertSame(5, $data['month']);
        $this->assertCount(31, $data['days']);

        $status = array_column($data['days'], 'status', 'date');
        $this->assertSame('holiday', $status['2026-05-01']);   // Friday
        $this->assertSame('present', $status['2026-05-03']);
        $this->assertSame('absent', $status['2026-05-05']);
        $this->assertSame('leave', $status['2026-05-06']);
        $this->assertSame('fugitive', $status['2026-05-07']);
        $this->assertSame('pending', $status['2026-05-10']);
        $this->assertSame('holiday', $status['2026-05-20']);   // calendar holiday

        $this->assertSame(['date' => '2026-05-03', 'status' => 'present', 'check_in' => null, 'check_out' => null, 'marked_by' => null, 'marked_at' => null, 'note' => null], $data['days'][2]);
    }

    public function test_a_future_month_has_no_days_and_the_month_is_required(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance?month=2099-01')->assertOk()->assertJsonPath('data.days', []);
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance')->assertStatus(422)->assertJsonStructure(['errors' => ['month']]);
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance/report?from=2026-05-31&to=2026-05-01')->assertStatus(422);
    }

    public function test_the_summary_covers_the_session_with_a_monthly_chart(): void
    {
        $data = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['counts']['present']);
        $this->assertSame([['month' => 5, 'percentage' => 13.6]], $data['monthly']);
    }

    public function test_a_student_sees_only_their_own_attendance(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::CLASSMATE_ROW.'/attendance/summary')->assertStatus(403);
        $this->withToken($this->teacherToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance/summary')->assertStatus(403);
    }

    public function test_the_class_teacher_gets_the_register_for_a_date(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teacher/attendance/register?date=2026-05-06')
            ->assertOk()
            ->assertJsonPath('data.section_id', self::REGISTER)
            ->assertJsonPath('data.class_label', 'Eight - A')
            ->assertJsonPath('data.marking_enabled', true)
            ->assertJsonPath('data.submitted_at', null)
            // Section A in roll order; section B is not in this register.
            ->assertJsonPath('data.students', [
                ['student_id' => (string) self::CLASSMATE_ROW, 'name' => 'Maria', 'roll' => '3', 'status' => null, 'leave_reason' => null],
                ['student_id' => (string) self::STUDENT_ROW, 'name' => 'Rahim', 'roll' => '12', 'status' => 'leave', 'leave_reason' => null],
            ]);

        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/attendance/register?date=2099-01-01')->assertOk()->assertJsonPath('data.marking_enabled', false);
    }

    public function test_submitting_the_register_writes_the_day_columns_and_the_log(): void
    {
        $this->submit('2026-05-10', [
            ['student_id' => (string) self::STUDENT_ROW, 'status' => 'present'],
            ['student_id' => (string) self::CLASSMATE_ROW, 'status' => 'absent'],
        ], 'register-1')->assertOk();

        // The student's existing May row is updated; the classmate had none, so it is created.
        $this->assertSame(1, (int) $this->dayCode(self::STUDENT_ROW, 5, 'd10'));
        $this->assertSame(2, (int) $this->dayCode(self::CLASSMATE_ROW, 5, 'd10'));
        $this->assertSame(1, $this->school()->table('attendance_info')->where('attendance_student_id', self::CLASSMATE_ROW)->count());
        $this->assertSame(1, $this->school()->table('app_attendance_register_log')->count());

        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/attendance/register?date=2026-05-10')->assertJsonPath('data.students.1.status', 'present');

        $day = collect($this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/attendance?month=2026-05')->json('data.days'))->firstWhere('date', '2026-05-10');
        $this->assertSame('present', $day['status']);
        $this->assertSame('Hasan', $day['marked_by']);
        $this->assertNotNull($day['marked_at']);
    }

    public function test_the_same_key_is_written_once_and_leave_is_not_overwritten(): void
    {
        $this->submit('2026-05-06', [['student_id' => (string) self::STUDENT_ROW, 'status' => 'absent']], 'register-2')->assertOk();
        $this->submit('2026-05-06', [['student_id' => (string) self::STUDENT_ROW, 'status' => 'absent']], 'register-2')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(3, (int) $this->dayCode(self::STUDENT_ROW, 5, 'd6'));
        $this->assertSame(1, $this->school()->table('app_attendance_register_log')->count());
    }

    public function test_a_register_with_a_wrong_student_or_status_or_date_is_refused(): void
    {
        $this->submit('2026-05-10', [['student_id' => (string) self::SECTION_B_ROW, 'status' => 'present']])->assertStatus(422)->assertJsonStructure(['errors' => ['marks.0.student_id']]);
        $this->submit('2026-05-10', [['student_id' => (string) self::STUDENT_ROW, 'status' => 'late']])->assertStatus(422)->assertJsonStructure(['errors' => ['marks.0.status']]);
        $this->submit('2099-01-01', [['student_id' => (string) self::STUDENT_ROW, 'status' => 'present']])->assertStatus(422)->assertJsonStructure(['errors' => ['date']]);

        $this->assertSame(0, $this->school()->table('app_attendance_register_log')->count());
    }

    public function test_only_the_class_teacher_can_use_the_register(): void
    {
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/teacher/attendance/register?date=2026-05-10')->assertStatus(403);
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/attendance/register?date=2026-05-10&section_id=20-9-1-0-0')->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/teacher/attendance/register?date=2026-05-10')->assertStatus(403);
    }

    private function submit(string $date, array $marks, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->teacherToken)
            ->withHeaders($key ? ['Idempotency-Key' => $key] : [])
            ->postJson('/api/v1/teacher/attendance/register', ['section_id' => self::REGISTER, 'date' => $date, 'marks' => $marks]);
    }

    private function dayCode(int $studentRow, int $month, string $column): mixed
    {
        return $this->school()->table('attendance_info')->where('attendance_student_id', $studentRow)->where('attendance_month', $month)->where('attendance_year', 2026)->value($column);
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
            'schl_name' => 'Attendance Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'attendance_test',
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
            throw new RuntimeException('Attendance tests must only use the test school database.');
        }

        // Old tables have zero-date defaults, which strict mode refuses when seeding.
        $connection->statement("SET SESSION sql_mode = ''");

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('session_info')->insert(['session_code' => self::SESSION, 'session_name' => '2026', 'session_year' => 2026, 'running_session' => 1]);
        $db->table('class_info')->insert(['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1]);
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
        $db->table('class_teacher_info')->insert([
            'teacher_code' => self::TEACHER_CODE, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID,
            'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'master_teacher' => '1',
        ]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9501, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9502, 'student_name' => 'Maria'],
            ['student_registration_code' => 9503, 'student_name' => 'Nadia'],
        ]);
        $row = fn (array $values) => $values + ['session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'active_status' => 1];
        $db->table('student_current_status_info')->insert([
            $row(['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9501, 'roll_number' => 12]),
            $row(['current_status_info_id' => self::CLASSMATE_ROW, 'student_registration_code' => 9502, 'roll_number' => 3]),
            $row(['current_status_info_id' => self::SECTION_B_ROW, 'student_registration_code' => 9503, 'section_code' => self::SECTION_B, 'roll_number' => 1]),
        ]);

        foreach (['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $i => $name) {
            $db->table('clr_days')->insert(['day_id' => $i + 1, 'day_name' => $name, 'status' => in_array($name, ['Saturday', 'Friday'], true) ? 0 : 1]);
        }
        $db->table('academic_calendar_info')->insert(['event_id' => 1, 'event_title_en' => 'Holiday', 'student_class' => 'All', 'event_attendance' => 0, 'event_status' => 1, 'start_date' => '2026-05-20', 'end_date' => '2026-05-20']);

        // May 2026 for the student. The test table has no auto-increment, so the id is given.
        $may = ['attendance_id' => 1, 'attendance_month' => 5, 'attendance_year' => 2026, 'attendance_class' => self::CLASS_ID, 'attendance_section' => self::SECTION_A, 'attendance_student_id' => self::STUDENT_ROW, 'attendance_creator' => 1, 'attendance_modifier' => 1];
        for ($d = 1; $d <= 31; $d++) {
            $may['d'.$d] = 0;
        }
        $db->table('attendance_info')->insert(array_merge($may, ['d3' => 1, 'd4' => 1, 'd17' => 1, 'd5' => 2, 'd6' => 3, 'd7' => 5]));
    }

    private function tokenFor(string $mobile, string $role, ?string $verifiedId, bool $verified = true): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => $verified,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Attendance Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }
}
