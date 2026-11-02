<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\ActivityLogService;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Homework writes run against pathshala_app_test_school, a copy of the real tables' structure
 * with made-up rows. The real school database is never written to.
 */
class HomeworkApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 77;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const SECTION_ID = 1;
    private const SUBJECT_ID = 143;
    private const TEACHER_USER = 500;
    private const TEACHER_CODE = 700;
    private const OTHER_TEACHER_USER = 501;
    private const OTHER_TEACHER_CODE = 701;
    private const BANNED_TEACHER_USER = 502;
    private const STUDENT_ROW = 4723;
    private const OTHER_STUDENT_ROW = 4724;
    private const INACTIVE_STUDENT_ROW = 4725;

    private const COPIED_TABLES = [
        'hw_api_idempotency', 'hw_assessment_feedback_tags', 'hw_assessment_rubric_scores', 'hw_assessments',
        'hw_assignment_attachments', 'hw_assignment_formats', 'hw_assignment_rubric_criteria', 'hw_assignment_students',
        'hw_assignment_types', 'hw_assignments', 'hw_feedback_tags', 'hw_notification_events', 'hw_notification_logs',
        'hw_resubmission_requests', 'hw_settings', 'hw_submission_files', 'hw_submissions',
        'users', 'teacher_info', 'student_info', 'student_current_status_info', 'session_info', 'class_info',
        'section_info', 'shift_info', 'group_info', 'subject_info', 'subject_teacher_info', 'institute_info',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $otherTeacherToken;
    private string $studentToken;
    private string $otherStudentToken;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000010', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000011', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000012', 'student', '9001');
        $this->otherStudentToken = $this->tokenFor('01700000013', 'student', '9002');
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('school_information')->where('schl_id', self::TEST_SCHOOL_ID)->delete();

        parent::tearDown();
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
            'schl_name' => 'Homework Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'hw_test',
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
            throw new RuntimeException('Homework tests must only write to the test school database.');
        }

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('institute_info')->insert(['institute_code' => 1, 'institute_EIIN' => '999999', 'institute_storage_path' => 'https://cloud.test/bucket/']);
        $db->table('session_info')->insert(['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1]);
        $db->table('class_info')->insert(['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_ID, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => 2, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('subject_info')->insert(['subject_code' => self::SUBJECT_ID, 'subject_name' => 'Math']);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1],
            ['teacher_code' => self::OTHER_TEACHER_CODE, 'teacher_name' => 'Karim', 'teacher_active_status' => 1],
        ]);
        $db->table('users')->insert([
            ['id' => self::TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::OTHER_TEACHER_USER, 'username' => 'karim', 'user_full_name' => 'Karim', 'teacher_code' => self::OTHER_TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::BANNED_TEACHER_USER, 'username' => 'gone', 'user_full_name' => 'Gone', 'teacher_code' => 0, 'user_type' => 'Teacher', 'activated' => 0, 'banned' => 1, 'password' => 'x'],
        ]);
        $db->table('subject_teacher_info')->insert([
            'teacher_code' => self::TEACHER_CODE, 'subject_code' => self::SUBJECT_ID, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'session_code' => self::SESSION,
        ]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9001, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9002, 'student_name' => 'Maria'],
            ['student_registration_code' => 9003, 'student_name' => 'Old'],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9001, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => self::OTHER_STUDENT_ROW, 'student_registration_code' => 9002, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 7, 'active_status' => 1],
            ['current_status_info_id' => self::INACTIVE_STUDENT_ROW, 'student_registration_code' => 9003, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 3, 'active_status' => 0],
        ]);
        $db->table('hw_settings')->insert(['institute_code' => 1, 'default_assessment_mode' => 'simple', 'allow_mode_override' => 1, 'allow_free_resubmission' => 0, 'allow_late_submission' => 1, 'reminder_hours_before_due' => 24]);
        $db->table('hw_assignment_types')->insert([['id' => 1, 'type_name' => 'Homework', 'sort_order' => 1, 'status' => 1], ['id' => 3, 'type_name' => 'Class Work', 'sort_order' => 3, 'status' => 1]]);
        $db->table('hw_feedback_tags')->insert([['id' => 3, 'tag_name' => 'Good', 'sort_order' => 3, 'status' => 1], ['id' => 4, 'tag_name' => 'Need Improvement', 'sort_order' => 4, 'status' => 1]]);
    }

    private function tokenFor(string $mobile, string $role, ?string $verifiedId, bool $verified = true): string
    {
        $user = AppUser::create([
            'mobile_number' => $mobile,
            'role' => $role,
            'verified_id' => $verifiedId,
            'is_verified' => $verified,
            'schl_id' => self::TEST_SCHOOL_ID,
            'school_name' => 'Homework Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }

    private function homeworkBody(array $overrides = []): array
    {
        return $overrides + [
            'class_id' => self::CLASS_ID,
            'section_id' => self::SECTION_ID,
            'subject_id' => self::SUBJECT_ID,
            'type' => 'homework',
            'title' => 'বীজগণিত ৪.৩',
            'description' => 'Solve 1 to 12',
            'chapter' => 'Chapter 4',
            'due_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z'),
            'total_marks' => 10,
            'passing_marks' => 4,
            'allowed_formats' => ['text', 'image', 'pdf', 'link'],
        ];
    }

    private function createHomework(array $overrides = [], ?string $token = null): string
    {
        return $this->withToken($token ?? $this->teacherToken)
            ->postJson('/api/v1/teacher/homework', $this->homeworkBody($overrides))
            ->assertCreated()
            ->json('data.id');
    }

    private function handIn(string $homeworkId, array $body = ['text' => 'My answer'], int $studentRow = self::STUDENT_ROW, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->studentToken)
            ->postJson('/api/v1/students/'.$studentRow.'/homework/'.$homeworkId.'/submissions', $body);
    }

    public function test_tests_never_use_the_real_school_for_writes(): void
    {
        $this->assertSame(self::TEST_SCHOOL_DB, $this->school()->getDatabaseName());
        $this->assertNotSame(self::SCHOOL_DB, self::TEST_SCHOOL_DB);
    }

    public function test_no_token_is_refused(): void
    {
        $this->getJson('/api/v1/teacher/homework')->assertStatus(401);
        $this->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertStatus(401);
    }

    public function test_roles_cannot_use_each_others_endpoints(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/teacher/homework')->assertStatus(403);
        $this->withToken($this->teacherToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertStatus(403);
    }

    public function test_a_student_cannot_see_another_students_homework(): void
    {
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/homework')->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/homework/summary')->assertStatus(403);
    }

    public function test_guests_have_no_homework(): void
    {
        $guest = $this->tokenFor('01700000014', 'student', null, verified: false);
        $this->withToken($guest)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertStatus(403);

        $teacherGuest = $this->tokenFor('01700000015', 'teacher', null, verified: false);
        $this->withToken($teacherGuest)->getJson('/api/v1/teacher/homework')->assertStatus(403);
    }

    public function test_a_banned_teacher_loses_access_with_a_token_already_issued(): void
    {
        $banned = $this->tokenFor('01700000016', 'teacher', (string) self::BANNED_TEACHER_USER);

        $this->withToken($banned)->getJson('/api/v1/teacher/homework')->assertStatus(403);
    }

    public function test_full_cycle_create_submit_assess_resubmit(): void
    {
        $homeworkId = $this->createHomework();

        $list = $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertOk();
        $list->assertJsonPath('status', true)
            ->assertJsonPath('data.0.id', $homeworkId)
            ->assertJsonPath('data.0.status', 'not_submitted')
            ->assertJsonPath('data.0.title', 'বীজগণিত ৪.৩')
            ->assertJsonPath('data.0.teacher_name', 'Hasan')
            ->assertJsonPath('data.0.subject', 'Math')
            ->assertJsonPath('data.0.type', 'homework')
            ->assertJsonPath('data.0.allowed_formats', ['text', 'image', 'pdf', 'link'])
            ->assertJsonMissingPath('data.0._version_count');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $list->json('data.0.due_at'));

        $this->handIn($homeworkId, ['text' => 'উত্তর', 'link' => 'https://example.com/work'])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submissions.0.version', 1)
            ->assertJsonPath('data.submissions.0.text', 'উত্তর')
            ->assertJsonPath('data.submissions.0.link', 'https://example.com/work')
            ->assertJsonPath('data.submissions.0.is_late', false);

        $this->assertSame('app', $this->school()->table('hw_submissions')->value('submitted_via'));

        $this->handIn($homeworkId)->assertStatus(422)->assertJsonPath('message', 'This homework is already handed in');

        $this->withToken($this->teacherToken)->getJson("/api/v1/teacher/homework/$homeworkId/submissions")->assertOk()
            ->assertJsonPath('data.0.student_id', (string) self::OTHER_STUDENT_ROW)
            ->assertJsonPath('data.0.status', 'not_submitted')
            ->assertJsonPath('data.1.student_id', (string) self::STUDENT_ROW)
            ->assertJsonPath('data.1.status', 'submitted')
            ->assertJsonPath('data.1.student_name', 'Rahim')
            ->assertJsonPath('data.1.versions', 1);

        $resubmission = "/api/v1/teacher/homework/$homeworkId/submissions/".self::STUDENT_ROW.'/resubmission';
        $this->withToken($this->teacherToken)->postJson($resubmission, ['reason' => ''])->assertStatus(422);
        $this->withToken($this->teacherToken)->postJson($resubmission, ['reason' => 'Fix question 4'])->assertOk()
            ->assertJsonPath('data.status', 'resubmit_requested')
            ->assertJsonPath('data.resubmission_reason', 'Fix question 4');
        $this->withToken($this->teacherToken)->postJson($resubmission, ['reason' => 'Again'])->assertStatus(422);

        $assessment = "/api/v1/teacher/homework/$homeworkId/submissions/".self::STUDENT_ROW.'/assessment';
        $this->withToken($this->teacherToken)->postJson($assessment, ['obtained_marks' => 8])->assertStatus(422);

        $this->handIn($homeworkId, ['text' => 'Fixed'])->assertCreated()
            ->assertJsonPath('data.submissions.1.version', 2)
            ->assertJsonPath('data.submissions.0.text', 'উত্তর');

        $this->withToken($this->teacherToken)->postJson($assessment, ['obtained_marks' => 11])->assertStatus(422);
        $this->withToken($this->teacherToken)->postJson($assessment, ['obtained_marks' => 8, 'tags' => ['bad_tag']])->assertStatus(422);

        $this->withToken($this->teacherToken)->postJson($assessment, ['obtained_marks' => 8, 'tags' => ['good'], 'feedback' => 'ভালো'])->assertOk()
            ->assertJsonPath('data.status', 'checked')
            ->assertJsonPath('data.assessment.obtained_marks', 8)
            ->assertJsonPath('data.assessment.tags', ['good'])
            ->assertJsonPath('data.assessment.feedback', 'ভালো');

        $this->withToken($this->teacherToken)->postJson($assessment, ['obtained_marks' => 9])->assertOk()
            ->assertJsonPath('data.assessment.obtained_marks', 9);
        $this->assertSame(1, $this->school()->table('hw_assessments')->count());

        $this->handIn($homeworkId)->assertStatus(422)->assertJsonPath('message', 'This homework is already checked');

        $summary = $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework/summary')->assertOk();
        $summary->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.completed', 1)
            ->assertJsonPath('data.by_subject.0.subject', 'Math');
        $this->assertEquals(90.0, $summary->json('data.average_score_percent'));

        $this->assertSame(
            ['assigned', 'submitted', 'resubmission_requested', 'submitted', 'assessed', 'assessed'],
            $this->school()->table('hw_notification_events')->orderBy('id')->pluck('event_type')->all(),
        );
    }

    public function test_rubric_homework_is_marked_by_criteria(): void
    {
        $body = ['rubric' => [['name' => 'Accuracy', 'max' => 6], ['name' => 'Presentation', 'max' => 3]]];

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody($body))
            ->assertStatus(422)
            ->assertJsonPath('errors.rubric.0', 'The max values add up to 9 but total_marks is 10.');

        $homeworkId = $this->createHomework(['rubric' => [['name' => 'Accuracy', 'max' => 6], ['name' => 'Presentation', 'max' => 4]]]);
        $this->handIn($homeworkId)->assertCreated();

        $url = "/api/v1/teacher/homework/$homeworkId/submissions/".self::STUDENT_ROW.'/assessment';

        $this->withToken($this->teacherToken)->postJson($url, ['criteria' => [['name' => 'Accuracy', 'obtained' => 7], ['name' => 'Presentation', 'obtained' => 2]]])->assertStatus(422);
        $this->withToken($this->teacherToken)->postJson($url, ['criteria' => [['name' => 'Accuracy', 'obtained' => 5]]])->assertStatus(422);

        $this->withToken($this->teacherToken)->postJson($url, ['criteria' => [['name' => ' accuracy ', 'obtained' => 5], ['name' => 'PRESENTATION', 'obtained' => 2.5]]])->assertOk()
            ->assertJsonPath('data.assessment.obtained_marks', 7.5)
            ->assertJsonPath('data.assessment.criteria.0.name', 'Accuracy')
            ->assertJsonPath('data.assessment.criteria.0.max', 6);
    }

    public function test_create_lists_every_wrong_field(): void
    {
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', [])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['class_id', 'subject_id', 'type', 'title', 'description', 'due_at', 'total_marks', 'allowed_formats']]);

        $this->assertSame(0, $this->school()->table('hw_assignments')->count());
    }

    public function test_a_teacher_can_only_create_for_what_they_teach(): void
    {
        $this->withToken($this->otherTeacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody())
            ->assertStatus(422)->assertJsonStructure(['errors' => ['class_id']]);

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(['subject_id' => 999]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['subject_id']]);

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(['section_id' => 2]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['section_id']]);

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(['teacher_id' => self::OTHER_TEACHER_CODE]))
            ->assertStatus(403);
    }

    public function test_only_active_students_of_the_target_are_assigned(): void
    {
        $homeworkId = $this->createHomework();

        $assigned = $this->school()->table('hw_assignment_students')->where('assignment_id', $homeworkId)->pluck('student_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame([self::STUDENT_ROW, self::OTHER_STUDENT_ROW], $assigned);
    }

    public function test_due_date_can_be_in_school_time_and_is_returned_in_utc(): void
    {
        $date = now()->addDays(3)->toDateString();
        $homeworkId = $this->createHomework(['due_at' => null, 'due_date' => $date, 'due_minutes' => 600]);

        $this->assertSame("$date 10:00:00", $this->school()->table('hw_assignments')->where('id', $homeworkId)->value('due_at'));

        $this->withToken($this->teacherToken)->getJson("/api/v1/teacher/homework/$homeworkId")->assertOk()
            ->assertJsonPath('data.due_at', $date.'T04:00:00Z');
    }

    public function test_the_same_idempotency_key_returns_the_first_answer(): void
    {
        $headers = ['Idempotency-Key' => 'tap-1'];
        $first = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertCreated();
        $second = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertCreated();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $this->school()->table('hw_assignments')->count());

        $this->school()->table('hw_api_idempotency')->update(['response_code' => null]);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertStatus(409);
    }

    public function test_a_failed_create_frees_its_idempotency_key(): void
    {
        $headers = ['Idempotency-Key' => 'tap-2'];

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(['title' => '']), $headers)->assertStatus(422);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertCreated();
    }

    public function test_another_teachers_homework_looks_like_it_does_not_exist(): void
    {
        $homeworkId = $this->createHomework();
        $other = $this->withToken($this->otherTeacherToken);

        $other->getJson("/api/v1/teacher/homework/$homeworkId")->assertStatus(404);
        $other->getJson("/api/v1/teacher/homework/$homeworkId/submissions")->assertStatus(404);
        $other->patchJson("/api/v1/teacher/homework/$homeworkId", ['title' => 'Stolen'])->assertStatus(404);
        $other->deleteJson("/api/v1/teacher/homework/$homeworkId")->assertStatus(404);
        $other->postJson("/api/v1/teacher/homework/$homeworkId/submissions/".self::STUDENT_ROW.'/assessment', ['obtained_marks' => 1])->assertStatus(404);
        $other->getJson('/api/v1/teacher/homework')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_teacher_list_dashboard_and_filters(): void
    {
        $first = $this->createHomework(['title' => 'Algebra']);
        $this->createHomework(['title' => 'Geometry', 'type' => 'class_work']);
        $this->handIn($first)->assertCreated();

        $teacher = $this->withToken($this->teacherToken);

        $teacher->getJson('/api/v1/teacher/homework')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Geometry')
            ->assertJsonPath('data.0.type', 'class_work')
            ->assertJsonPath('data.0.teacher.id', (string) self::TEACHER_CODE)
            ->assertJsonPath('data.0.created_by.id', (string) self::TEACHER_USER)
            ->assertJsonPath('data.1.counts.assigned', 2)
            ->assertJsonPath('data.1.counts.submitted', 1)
            ->assertJsonPath('data.1.counts.under_review', 1)
            ->assertJsonPath('data.1.counts.pending', 1);

        $teacher->getJson('/api/v1/teacher/homework?search=Alg')->assertOk()->assertJsonCount(1, 'data');
        $teacher->getJson('/api/v1/teacher/homework?type=class_work')->assertOk()->assertJsonCount(1, 'data');
        $teacher->getJson('/api/v1/teacher/homework?status=draft')->assertOk()->assertJsonCount(0, 'data');
        $teacher->getJson('/api/v1/teacher/homework?status=wrong')->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
        $teacher->getJson('/api/v1/teacher/homework?teacher_id=7')->assertStatus(403);

        $teacher->getJson('/api/v1/teacher/homework/dashboard')->assertOk()
            ->assertJsonPath('data.totals.assigned', 2)
            ->assertJsonPath('data.totals.submitted', 1)
            ->assertJsonCount(2, 'data.recent');

        $teacher->getJson('/api/v1/teacher/homework/filter-options')->assertOk()
            ->assertJsonPath('data.sessions.0.id', (string) self::SESSION)
            ->assertJsonPath('data.types.1.id', 'class_work')
            ->assertJsonPath('data.teachers', []);
    }

    public function test_teaching_options_list_what_the_teacher_teaches(): void
    {
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/teaching-options')->assertOk()
            ->assertJsonPath('data.0.id', (string) self::CLASS_ID)
            ->assertJsonPath('data.0.sections', [['id' => (string) self::SECTION_ID, 'name' => 'A']])
            ->assertJsonPath('data.0.subjects', [['id' => (string) self::SUBJECT_ID, 'name' => 'Math']]);

        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/teacher/teaching-options')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_edit_changes_only_the_fields_sent_and_respects_the_locks(): void
    {
        $homeworkId = $this->createHomework();
        $teacher = $this->withToken($this->teacherToken);
        $url = "/api/v1/teacher/homework/$homeworkId";

        $teacher->patchJson($url, [])->assertStatus(422);

        $teacher->patchJson($url, ['title' => 'New title', 'allowed_formats' => ['text', 'document']])->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.description', 'Solve 1 to 12')
            ->assertJsonPath('data.allowed_formats', ['text', 'document'])
            ->assertJsonPath('data.locks.target', true);

        $this->assertSame(4, $this->school()->table('hw_assignment_formats')->where('assignment_id', $homeworkId)->count());

        $teacher->patchJson($url, ['section_id' => 2, 'title' => 'Not saved'])->assertStatus(422);
        $this->assertSame('New title', $this->school()->table('hw_assignments')->where('id', $homeworkId)->value('title'));

        $teacher->patchJson($url, ['due_at' => '2000-01-01T00:00:00Z'])->assertStatus(422);

        $this->handIn($homeworkId)->assertCreated();
        $teacher = $this->withToken($this->teacherToken);
        $teacher->postJson("$url/submissions/".self::STUDENT_ROW.'/assessment', ['obtained_marks' => 5])->assertOk();

        $teacher->patchJson($url, ['total_marks' => 20])->assertStatus(422)->assertJsonStructure(['errors' => ['total_marks']]);
        $teacher->patchJson($url, ['rubric' => [['name' => 'A', 'max' => 10]]])->assertStatus(422);
        $teacher->getJson($url)->assertJsonPath('data.locks.marks', true)->assertJsonPath('data.locks.delete', true);

        $this->school()->table('hw_assignments')->where('id', $homeworkId)->update(['status' => 'closed']);
        $teacher->patchJson($url, ['title' => 'Closed'])->assertStatus(422);
        $this->handIn($homeworkId, ['text' => 'late'], self::OTHER_STUDENT_ROW, $this->otherStudentToken)->assertStatus(422);
    }

    public function test_a_draft_from_the_web_can_be_published(): void
    {
        $homeworkId = $this->school()->table('hw_assignments')->insertGetId([
            'academic_session_id' => self::SESSION, 'class_id' => self::CLASS_ID, 'section_id' => self::SECTION_ID,
            'subject_id' => self::SUBJECT_ID, 'teacher_id' => self::TEACHER_CODE, 'assignment_type_id' => 1,
            'title' => 'Draft', 'assigned_date' => now()->toDateString(), 'due_at' => now()->addDays(2)->toDateTimeString(),
            'total_marks' => 10, 'priority' => 'medium', 'assessment_mode' => 'simple', 'allow_resubmission' => 0,
            'notify_on_publish' => 1, 'status' => 'draft', 'is_deleted' => 0, 'created_by' => self::TEACHER_USER,
        ]);

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertJsonCount(0, 'data');
        $this->handIn((string) $homeworkId)->assertStatus(404);

        $this->withToken($this->teacherToken)->patchJson("/api/v1/teacher/homework/$homeworkId", ['publish' => true])->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertJsonCount(1, 'data');
    }

    public function test_delete_is_soft_and_refused_after_a_submission(): void
    {
        $empty = $this->createHomework();
        $teacher = $this->withToken($this->teacherToken);

        $teacher->deleteJson("/api/v1/teacher/homework/$empty")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertSame(1, (int) $this->school()->table('hw_assignments')->where('id', $empty)->value('is_deleted'));
        $teacher->getJson("/api/v1/teacher/homework/$empty")->assertStatus(404);
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertJsonCount(0, 'data');

        $taken = $this->createHomework();
        $this->handIn($taken)->assertCreated();
        $this->withToken($this->teacherToken)->deleteJson("/api/v1/teacher/homework/$taken")->assertStatus(409);
    }

    public function test_submission_rules(): void
    {
        $homeworkId = $this->createHomework(['allowed_formats' => ['text']]);

        $this->handIn($homeworkId, [])->assertStatus(422)->assertJsonStructure(['errors' => ['submission']]);
        $this->handIn($homeworkId, ['link' => 'https://example.com'])->assertStatus(422)->assertJsonStructure(['errors' => ['link']]);
        $this->handIn($homeworkId, ['text' => str_repeat('x', 20001)])->assertStatus(422)->assertJsonStructure(['errors' => ['text']]);
        $this->handIn('999999', ['text' => 'x'])->assertStatus(404);

        $this->withToken($this->studentToken)->post('/api/v1/students/'.self::STUDENT_ROW."/homework/$homeworkId/submissions", [
            'files' => [UploadedFile::fake()->create('work.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['errors' => ['files']]);

        $this->assertSame(0, $this->school()->table('hw_submissions')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_inactive_student_cannot_hand_in(): void
    {
        $inactive = $this->tokenFor('01700000017', 'student', '9003');

        $this->withToken($inactive)->getJson('/api/v1/students/'.self::INACTIVE_STUDENT_ROW.'/homework')->assertOk();

        $this->school()->table('hw_assignment_students')->insert(['assignment_id' => $this->createHomework(), 'student_id' => self::INACTIVE_STUDENT_ROW, 'status' => 'pending', 'is_late' => 0, 'version_count' => 0]);
        $homeworkId = $this->school()->table('hw_assignments')->value('id');

        $this->handIn((string) $homeworkId, ['text' => 'x'], self::INACTIVE_STUDENT_ROW, $inactive)->assertStatus(403);
    }

    public function test_late_work_is_marked_late_or_refused_by_the_school_setting(): void
    {
        $homeworkId = $this->createHomework();
        $this->school()->table('hw_assignments')->where('id', $homeworkId)->update(['due_at' => '2020-01-01 10:00:00']);

        $list = $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework');
        $list->assertJsonPath('data.0.status', 'missing');

        $this->school()->table('hw_settings')->update(['allow_late_submission' => 0]);
        $this->handIn($homeworkId)->assertStatus(422);

        $this->school()->table('hw_settings')->update(['allow_late_submission' => 1]);
        $this->handIn($homeworkId)->assertCreated()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.submissions.0.is_late', true);

        $this->assertSame('late', $this->school()->table('hw_notification_events')->orderByDesc('id')->value('event_type'));
    }

    public function test_homework_not_open_yet_cannot_be_handed_in(): void
    {
        $homeworkId = $this->createHomework(['assigned_date' => now()->addDay()->toDateString()]);

        $this->handIn($homeworkId)->assertStatus(422)->assertJsonPath('message', 'This homework is not open yet');
    }

    public function test_files_are_stored_and_returned_with_a_url(): void
    {
        $teacher = $this->withToken($this->teacherToken);

        $homeworkId = $teacher->post('/api/v1/teacher/homework', $this->homeworkBody() + [
            'files' => [UploadedFile::fake()->create('sheet.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $detail = $teacher->getJson("/api/v1/teacher/homework/$homeworkId")->assertOk()
            ->assertJsonPath('data.attachments.0.name', 'sheet.pdf');

        $this->assertStringStartsWith('http://files.test/999999/homework/assignment/hwa_', $detail->json('data.attachments.0.url'));

        $this->withToken($this->studentToken)->post('/api/v1/students/'.self::STUDENT_ROW."/homework/$homeworkId/submissions", [
            'files' => [UploadedFile::fake()->create('page.jpg', 20, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.submissions.0.files.0.name', 'page.jpg')
            ->assertJsonPath('data.references.0.name', 'sheet.pdf');

        $this->assertSame('photo', $this->school()->table('hw_submission_files')->value('file_type'));
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $attachmentId = $detail->json('data.attachments.0.id');

        $this->withToken($this->teacherToken)->patchJson("/api/v1/teacher/homework/$homeworkId", ['remove_attachment_ids' => [$attachmentId]])->assertOk()
            ->assertJsonCount(0, 'data.attachments');
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_cloud_files_from_the_web_use_the_schools_storage_path(): void
    {
        $homeworkId = $this->createHomework();
        $this->school()->table('hw_assignment_attachments')->insert([
            'assignment_id' => $homeworkId, 'type' => 'attachment', 'file_name' => 'old.pdf', 'file_path' => '130407/homework/assignment/old.pdf', 'file_size' => 5,
        ]);

        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertOk()
            ->assertJsonPath('data.0.references.0.url', 'https://cloud.test/bucket/130407/homework/assignment/old.pdf')
            ->assertJsonPath('data.0.references.0.size_bytes', 5);
    }

    public function test_cached_reads_answer_304_when_nothing_changed(): void
    {
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/homework/summary';
        $etag = $this->withToken($this->studentToken)->getJson($url)->assertOk()->headers->get('ETag');

        $this->assertNotNull($etag);
        $this->withToken($this->studentToken)->getJson($url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_wrong_method_answers_405(): void
    {
        $this->withToken($this->teacherToken)->putJson('/api/v1/teacher/homework')->assertStatus(405);
    }

    public function test_activity_goes_to_the_monthly_file_without_any_content(): void
    {
        $homeworkId = $this->createHomework();
        $this->handIn($homeworkId, ['text' => 'SECRET-ANSWER'])->assertCreated();
        $this->handIn($homeworkId, ['text' => 'refused'])->assertStatus(422);

        // Views are not logged.
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework')->assertOk();
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/homework/summary')->assertOk();
        $this->withToken($this->studentToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/homework')->assertOk();
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/homework/dashboard')->assertOk();
        $this->withToken($this->teacherToken)->getJson("/api/v1/teacher/homework/$homeworkId/submissions")->assertOk();

        $entries = app(ActivityLogService::class)->read((int) now()->format('Y'), (int) now()->format('n'));
        $actions = array_column($entries, 'action');

        $this->assertSame(['create_homework', 'submit_homework'], $actions);
        $this->assertSame((string) $homeworkId, (string) $entries[0]['details']['homeworkId']);
        $this->assertSame((string) $homeworkId, (string) $entries[1]['details']['homeworkId']);
        $this->assertSame('student', $entries[1]['role']);
        $this->assertStringNotContainsString('SECRET-ANSWER', json_encode($entries));
    }

    public function test_every_change_is_logged_and_a_replayed_create_is_not(): void
    {
        $headers = ['Idempotency-Key' => 'tap-log'];
        $homeworkId = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertCreated()->json('data.id');
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/homework', $this->homeworkBody(), $headers)->assertCreated();

        $this->withToken($this->teacherToken)->patchJson("/api/v1/teacher/homework/$homeworkId", ['title' => 'New'])->assertOk();
        $this->handIn($homeworkId)->assertCreated();

        $student = "/api/v1/teacher/homework/$homeworkId/submissions/".self::STUDENT_ROW;
        $this->withToken($this->teacherToken)->postJson("$student/resubmission", ['reason' => 'Fix it'])->assertOk();
        $this->handIn($homeworkId)->assertCreated();
        $this->withToken($this->teacherToken)->postJson("$student/assessment", ['obtained_marks' => 5])->assertOk();

        $other = $this->createHomework();
        $this->withToken($this->teacherToken)->deleteJson("/api/v1/teacher/homework/$other")->assertOk();

        $entries = app(ActivityLogService::class)->read((int) now()->format('Y'), (int) now()->format('n'));

        $this->assertSame(
            ['create_homework', 'update_homework', 'submit_homework', 'request_resubmission', 'submit_homework', 'assess_homework', 'create_homework', 'delete_homework'],
            array_column($entries, 'action'),
        );

        $this->assertSame([
            'Hasan created homework: বীজগণিত ৪.৩',
            'Hasan updated homework: New',
            'Rahim submitted homework: New',
            'Hasan requested resubmission from Rahim on homework: New',
            'Rahim submitted homework: New',
            'Hasan assessed Rahim on homework: New',
            'Hasan created homework: বীজগণিত ৪.৩',
            'Hasan deleted homework: বীজগণিত ৪.৩',
        ], array_column($entries, 'message'));

        $this->assertStringNotContainsString('Fix it', json_encode($entries));
    }

    public function test_the_read_only_connection_still_cannot_write_to_a_school(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        app(\App\Services\SchoolDbService::class)->connect(self::TEST_SCHOOL_DB)->table('hw_settings')->update(['allow_late_submission' => 0]);
    }

    public function test_profile_gives_the_student_their_homework_id(): void
    {
        $this->school()->table('student_info')->where('student_registration_code', 9001)->update(['contact_number' => null]);

        $this->withToken($this->studentToken)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.placement.homework_student_id', (string) self::STUDENT_ROW);
    }
}
