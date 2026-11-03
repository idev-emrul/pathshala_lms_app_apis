<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Notices are read from and written to pathshala_app_test_school, a copy of the real tables' structure
 * with made-up rows. The real school database is never written to.
 */
class NoticeApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_school';
    private const TEST_SCHOOL_ID = 81;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const OTHER_CLASS_ID = 9;
    private const SECTION_A = 1;
    private const SECTION_B = 2;
    private const SCIENCE = 2;
    private const HUMANITIES = 1;
    private const TEACHER_USER = 900;
    private const TEACHER_CODE = 1100;
    private const STAFF_USER = 901;
    private const STAFF_CODE = 1101;

    private const COPIED_TABLES = [
        'app_notice', 'app_notice_read', 'notice', 'student_info', 'student_current_status_info', 'session_info',
        'class_info', 'section_info', 'group_info', 'class_teacher_info', 'subject_teacher_info', 'teacher_info',
        'users', 'institute_info',
    ];

    private static bool $schoolCreated = false;

    private string $teacherToken;
    private string $staffToken;
    private string $studentToken;
    private string $otherClassStudentToken;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000050', 'teacher', (string) self::TEACHER_USER);
        $this->staffToken = $this->tokenFor('01700000051', 'teacher', (string) self::STAFF_USER);
        $this->studentToken = $this->tokenFor('01700000052', 'student', '9401');
        $this->otherClassStudentToken = $this->tokenFor('01700000053', 'student', '9402');
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
        $this->getJson('/api/v1/notices')->assertStatus(401);
        $this->postJson('/api/v1/notices', $this->noticeBody())->assertStatus(401);
    }

    public function test_everyone_sees_the_school_websites_public_notices(): void
    {
        $response = $this->withToken($this->otherClassStudentToken)->getJson('/api/v1/notices')->assertOk();

        // Only the published public one: not the future one, not the internal one.
        $this->assertSame(['web-1'], array_column($response->json('data'), 'id'));

        $response->assertJsonPath('data.0', [
            'id' => 'web-1',
            'title' => 'Eid holiday',
            'body' => "School is closed.\nSee you soon.",
            'category' => 'general',
            'category_label' => null,
            'priority' => 'normal',
            'published_at' => '2026-06-01T04:00:00Z',
            'expires_at' => null,
            'is_read' => false,
            'attachments' => [['name' => 'notice-1.pdf', 'url' => 'https://cloud.test/bucket/999999/site/documents/1.pdf', 'size_bytes' => null]],
            'student_id' => null,
            'student_name_bn' => null,
            'student_name_en' => null,
        ]);

        $this->withToken($this->teacherToken)->getJson('/api/v1/notices')->assertOk()->assertJsonPath('data.0.id', 'web-1');
    }

    public function test_a_notice_reaches_only_its_audience(): void
    {
        $id = $this->publish()->json('data.id');

        $this->assertSame([$id, 'web-1'], array_column($this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data'), 'id'));
        $this->assertSame(['web-1'], array_column($this->withToken($this->otherClassStudentToken)->getJson('/api/v1/notices')->json('data'), 'id'));
        // A notice for students does not reach teachers.
        $this->assertSame(['web-1'], array_column($this->withToken($this->teacherToken)->getJson('/api/v1/notices')->json('data'), 'id'));
    }

    public function test_filters_combine_and_a_teachers_notice_reaches_the_teachers_of_its_class(): void
    {
        $science = $this->publish(['audience_groups' => [self::SCIENCE], 'title' => 'Science fair'])->json('data.id');
        $this->publish(['audience_groups' => [self::HUMANITIES], 'title' => 'Debate']);
        $this->publish(['audience_sections' => [self::SECTION_B], 'title' => 'Section B only']);
        $forTeachers = $this->publish(['audience_roles' => ['teachers'], 'title' => 'Staff meeting'])->json('data.id');

        // The student is in Eight, section A, Science.
        $this->assertSame([$science, 'web-1'], array_column($this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data'), 'id'));
        $this->assertSame([$forTeachers, 'web-1'], array_column($this->withToken($this->teacherToken)->getJson('/api/v1/notices')->json('data'), 'id'));
        // Staff are not teachers, and teach no class.
        $this->assertSame(['web-1'], array_column($this->withToken($this->staffToken)->getJson('/api/v1/notices')->json('data'), 'id'));
    }

    public function test_a_published_notice_has_the_contract_shape(): void
    {
        $this->publish(['category' => 'custom', 'category_label' => 'Parents day', 'priority' => 'urgent', 'expires_on' => '2099-12-31']);

        $item = $this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data.0');

        $this->assertSame('Class Eight exam', $item['title']);
        $this->assertSame('Exams start on 1 November.', $item['body']);
        $this->assertSame('custom', $item['category']);
        $this->assertSame('Parents day', $item['category_label']);
        $this->assertSame('urgent', $item['priority']);
        $this->assertSame('2099-12-31T17:59:59Z', $item['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['published_at']);
        $this->assertFalse($item['is_read']);
    }

    public function test_a_future_notice_is_hidden_and_an_expired_one_stays(): void
    {
        $this->publish(['publish_on' => '2099-01-01', 'title' => 'Later']);

        $this->school()->table('app_notice')->insert([
            'category' => 'general', 'priority' => 'normal', 'title' => 'Old', 'body' => 'Old news', 'audience_roles' => 'students',
            'published_at' => '2026-01-01 09:00:00', 'expires_at' => '2026-01-31 23:59:59', 'attachments' => '[]', 'request_data' => '{}',
            'sender_type' => 'teacher', 'sender_id' => self::TEACHER_CODE, 'status' => 1, 'created_at' => '2026-01-01 09:00:00',
        ]);

        $titles = array_column($this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data'), 'title');

        $this->assertNotContains('Later', $titles);
        $this->assertContains('Old', $titles);
    }

    public function test_the_same_idempotency_key_never_publishes_twice(): void
    {
        $first = $this->publish([], 'notice-key-1')->assertStatus(201)->json('data.id');

        $this->publish(['title' => 'Changed'], 'notice-key-1')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first);

        $this->assertSame(1, $this->school()->table('app_notice')->count());
    }

    public function test_every_wrong_field_is_listed(): void
    {
        $this->withToken($this->teacherToken)
            ->post('/api/v1/notices', ['category' => 'nope', 'audience_classes' => [999], 'expires_on' => '2020-01-01'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category', 'priority', 'title', 'body', 'audience_roles', 'expires_on']]);

        $this->publish(['category' => 'custom'])->assertStatus(422)->assertJsonStructure(['errors' => ['category_label']]);
        $this->publish(['audience_classes' => [999]])->assertStatus(422)->assertJsonStructure(['errors' => ['audience_classes']]);
    }

    public function test_lists_can_be_sent_as_one_comma_separated_value(): void
    {
        $id = $this->publish(['audience_roles' => 'students,guardians', 'audience_classes' => (string) self::CLASS_ID])->assertStatus(201)->json('data.id');

        $this->assertSame('students,guardians', $this->school()->table('app_notice')->where('id', $id)->value('audience_roles'));
    }

    public function test_a_teacher_can_only_send_to_their_own_classes(): void
    {
        $this->publish(['audience_classes' => [self::OTHER_CLASS_ID]])->assertStatus(403);
        $this->publish(['audience_classes' => []])->assertStatus(403);

        $this->assertSame(0, $this->school()->table('app_notice')->count());
    }

    public function test_students_cannot_publish_or_see_the_audience_options(): void
    {
        $this->withToken($this->studentToken)->post('/api/v1/notices', $this->noticeBody(), ['Accept' => 'application/json'])->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/notices/audience-options')->assertStatus(403);
    }

    public function test_the_audience_options_are_the_teachers_own_classes(): void
    {
        $this->publish(['category' => 'custom', 'category_label' => 'Parents day']);

        $this->withToken($this->teacherToken)
            ->getJson('/api/v1/notices/audience-options')
            ->assertOk()
            ->assertJsonPath('data.classes', [['id' => '8', 'name' => 'Eight', 'sections' => [['id' => '1', 'name' => 'A']]]])
            ->assertJsonPath('data.groups', [['id' => '1', 'name' => 'Humanities'], ['id' => '2', 'name' => 'Science']])
            ->assertJsonPath('data.custom_categories', ['Parents day']);

        $this->withToken($this->staffToken)->getJson('/api/v1/notices/audience-options')->assertOk()->assertJsonPath('data.classes', []);
    }

    public function test_a_read_mark_is_per_reader_and_a_repeat_is_harmless(): void
    {
        $id = $this->publish(['audience_roles' => ['students', 'teachers']])->json('data.id');

        foreach ([$id, 'web-1', $id] as $notice) {
            $this->withToken($this->studentToken)->postJson("/api/v1/notices/$notice/read")->assertOk();
        }

        $read = collect($this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data'))->pluck('is_read', 'id')->all();
        $this->assertSame([$id => true, 'web-1' => true], $read);

        $teacherRead = collect($this->withToken($this->teacherToken)->getJson('/api/v1/notices')->json('data'))->pluck('is_read', 'id')->all();
        $this->assertSame([$id => false, 'web-1' => false], $teacherRead);

        $this->assertSame(2, $this->school()->table('app_notice_read')->count());
    }

    public function test_an_unknown_notice_cannot_be_marked_read(): void
    {
        $this->withToken($this->studentToken)->postJson('/api/v1/notices/9999/read')->assertStatus(404);
        // The internal website notice is not public.
        $this->withToken($this->studentToken)->postJson('/api/v1/notices/web-3/read')->assertStatus(404);
    }

    public function test_attachments_are_stored_and_listed_with_a_url(): void
    {
        $this->publish(['files' => [UploadedFile::fake()->create('routine.pdf', 120, 'application/pdf')]])->assertStatus(201);

        $attachment = $this->withToken($this->studentToken)->getJson('/api/v1/notices')->json('data.0.attachments.0');

        $this->assertSame('routine.pdf', $attachment['name']);
        $this->assertStringStartsWith('http://files.test/999999/app/notices/ntc_'.self::TEACHER_CODE.'_', $attachment['url']);
        $this->assertGreaterThan(0, $attachment['size_bytes']);
        Storage::disk('local')->assertExists(substr($attachment['url'], strlen('http://files.test/')));

        $this->publish(['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertStatus(422)->assertJsonStructure(['errors' => ['files.0']]);
    }

    private function publish(array $overrides = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $headers = ['Accept' => 'application/json'] + ($key ? ['Idempotency-Key' => $key] : []);

        return $this->withToken($this->teacherToken)->post('/api/v1/notices', $this->noticeBody($overrides), $headers);
    }

    private function noticeBody(array $overrides = []): array
    {
        return $overrides + [
            'category' => 'examination',
            'priority' => 'high',
            'title' => 'Class Eight exam',
            'body' => 'Exams start on 1 November.',
            'audience_roles' => ['students'],
            'audience_classes' => [self::CLASS_ID],
        ];
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
            'schl_name' => 'Notice Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'notice_test',
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
            throw new RuntimeException('Notice tests must only use the test school database.');
        }

        // Old tables have zero-date defaults, which strict mode refuses when seeding.
        $connection->statement("SET SESSION sql_mode = ''");

        return $connection;
    }

    private function seedSchool(): void
    {
        $db = $this->school();

        $db->table('institute_info')->insert(['institute_code' => 1, 'institute_EIIN' => '999999', 'institute_storage_path' => 'https://cloud.test/bucket/']);
        $db->table('session_info')->insert(['session_code' => self::SESSION, 'session_name' => '2026', 'running_session' => 1]);
        $db->table('class_info')->insert([
            ['class_code' => self::CLASS_ID, 'class_name' => 'Eight', 'class_activation_status' => 1, 'class_position' => 1],
            ['class_code' => self::OTHER_CLASS_ID, 'class_name' => 'Nine', 'class_activation_status' => 1, 'class_position' => 2],
        ]);
        $db->table('section_info')->insert([
            ['section_code' => self::SECTION_A, 'section_name' => 'A', 'section_activation_status' => 1, 'sec_position' => 1],
            ['section_code' => self::SECTION_B, 'section_name' => 'B', 'section_activation_status' => 1, 'sec_position' => 2],
        ]);
        $db->table('group_info')->insert([
            ['group_code' => self::HUMANITIES, 'group_name' => 'Humanities', 'group_active_status' => 1],
            ['group_code' => self::SCIENCE, 'group_name' => 'Science', 'group_active_status' => 1],
        ]);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1, 'employee_type' => 1],
            ['teacher_code' => self::STAFF_CODE, 'teacher_name' => 'Rafiq', 'teacher_active_status' => 1, 'employee_type' => 2],
        ]);
        $db->table('users')->insert([
            ['id' => self::TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::STAFF_USER, 'username' => 'rafiq', 'user_full_name' => 'Rafiq', 'teacher_code' => self::STAFF_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
        ]);
        $db->table('class_teacher_info')->insert([
            'teacher_code' => self::TEACHER_CODE, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID,
            'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'master_teacher' => '1',
        ]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9401, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9402, 'student_name' => 'Maria'],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => 8001, 'student_registration_code' => 9401, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => self::SCIENCE, 'shift_code' => 0, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => 8002, 'student_registration_code' => 9402, 'session_code' => self::SESSION, 'class_code' => self::OTHER_CLASS_ID, 'section_code' => self::SECTION_A, 'group_code' => 0, 'shift_code' => 0, 'roll_number' => 3, 'active_status' => 1],
        ]);

        // The school website's notices: public and published, not yet published, and internal only.
        $web = fn (int $id, string $title, string $at, int $show, string $attachment = '') => [
            'id' => $id, 'type' => 1, 'title_english' => $title, 'title_bangla' => '', 'des_english' => '<p>School is closed.</p>See you soon.',
            'des_bangla' => '', 'attachment' => $attachment, 'notice_date' => substr($at, 0, 10), 'notice_publish_date_time' => $at, 'showDocument' => $show,
        ];
        $db->table('notice')->insert([
            $web(1, 'Eid holiday', '2026-06-01 10:00:00', 1, '.pdf'),
            $web(2, 'Next year', '2099-01-01 10:00:00', 1),
            $web(3, 'Office only', '2026-06-01 10:00:00', 5),
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
            'school_name' => 'Notice Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }
}
