<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Profiles are read from pathshala_app_test_school, a copy of the real tables' structure with
 * made-up rows. The real school database is never written to.
 */
class ProfileApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 79;
    private const SESSION = 20;
    private const OLD_SESSION = 19;
    private const CLASS_ID = 8;
    private const OLD_CLASS_ID = 7;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const TEACHER_USER = 700;
    private const TEACHER_CODE = 900;
    private const OTHER_TEACHER_USER = 701;
    private const OTHER_TEACHER_CODE = 901;
    private const OLD_ROW = 6001;
    private const STUDENT_ROW = 6002;
    private const OTHER_STUDENT_ROW = 6003;
    private const DELETED_ROW = 6004;
    private const CLASSMATE_ROW = 6005;
    private const INACTIVE_ROW = 6006;

    private const COPIED_TABLES = [
        'student_info', 'student_current_status_info', 'session_info', 'class_info', 'section_info', 'group_info',
        'shift_info', 'remission_info', 'category_info', 'religion_infos', 'class_teacher_info', 'subject_teacher_info',
        'teacher_info', 'users', 'exam_overall_result', 'institute_info', 'app_stu_profile_change_request',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $otherTeacherToken;
    private string $studentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000030', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000031', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000032', 'student', '9201');
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
        $this->getJson('/api/v1/students/'.self::STUDENT_ROW.'/profile')->assertStatus(401);
        $this->getJson('/api/v1/teacher/my-class/students')->assertStatus(401);
    }

    public function test_a_student_gets_their_full_profile(): void
    {
        $response = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/profile')
            ->assertOk()
            ->assertJsonPath('status', true);

        $response->assertJsonPath('data.student_id', (string) self::STUDENT_ROW)
            ->assertJsonPath('data.name_en', 'Rahim Ahmed')
            ->assertJsonPath('data.name_bn', 'রহিম আহমেদ')
            ->assertJsonPath('data.student_no', '009201')
            ->assertJsonPath('data.roll_no', '12')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.session', '2026')
            ->assertJsonPath('data.class', 'Eight')
            ->assertJsonPath('data.section', 'A')
            ->assertJsonPath('data.class_teacher', 'Hasan')
            ->assertJsonPath('data.admission_date', '2020-01-06')
            ->assertJsonPath('data.admission_class', 'Seven')
            ->assertJsonPath('data.student_category', 'General')
            ->assertJsonPath('data.date_of_birth', '2012-09-20')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.religion', 'Islam')
            ->assertJsonPath('data.blood_group', 'O+')
            ->assertJsonPath('data.nationality', 'Bangladeshi')
            ->assertJsonPath('data.photo_url', 'https://cloud.test/bucket/999999/student/47/9201.jpg')
            ->assertJsonPath('data.documents', []);

        // The contract: masked always, full never in this API.
        $response->assertJsonPath('data.birth_reg', ['masked' => '**** **** **** 5521', 'full' => null]);
        $response->assertJsonPath('data.nid', ['masked' => null, 'full' => null]);

        $response->assertJsonPath('data.guardians.0.relation', 'Father')
            ->assertJsonPath('data.guardians.0.nid', ['masked' => '**** **** 9034', 'full' => null])
            ->assertJsonPath('data.guardians.2.relation', 'Guardian')
            ->assertJsonPath('data.guardians.2.mobile', '01712445019')
            ->assertJsonPath('data.guardians.2.email', 'karim@example.com');

        // Oldest first; 5.33 (the web system's "golden A+") is shown as 5.
        $response->assertJsonPath('data.history', [
            ['session' => '2025', 'class' => 'Seven', 'section' => 'A', 'roll' => '15', 'gpa' => 5, 'status' => 'Promoted'],
            ['session' => '2026', 'class' => 'Eight', 'section' => 'A', 'roll' => '12', 'gpa' => null, 'status' => 'Current'],
        ]);

        $this->assertStringNotContainsString('1994556790', $response->getContent());
    }

    public function test_an_older_row_of_the_student_shows_the_current_placement(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::OLD_ROW.'/profile')
            ->assertOk()
            ->assertJsonPath('data.student_id', (string) self::STUDENT_ROW)
            ->assertJsonPath('data.class', 'Eight');
    }

    public function test_a_student_cannot_see_another_students_profile(): void
    {
        $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/profile')
            ->assertStatus(403);
    }

    public function test_a_teacher_sees_their_students_profile_without_guardian_contact(): void
    {
        $response = $this->withToken($this->teacherToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/profile')
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Rahim Ahmed')
            ->assertJsonPath('data.birth_reg.masked', '**** **** **** 5521');

        foreach ($response->json('data.guardians') as $guardian) {
            $this->assertNull($guardian['mobile']);
            $this->assertNull($guardian['email']);
            $this->assertSame(['masked' => null, 'full' => null], $guardian['nid']);
        }
    }

    public function test_a_teacher_cannot_see_a_student_they_do_not_teach(): void
    {
        // Section B is not the teacher's section.
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/profile')
            ->assertStatus(403);

        $this->withToken($this->otherTeacherToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/profile')
            ->assertStatus(403);
    }

    public function test_a_deleted_or_unknown_student_is_not_found(): void
    {
        $this->withToken($this->teacherToken)->getJson('/api/v1/students/'.self::DELETED_ROW.'/profile')->assertStatus(404);
        $this->withToken($this->teacherToken)->getJson('/api/v1/students/999999/profile')->assertStatus(404);
    }

    public function test_the_class_teacher_gets_their_class_list_in_roll_order(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teacher/my-class/students')
            ->assertOk()
            // Section A's active students only: not section B, not the deleted row, not last year's row.
            ->assertJsonPath('data', [
                ['student_id' => (string) self::CLASSMATE_ROW, 'name' => 'Tanvir', 'roll' => '3', 'status' => 'active'],
                ['student_id' => (string) self::STUDENT_ROW, 'name' => 'Rahim Ahmed', 'roll' => '12', 'status' => 'active'],
            ]);
    }

    public function test_a_teacher_who_is_not_a_class_teacher_gets_an_empty_list(): void
    {
        $this->withToken($this->otherTeacherToken)
            ->getJson('/api/v1/teacher/my-class/students')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_student_cannot_use_the_class_list(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/teacher/my-class/students')->assertStatus(403);
    }

    public function test_a_student_sends_a_correction_request_with_a_snapshot_of_their_placement(): void
    {
        $body = ['field' => 'Blood group', 'requested_value' => 'A+', 'note' => 'রিপোর্ট সংযুক্ত'];

        $this->withToken($this->studentToken)
            ->withHeader('Idempotency-Key', 'profile-change-6002-1')
            ->postJson('/api/v1/students/'.self::STUDENT_ROW.'/profile/change-requests', $body)
            ->assertStatus(201)
            ->assertJsonPath('status', true);

        $request = $this->school()->table('app_stu_profile_change_request')->sole();

        $this->assertSame(9201, (int) $request->student_registration_code);
        $this->assertSame(self::STUDENT_ROW, (int) $request->current_status_info_id);
        $this->assertSame('2026', $request->session_name);
        $this->assertSame('Eight', $request->class_name);
        $this->assertSame('12', $request->roll_number);
        $this->assertSame(1, (int) $request->student_status);
        $this->assertSame(0, (int) $request->status);
        $this->assertSame($body, json_decode($request->request_data, true));
        $this->assertSame((int) AppUser::where('auth_token', $this->studentToken)->value('id'), (int) $request->app_user_id);

        // The profile itself is never changed.
        $this->assertSame(5, (int) $this->school()->table('student_info')->where('student_registration_code', 9201)->value('student_bg'));
    }

    public function test_the_same_idempotency_key_is_one_request(): void
    {
        $send = fn () => $this->withToken($this->studentToken)
            ->withHeader('Idempotency-Key', 'profile-change-6002-2')
            ->postJson('/api/v1/students/'.self::STUDENT_ROW.'/profile/change-requests', ['field' => 'Address', 'requested_value' => 'Sylhet']);

        $send()->assertStatus(201);
        $send()->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->school()->table('app_stu_profile_change_request')->count());
    }

    public function test_a_correction_request_needs_the_field_and_the_value(): void
    {
        $this->withToken($this->studentToken)
            ->postJson('/api/v1/students/'.self::STUDENT_ROW.'/profile/change-requests', ['note' => 'x'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['field', 'requested_value']]);
    }

    public function test_only_the_active_student_themself_can_ask_for_a_correction(): void
    {
        $body = ['field' => 'Blood group', 'requested_value' => 'A+'];

        $this->withToken($this->studentToken)
            ->postJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/profile/change-requests', $body)
            ->assertStatus(403);

        $this->withToken($this->teacherToken)
            ->postJson('/api/v1/students/'.self::STUDENT_ROW.'/profile/change-requests', $body)
            ->assertStatus(403);

        $inactiveToken = $this->tokenFor('01700000034', 'student', '9205');

        $this->withToken($inactiveToken)
            ->postJson('/api/v1/students/'.self::INACTIVE_ROW.'/profile/change-requests', $body)
            ->assertStatus(403);

        $this->assertSame(0, $this->school()->table('app_stu_profile_change_request')->count());
    }

    public function test_guests_have_no_profile(): void
    {
        $guestToken = $this->tokenFor('01700000033', 'student', null, verified: false);

        $this->withToken($guestToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/profile')->assertStatus(403);
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
            'schl_name' => 'Profile Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'profile_test',
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
            throw new RuntimeException('Profile tests must only use the test school database.');
        }

        // Old tables have zero-date defaults, which strict mode refuses when seeding.
        $connection->statement("SET SESSION sql_mode = ''");

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('institute_info')->insert(['institute_code' => 1, 'institute_EIIN' => '999999', 'institute_storage_path' => 'https://cloud.test/bucket/', 'institute_website' => 'school.test']);
        $db->table('session_info')->insert([
            ['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1],
            ['session_code' => self::OLD_SESSION, 'session_name' => '2025', 'running_session' => 0],
        ]);
        $db->table('class_info')->insert([
            ['class_code' => self::OLD_CLASS_ID, 'class_name' => 'Seven', 'class_activation_status' => 1, 'class_position' => 1],
            ['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 2],
        ]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_A, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => self::SECTION_B, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('category_info')->insert(['category_code' => 1, 'category_name' => 'General']);
        $db->table('religion_infos')->insert(['religion_id' => 1, 'religion_code' => 1, 'religion_name' => 'Islam']);
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
            [
                'student_registration_code' => 9201, 'student_name' => 'Rahim Ahmed', 'student_name_bn' => 'রহিম আহমেদ',
                'student_admission_date' => '2020-01-06', 'birth_date' => '2012-09-20', 'student_gender' => 1,
                'student_religion' => 1, 'student_bg' => 5, 'student_category' => 1, 'stu_nationality' => 'Bangladeshi',
                'birth_cer_number' => '2012 1234 5678 5521', 'img_ext' => '.jpg',
                'student_present_address' => 'Mirpur, Dhaka', 'student_permanent_address' => 'Sylhet',
                'father_name' => 'Karim Ahmed', 'father_nid_number' => '1994 5567 9034',
                'mother_name' => 'Amina Begum', 'mother_nid_number' => '',
                'guardian_name' => 'Karim Ahmed', 'guardian_occupation' => 'Business', 'guardian_email' => 'karim@example.com',
                'guardian_nid_number' => '1975 4432 1180', 'contact_number' => '01712445019',
            ],
            ['student_registration_code' => 9202, 'student_name' => 'Maria', 'student_name_bn' => null, 'student_admission_date' => null, 'birth_date' => null, 'student_gender' => 2, 'student_religion' => 0, 'student_bg' => 0, 'student_category' => 0, 'stu_nationality' => null, 'birth_cer_number' => null, 'img_ext' => null, 'student_present_address' => null, 'student_permanent_address' => null, 'father_name' => null, 'father_nid_number' => null, 'mother_name' => null, 'mother_nid_number' => null, 'guardian_name' => null, 'guardian_occupation' => null, 'guardian_email' => null, 'guardian_nid_number' => null, 'contact_number' => null],
            ['student_registration_code' => 9203, 'student_name' => 'Old', 'student_name_bn' => null, 'student_admission_date' => null, 'birth_date' => null, 'student_gender' => 1, 'student_religion' => 0, 'student_bg' => 0, 'student_category' => 0, 'stu_nationality' => null, 'birth_cer_number' => null, 'img_ext' => null, 'student_present_address' => null, 'student_permanent_address' => null, 'father_name' => null, 'father_nid_number' => null, 'mother_name' => null, 'mother_nid_number' => null, 'guardian_name' => null, 'guardian_occupation' => null, 'guardian_email' => null, 'guardian_nid_number' => null, 'contact_number' => null],
            ['student_registration_code' => 9205, 'student_name' => 'Left', 'student_name_bn' => null, 'student_admission_date' => null, 'birth_date' => null, 'student_gender' => 1, 'student_religion' => 0, 'student_bg' => 0, 'student_category' => 0, 'stu_nationality' => null, 'birth_cer_number' => null, 'img_ext' => null, 'student_present_address' => null, 'student_permanent_address' => null, 'father_name' => null, 'father_nid_number' => null, 'mother_name' => null, 'mother_nid_number' => null, 'guardian_name' => null, 'guardian_occupation' => null, 'guardian_email' => null, 'guardian_nid_number' => null, 'contact_number' => null],
            ['student_registration_code' => 9204, 'student_name' => 'Tanvir', 'student_name_bn' => null, 'student_admission_date' => null, 'birth_date' => null, 'student_gender' => 1, 'student_religion' => 0, 'student_bg' => 0, 'student_category' => 0, 'stu_nationality' => null, 'birth_cer_number' => null, 'img_ext' => null, 'student_present_address' => null, 'student_permanent_address' => null, 'father_name' => null, 'father_nid_number' => null, 'mother_name' => null, 'mother_nid_number' => null, 'guardian_name' => null, 'guardian_occupation' => null, 'guardian_email' => null, 'guardian_nid_number' => null, 'contact_number' => null],
        ]);

        $row = fn (array $values) => $values + ['session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'remission_code' => 0, 'active_status' => 1];

        $db->table('student_current_status_info')->insert([
            $row(['current_status_info_id' => self::OLD_ROW, 'student_registration_code' => 9201, 'session_code' => self::OLD_SESSION, 'class_code' => self::OLD_CLASS_ID, 'roll_number' => 15, 'active_status' => 4]),
            $row(['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9201, 'roll_number' => 12]),
            $row(['current_status_info_id' => self::OTHER_STUDENT_ROW, 'student_registration_code' => 9202, 'section_code' => self::SECTION_B, 'roll_number' => 7]),
            $row(['current_status_info_id' => self::DELETED_ROW, 'student_registration_code' => 9203, 'roll_number' => 5, 'active_status' => 5]),
            $row(['current_status_info_id' => self::CLASSMATE_ROW, 'student_registration_code' => 9204, 'roll_number' => 3]),
            $row(['current_status_info_id' => self::INACTIVE_ROW, 'student_registration_code' => 9205, 'section_code' => self::SECTION_B, 'roll_number' => 9, 'active_status' => 3]),
        ]);

        // Last year's final result, stored with the "golden A+" GPA.
        $db->table('exam_overall_result')->insert(['stud_class_id' => self::OLD_ROW, 'exam_class_id' => 1, 'final_result' => 1, 'status' => 2, 'average_gpa' => 5.33, 'overall_total_mark' => 700]);
    }

    private function tokenFor(string $mobile, string $role, ?string $verifiedId, bool $verified = true): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => $verified,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Profile Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }
}
