<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Results are read from pathshala_app_test_school, a copy of the real tables' structure with made-up
 * rows. The comment write goes there too. The real school database is never written to.
 */
class ResultApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 80;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const OTHER_CLASS_ID = 9;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const TEACHER_USER = 800;
    private const TEACHER_CODE = 1000;
    private const OTHER_TEACHER_USER = 801;
    private const OTHER_TEACHER_CODE = 1001;
    private const STUDENT_ROW = 7001;
    private const CLASSMATE_ROW = 7002;
    private const SECTION_B_ROW = 7003;

    // Exams of class Eight: published half-yearly, published combined annual, marks edited after the
    // merit list, not yet published, an admission test; and another class's exam.
    private const HALF_YEARLY = 101;
    private const ANNUAL = 102;
    private const EDITED = 103;
    private const NOT_PUBLISHED = 104;
    private const ADMISSION = 105;
    private const OTHER_CLASS_EXAM = 106;

    private const COPIED_TABLES = [
        'exam_info', 'exam_class_info', 'exam_publication_info', 'exam_subject_info', 'subject_mark_info',
        'combine_mark_info', 'exam_overall_result', 'subject_group', 'student_info', 'student_current_status_info',
        'session_info', 'class_info', 'section_info', 'class_teacher_info', 'subject_teacher_info', 'teacher_info', 'users',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $otherTeacherToken;
    private string $studentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000040', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000041', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000042', 'student', '9301');
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
        $this->getJson('/api/v1/students/'.self::STUDENT_ROW.'/results')->assertStatus(401);
        $this->getJson('/api/v1/teacher/my-class/exams')->assertStatus(401);
    }

    public function test_a_student_gets_only_published_exams_oldest_first(): void
    {
        $response = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/results')
            ->assertOk()
            ->assertJsonPath('data.rank_visible', true)
            ->assertJsonPath('data.class_average_visible', true)
            ->assertJsonPath('data.transcript_url', null);

        // Not the edited exam, not the unpublished one, not the admission test.
        $this->assertSame(['101', '102', '102-combined'], array_column($response->json('data.exams'), 'id'));
    }

    public function test_an_exam_has_the_stored_figures_and_section_figures(): void
    {
        $exam = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/results')
            ->json('data.exams.0');

        $this->assertSame([
            'id' => '101',
            'name' => 'Eight - Half Yearly',
            'status' => 'published',
            'published_at' => '2026-07-04T18:00:00Z',
            // The "not added" subject is not counted in the total.
            'total_marks' => 200,
            'obtained_marks' => 150,
            'percentage' => 75,
            'gpa' => 4.5,
            'grade' => 'A',
            'position' => 1,
            // Section A only; the older duplicate row of the student is ignored.
            'class_size' => 2,
            'class_average' => 65,
            'marksheet_url' => null,
            'subjects' => [
                ['name' => 'Math', 'total_marks' => 100, 'obtained_marks' => 80, 'grade' => 'A+'],
                ['name' => 'English', 'total_marks' => 100, 'obtained_marks' => 70, 'grade' => 'A'],
                ['name' => 'Extra', 'total_marks' => 50, 'obtained_marks' => 40, 'grade' => 'A'],
            ],
        ], $exam);
    }

    public function test_a_combined_annual_exam_is_an_extra_entry_with_its_combined_marks(): void
    {
        $exams = $this->withToken($this->studentToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/results')
            ->json('data.exams');

        $this->assertSame('Eight - Annual', $exams[1]['name']);
        $this->assertSame(90, $exams[1]['obtained_marks']);

        $this->assertSame('Eight - Annual (Combined)', $exams[2]['name']);
        $this->assertSame(85, $exams[2]['obtained_marks']);
        // 5.33 is the web system's "golden A+" marker; the GPA itself is at most 5.
        $this->assertSame(5, $exams[2]['gpa']);
        $this->assertSame([['name' => 'Math', 'total_marks' => 100, 'obtained_marks' => 85, 'grade' => 'A+']], $exams[2]['subjects']);
    }

    public function test_a_student_cannot_see_another_students_results(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::CLASSMATE_ROW.'/results')->assertStatus(403);
    }

    public function test_a_teacher_sees_results_of_their_students_only(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/students/'.self::STUDENT_ROW.'/results')
            ->assertOk()
            ->assertJsonCount(3, 'data.exams');

        $this->withToken($this->teacherToken)->getJson('/api/v1/students/'.self::SECTION_B_ROW.'/results')->assertStatus(403);
    }

    public function test_the_class_teacher_sees_every_exam_of_the_class_with_its_status(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teacher/my-class/exams')
            ->assertOk()
            ->assertJsonPath('data', [
                ['id' => '101', 'name' => 'Eight - Half Yearly', 'status' => 'published', 'class_label' => 'Eight'],
                ['id' => '102', 'name' => 'Eight - Annual', 'status' => 'published', 'class_label' => 'Eight'],
                ['id' => '103', 'name' => 'Eight - Edited', 'status' => 'processing', 'class_label' => 'Eight'],
                ['id' => '104', 'name' => 'Eight - Later', 'status' => 'pending', 'class_label' => 'Eight'],
            ]);

        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/teacher/my-class/exams')->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_class_result_sheet_lists_the_teachers_section_in_roll_order(): void
    {
        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teacher/my-class/exams/'.self::HALF_YEARLY)
            ->assertOk()
            ->assertJsonPath('data.exam.status', 'published')
            ->assertJsonPath('data.class_average', 65)
            ->assertJsonPath('data.comments_enabled', true)
            ->assertJsonPath('data.rows', [
                ['student_id' => (string) self::CLASSMATE_ROW, 'student_name' => 'Maria', 'roll' => '7', 'obtained_marks' => 110, 'total_marks' => 200, 'gpa' => 3.5, 'grade' => 'A-', 'position' => 2, 'comment' => null],
                ['student_id' => (string) self::STUDENT_ROW, 'student_name' => 'Rahim', 'roll' => '12', 'obtained_marks' => 150, 'total_marks' => 200, 'gpa' => 4.5, 'grade' => 'A', 'position' => 1, 'comment' => null],
            ]);
    }

    public function test_another_classs_exam_is_refused_and_an_unknown_exam_is_not_found(): void
    {
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/my-class/exams/'.self::OTHER_CLASS_EXAM)->assertStatus(403);
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/my-class/exams/9999')->assertStatus(404);
    }

    public function test_the_teacher_saves_a_comment_the_way_the_web_system_does(): void
    {
        $before = $this->school()->table('exam_overall_result')->where('stud_class_id', self::STUDENT_ROW)->where('exam_class_id', self::HALF_YEARLY)->orderByDesc('exam_overall_result_id')->first();

        $this->withToken($this->teacherToken)
            ->putJson('/api/v1/teacher/my-class/exams/'.self::HALF_YEARLY.'/students/'.self::STUDENT_ROW.'/comment', ['comment' => "Rahim's best result"])
            ->assertOk()
            ->assertJsonPath('status', true);

        $after = $this->school()->table('exam_overall_result')->where('exam_overall_result_id', $before->exam_overall_result_id)->first();

        // Stored with addslashes, as the web comment screen stores it. Nothing else changes.
        $this->assertSame("Rahim\\'s best result", $after->remarks_note);
        $this->assertSame($before->overall_total_mark, $after->overall_total_mark);
        $this->assertSame($before->average_gpa, $after->average_gpa);

        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/teacher/my-class/exams/'.self::HALF_YEARLY)
            ->assertJsonPath('data.rows.1.comment', "Rahim's best result");
    }

    public function test_a_comment_is_refused_outside_the_teachers_class_or_before_the_result_exists(): void
    {
        $url = fn (int $exam, int $student) => '/api/v1/teacher/my-class/exams/'.$exam.'/students/'.$student.'/comment';

        $this->withToken($this->teacherToken)->putJson($url(self::HALF_YEARLY, self::SECTION_B_ROW), ['comment' => 'x'])->assertStatus(403);
        $this->withToken($this->teacherToken)->putJson($url(self::NOT_PUBLISHED, self::STUDENT_ROW), ['comment' => 'x'])->assertStatus(403);
        $this->withToken($this->otherTeacherToken)->putJson($url(self::HALF_YEARLY, self::STUDENT_ROW), ['comment' => 'x'])->assertStatus(403);
        $this->withToken($this->studentToken)->putJson($url(self::HALF_YEARLY, self::STUDENT_ROW), ['comment' => 'x'])->assertStatus(403);
    }

    public function test_a_comment_must_be_sent_and_fit_the_column(): void
    {
        $url = '/api/v1/teacher/my-class/exams/'.self::HALF_YEARLY.'/students/'.self::STUDENT_ROW.'/comment';

        $this->withToken($this->teacherToken)->putJson($url, [])->assertStatus(422)->assertJsonStructure(['errors' => ['comment']]);
        $this->withToken($this->teacherToken)->putJson($url, ['comment' => str_repeat('a', 301)])->assertStatus(422);
    }

    public function test_a_student_cannot_use_the_teacher_endpoints(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/teacher/my-class/exams')->assertStatus(403);
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
            'schl_name' => 'Result Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'result_test',
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
            throw new RuntimeException('Result tests must only use the test school database.');
        }

        // Old tables have zero-date defaults, which strict mode refuses when seeding.
        $connection->statement("SET SESSION sql_mode = ''");

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
            ['student_registration_code' => 9301, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9302, 'student_name' => 'Maria'],
            ['student_registration_code' => 9303, 'student_name' => 'Nadia'],
        ]);
        $row = fn (array $values) => $values + ['session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'active_status' => 1];
        $db->table('student_current_status_info')->insert([
            $row(['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9301, 'roll_number' => 12]),
            $row(['current_status_info_id' => self::CLASSMATE_ROW, 'student_registration_code' => 9302, 'roll_number' => 7]),
            $row(['current_status_info_id' => self::SECTION_B_ROW, 'student_registration_code' => 9303, 'section_code' => self::SECTION_B, 'roll_number' => 1]),
        ]);

        $db->table('exam_info')->insert([
            ['exam_id' => 1, 'exam_name' => 'Half Yearly', 'exam_type' => 1],
            ['exam_id' => 2, 'exam_name' => 'Annual', 'exam_type' => 2],
            ['exam_id' => 3, 'exam_name' => 'Admission', 'exam_type' => 10],
        ]);
        $exam = fn (int $id, int $examId, string $name, array $extra = []) => $extra + [
            'exam_class_id' => $id, 'exam_id' => $examId, 'custom_exam_name' => $name, 'class_code' => self::CLASS_ID,
            'session_code' => self::SESSION, 'shift_code' => 0, 'exam_class_active_status' => 1, 'examclass_status' => 0, 'exam_total_mark' => null,
            'exam_combine_or_not' => 0,
        ];
        $db->table('exam_class_info')->insert([
            $exam(self::HALF_YEARLY, 1, 'Eight - Half Yearly'),
            $exam(self::ANNUAL, 2, 'Eight - Annual', ['exam_combine_or_not' => 1]),
            $exam(self::EDITED, 1, 'Eight - Edited', ['examclass_status' => 1]),
            $exam(self::NOT_PUBLISHED, 1, 'Eight - Later'),
            $exam(self::ADMISSION, 3, 'Eight - Admission'),
            $exam(self::OTHER_CLASS_EXAM, 1, 'Nine - Half Yearly', ['class_code' => self::OTHER_CLASS_ID]),
        ]);
        $publication = fn (int $id, string $date) => ['exam_class_id' => $id, 'published_exam' => 1, 'web_pub_date' => $date];
        $db->table('exam_publication_info')->insert([
            $publication(self::HALF_YEARLY, '2026-07-05'),
            $publication(self::ANNUAL, '2026-10-01'),
            $publication(self::EDITED, '2026-07-05'),
            $publication(self::NOT_PUBLISHED, '2099-01-01'),
            $publication(self::ADMISSION, '2026-07-05'),
            $publication(self::OTHER_CLASS_EXAM, '2026-07-05'),
        ]);

        $db->table('subject_group')->insert([
            ['subject_group_id' => 10, 'subject_group_name' => 'Math', 'subject_group_position' => 1],
            ['subject_group_id' => 11, 'subject_group_name' => 'English', 'subject_group_position' => 2],
            ['subject_group_id' => 12, 'subject_group_name' => 'Extra', 'subject_group_position' => 3],
        ]);
        $db->table('exam_subject_info')->insert([
            ['exam_subject_id' => 1001, 'exam_class_id' => self::HALF_YEARLY, 'subject_group_code' => 10, 'total_marks' => 100, 'no_add_table' => 0],
            ['exam_subject_id' => 1002, 'exam_class_id' => self::HALF_YEARLY, 'subject_group_code' => 11, 'total_marks' => 100, 'no_add_table' => 0],
            ['exam_subject_id' => 1003, 'exam_class_id' => self::HALF_YEARLY, 'subject_group_code' => 12, 'total_marks' => 50, 'no_add_table' => 1],
            ['exam_subject_id' => 2001, 'exam_class_id' => self::ANNUAL, 'subject_group_code' => 10, 'total_marks' => 100, 'no_add_table' => 0],
        ]);
        $mark = fn (int $student, int $subject, float $marks, string $grade) => ['stud_class_id' => $student, 'exam_subject_id' => $subject, 'exact_total_mark' => $marks, 'letter_grade' => $grade];
        $db->table('subject_mark_info')->insert([
            $mark(self::STUDENT_ROW, 1001, 80, 'A+'), $mark(self::STUDENT_ROW, 1002, 70, 'A'), $mark(self::STUDENT_ROW, 1003, 40, 'A'),
            $mark(self::CLASSMATE_ROW, 1001, 60, 'A-'), $mark(self::CLASSMATE_ROW, 1002, 50, 'B'),
            $mark(self::SECTION_B_ROW, 1001, 90, 'A+'), $mark(self::SECTION_B_ROW, 1002, 90, 'A+'),
            $mark(self::STUDENT_ROW, 2001, 90, 'A+'),
        ]);
        $db->table('combine_mark_info')->insert($mark(self::STUDENT_ROW, 2001, 85, 'A+'));

        // The test school's table has no auto-increment, so the ids are given; the latest id wins.
        $id = 0;
        $result = function (int $student, int $exam, float $total, float $gpa, string $grade, int $position, int $final = 0, int $status = 1) use (&$id) {
            return [
                'exam_overall_result_id' => ++$id, 'stud_class_id' => $student, 'exam_class_id' => $exam,
                'overall_total_mark' => $total, 'average_gpa' => $gpa, 'letter_grade' => $grade, 'merit_sectionwise' => $position,
                'final_result' => $final, 'status' => $status, 'remarks_note' => null,
            ];
        };
        $db->table('exam_overall_result')->insert([
            // An older duplicate of the student's half-yearly row; the latest id wins.
            $result(self::STUDENT_ROW, self::HALF_YEARLY, 1, 1, 'F', 9),
            $result(self::STUDENT_ROW, self::HALF_YEARLY, 150, 4.5, 'A', 1),
            $result(self::CLASSMATE_ROW, self::HALF_YEARLY, 110, 3.5, 'A-', 2),
            $result(self::SECTION_B_ROW, self::HALF_YEARLY, 180, 5, 'A+', 1),
            $result(self::STUDENT_ROW, self::ANNUAL, 90, 5, 'A+', 1, 0, 2),
            $result(self::STUDENT_ROW, self::ANNUAL, 85, 5.33, 'A+', 1, 1, 3),
            $result(self::STUDENT_ROW, self::EDITED, 100, 4, 'A', 1),
            $result(self::CLASSMATE_ROW, self::NOT_PUBLISHED, 100, 4, 'A', 1),
            $result(self::STUDENT_ROW, self::ADMISSION, 50, 0, 'F', 1),
        ]);
    }

    private function tokenFor(string $mobile, string $role, ?string $verifiedId, bool $verified = true): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => $verified,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Result Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }
}
