<?php

namespace Tests\Feature;

use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Services\ActivityLogService;
use App\Services\TokenService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Quiz writes run against pathshala_app_test_quiz, a copy of the real tables' structure
 * with made-up rows. The real school database is never written to.
 */
class QuizApiTest extends TestCase
{
    private const TEST_SCHOOL_DB = 'pathshala_app_test_quiz';
    private const TEST_SCHOOL_ID = 80;
    private const SECOND_SCHOOL_ID = 81;
    private const SESSION = 20;
    private const CLASS_ID = 8;
    private const SECTION_ID = 1;
    private const SUBJECT_ID = 143;
    private const TEACHER_USER = 500;
    private const TEACHER_CODE = 700;
    private const OTHER_TEACHER_USER = 501;
    private const STUDENT_ROW = 4723;
    private const OTHER_STUDENT_ROW = 4724;
    private const SECTION_B_ROW = 4726;
    private const INACTIVE_STUDENT_ROW = 4725;

    private const COPIED_TABLES = [
        'qz_attempt_answers', 'qz_attempts', 'qz_notification_events', 'qz_quiz_questions', 'qz_quiz_students', 'qz_quizzes', 'qz_settings',
        'qb_questions', 'qb_question_options', 'perf_grading_bands', 'hw_api_idempotency',
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

        $this->prepareSchool();

        $this->teacherToken = $this->tokenFor('01700000010', 'teacher', (string) self::TEACHER_USER);
        $this->otherTeacherToken = $this->tokenFor('01700000011', 'teacher', (string) self::OTHER_TEACHER_USER);
        $this->studentToken = $this->tokenFor('01700000012', 'student', '9001');
        $this->otherStudentToken = $this->tokenFor('01700000013', 'student', '9002');
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('school_information')->whereIn('schl_id', [self::TEST_SCHOOL_ID, self::SECOND_SCHOOL_ID])->delete();

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
            'schl_name' => 'Quiz Test School',
            'schl_url' => 'http://example.test',
            'schl_code' => 'qz_test',
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
        config(['database.connections.test_quiz_school' => ['database' => self::TEST_SCHOOL_DB] + config('database.connections.master')]);

        $connection = DB::connection('test_quiz_school');

        if ($connection->getDatabaseName() !== self::TEST_SCHOOL_DB) {
            throw new RuntimeException('Quiz tests must only write to the test school database.');
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
        $db->table('subject_info')->insert(['subject_code' => self::SUBJECT_ID, 'subject_name' => 'Math']);
        $db->table('teacher_info')->insert([
            ['teacher_code' => self::TEACHER_CODE, 'teacher_name' => 'Hasan', 'teacher_active_status' => 1],
            ['teacher_code' => 701, 'teacher_name' => 'Karim', 'teacher_active_status' => 1],
        ]);
        $db->table('users')->insert([
            ['id' => self::TEACHER_USER, 'username' => 'hasan', 'user_full_name' => 'Hasan', 'teacher_code' => self::TEACHER_CODE, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
            ['id' => self::OTHER_TEACHER_USER, 'username' => 'karim', 'user_full_name' => 'Karim', 'teacher_code' => 701, 'user_type' => 'Teacher', 'activated' => 1, 'banned' => 0, 'password' => 'x'],
        ]);
        $db->table('subject_teacher_info')->insert([
            'teacher_code' => self::TEACHER_CODE, 'subject_code' => self::SUBJECT_ID, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'session_code' => self::SESSION,
        ]);
        $db->table('student_info')->insert([
            ['student_registration_code' => 9001, 'student_name' => 'Rahim'],
            ['student_registration_code' => 9002, 'student_name' => 'Maria'],
            ['student_registration_code' => 9003, 'student_name' => 'Old'],
            ['student_registration_code' => 9004, 'student_name' => 'Nadia'],
        ]);
        $db->table('student_current_status_info')->insert([
            ['current_status_info_id' => self::STUDENT_ROW, 'student_registration_code' => 9001, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 12, 'active_status' => 1],
            ['current_status_info_id' => self::OTHER_STUDENT_ROW, 'student_registration_code' => 9002, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 7, 'active_status' => 1],
            ['current_status_info_id' => self::INACTIVE_STUDENT_ROW, 'student_registration_code' => 9003, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => self::SECTION_ID, 'roll_number' => 3, 'active_status' => 0],
            ['current_status_info_id' => self::SECTION_B_ROW, 'student_registration_code' => 9004, 'session_code' => self::SESSION, 'class_code' => self::CLASS_ID, 'section_code' => 2, 'roll_number' => 1, 'active_status' => 1],
        ]);
        $db->table('qz_settings')->insert(['default_max_attempts' => 1, 'not_accepted_policy' => 'exclude', 'allow_reopen' => 1, 'reminder_hours_before' => 24, 'auto_release' => 0]);
        $db->table('perf_grading_bands')->insert([
            ['min_percent' => 90, 'max_percent' => 100, 'label_en' => 'Excellent', 'label_bn' => 'চমৎকার', 'scope' => 'all', 'status' => 1],
            ['min_percent' => 70, 'max_percent' => 89.99, 'label_en' => 'Good', 'label_bn' => 'ভালো', 'scope' => 'all', 'status' => 1],
            ['min_percent' => 0, 'max_percent' => 69.99, 'label_en' => 'Poor', 'label_bn' => null, 'scope' => 'all', 'status' => 1],
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
            'school_name' => 'Quiz Test School',
            'pin' => 'x',
        ]);

        $token = app(TokenService::class)->issueAppToken($user, self::TEST_SCHOOL_DB);
        $user->update(['auth_token' => $token]);

        return $token;
    }

    private function quizBody(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'অধ্যায় ১ কুইজ',
            'subject_id' => (string) self::SUBJECT_ID,
            'class_id' => self::CLASS_ID.'-'.self::SECTION_ID,
            'instructions' => 'Answer all',
            'total_marks' => 4,
            'due_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => now()->addDays(2)->utc()->format('Y-m-d\TH:i:s\Z'),
            'mode' => 'in_app',
            'duration_minutes' => 15,
            'questions' => [
                ['text' => '2 + 2 = ?', 'options' => ['3', '4', '5'], 'correct' => 1, 'marks' => 2, 'explanation' => 'Simple sum'],
                ['text' => 'Primes?', 'options' => ['2', '4', '3', '6'], 'correct' => [0, 2], 'marks' => 2, 'negative_marks' => 0.5],
            ],
        ];
    }

    private function offlineBody(array $overrides = []): array
    {
        $body = $this->quizBody($overrides + ['mode' => 'offline']);
        unset($body['questions'], $body['duration_minutes']);

        return $body;
    }

    private function createQuiz(array $overrides = []): string
    {
        return $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody($overrides))->assertCreated()->json('data.id');
    }

    private function url(string $quizId, string $suffix = '', int $student = self::STUDENT_ROW): string
    {
        return "/api/v1/students/$student/quizzes/$quizId$suffix";
    }

    private function accept(string $quizId, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->studentToken)->postJson($this->url($quizId, '/accept'));
    }

    private function start(string $quizId): TestResponse
    {
        return $this->withToken($this->studentToken)->postJson($this->url($quizId, '/attempt'));
    }

    private function submit(string $quizId, array $answers): TestResponse
    {
        return $this->withToken($this->studentToken)->postJson($this->url($quizId, '/attempt/submit'), ['answers' => $answers]);
    }

    /**
     * Answers by question text, because question ids come from the attempt.
     */
    private function answersFor(array $attempt, array $byText): array
    {
        $answers = [];

        foreach ($attempt['questions'] as $question) {
            if (array_key_exists($question['text'], $byText)) {
                $answers[$question['id']] = $byText[$question['text']];
            }
        }

        return $answers;
    }

    private function roster(string $quizId, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->teacherToken)->getJson("/api/v1/teacher/quizzes/$quizId/roster");
    }

    private function saveMarks(string $quizId, array $marks): TestResponse
    {
        return $this->withToken($this->teacherToken)->putJson("/api/v1/teacher/quizzes/$quizId/marks", ['marks' => $marks]);
    }

    private function release(string $quizId): TestResponse
    {
        return $this->withToken($this->teacherToken)->postJson("/api/v1/teacher/quizzes/$quizId/release");
    }

    private function entry(TestResponse $roster, int $student): array
    {
        return collect($roster->json('data.entries'))->firstWhere('student_id', (string) $student);
    }

    private function guardianFor(string $studentId): array
    {
        $user = AppUser::create(['mobile_number' => '01700000020', 'role' => 'guardian', 'is_verified' => true, 'pin' => 'x']);
        $child = AppGuardianChild::create(['app_user_id' => $user->id, 'schl_id' => self::TEST_SCHOOL_ID, 'student_id' => $studentId]);

        $token = app(TokenService::class)->issueAppToken($user, null);
        $user->update(['auth_token' => $token]);

        return [$token, $child->id, $user->id];
    }

    public function test_tests_never_use_the_real_school_for_writes(): void
    {
        $this->assertSame(self::TEST_SCHOOL_DB, $this->school()->getDatabaseName());
        $this->assertNotSame(self::SCHOOL_DB, self::TEST_SCHOOL_DB);
    }

    public function test_full_cycle_create_accept_attempt_mark_release(): void
    {
        $quizId = $this->createQuiz();

        // Only active students of the section get it.
        $this->assertSame(
            [self::STUDENT_ROW, self::OTHER_STUDENT_ROW],
            $this->school()->table('qz_quiz_students')->where('quiz_id', $quizId)->orderBy('student_id')->pluck('student_id')->map(fn ($id) => (int) $id)->all(),
        );

        $this->assertSame('quiz_received', $this->school()->table('qz_notification_events')->where('quiz_id', $quizId)->value('event_type'));

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()
            ->assertJsonPath('data.acceptance', 'pending')
            ->assertJsonPath('data.attempt', null)
            ->assertJsonPath('data.attempts_left', 1)
            ->assertJsonPath('data.question_count', 2)
            ->assertJsonPath('data.class_label', 'Eight A')
            ->assertJsonPath('data.teacher', 'Hasan')
            ->assertJsonPath('data.result', null);

        $this->start($quizId)->assertStatus(422)->assertJsonPath('message', 'Accept the quiz first.');

        $this->accept($quizId)->assertOk()->assertJsonPath('data.acceptance', 'accepted');
        $this->accept($quizId)->assertOk();

        $attempt = $this->start($quizId)->assertOk()->assertJsonPath('data.attempt_no', 1)->json('data');
        $this->assertGreaterThan(800, $attempt['seconds_left']);

        // 2 + 2 right, primes right: 4 of 4.
        $answers = $this->answersFor($attempt, ['2 + 2 = ?' => 1, 'Primes?' => [0, 2]]);

        $this->submit($quizId, $answers)->assertOk()
            ->assertJsonPath('data.attempt', 'submitted')
            ->assertJsonPath('data.attempts_left', 0)
            ->assertJsonPath('data.result', null);

        $roster = $this->roster($quizId)->assertOk();
        $entry = $this->entry($roster, self::STUDENT_ROW);

        $this->assertSame(4, $entry['auto_score']);
        $this->assertNull($entry['obtained']);
        $this->assertNull($entry['remarks']);
        $this->assertTrue($entry['submitted']);

        $roster->assertJsonPath('data.quiz.accepted', 1)->assertJsonPath('data.quiz.pending', 1)->assertJsonPath('data.quiz.scored', 1)->assertJsonPath('data.quiz.released', false);

        $saved = $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 3, 'feedback' => 'ভালো করেছ।']])->assertOk();
        $entry = $this->entry($saved, self::STUDENT_ROW);

        $this->assertSame(3, $entry['obtained']);
        $this->assertSame('ভালো', $entry['remarks']);
        $this->assertSame(75, $entry['percent']);

        // The row keeps the English label, as the web saves it.
        $this->assertSame('Good', $this->school()->table('qz_quiz_students')->where('student_id', self::STUDENT_ROW)->value('remarks_label'));

        // Marks are not shown to the student before release.
        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.result', null);

        $this->release($quizId)->assertOk()->assertJsonPath('data.quiz.released', true)->assertJsonPath('data.quiz.status', 'released');

        $result = $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()
            ->assertJsonPath('data.result.obtained', 3)
            ->assertJsonPath('data.result.total', 4)
            ->assertJsonPath('data.result.percent', 75)
            ->assertJsonPath('data.result.remarks', 'ভালো')
            ->assertJsonPath('data.result.feedback', 'ভালো করেছ।')
            ->assertJsonPath('data.result.review.0.text', '2 + 2 = ?')
            ->assertJsonPath('data.result.review.0.correct', 1)
            ->assertJsonPath('data.result.review.0.chosen', 1)
            ->assertJsonPath('data.result.review.0.explanation', 'Simple sum')
            ->assertJsonPath('data.result.review.1.correct', [0, 2])
            ->assertJsonPath('data.result.review.1.chosen', [0, 2])
            ->json('data.result');

        $this->assertSame(2, $result['review'][0]['obtained']);
        $this->assertSame('result_released', $this->school()->table('qz_notification_events')->where('quiz_id', $quizId)->orderByDesc('id')->value('event_type'));
    }

    public function test_the_attempt_never_contains_the_answer_key(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();

        foreach ([$this->start($quizId), $this->withToken($this->studentToken)->getJson($this->url($quizId))] as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString('is_correct', $response->getContent());
            $this->assertStringNotContainsString('"correct"', $response->getContent());
            $this->assertStringNotContainsString('explanation', $response->getContent());
        }
    }

    public function test_the_data_is_stored_in_the_format_the_web_reads(): void
    {
        $quizId = $this->createQuiz();
        $db = $this->school();

        $question = $db->table('qz_quiz_questions')->where('quiz_id', $quizId)->orderBy('sort_order')->get();
        $snapshot = json_decode($question[0]->snapshot_json, true);

        $this->assertSame('mcq_single', $snapshot['type']);
        $this->assertSame('2 + 2 = ?', $snapshot['stem']);
        $this->assertSame([
            ['key' => 'a', 'text' => '3', 'is_correct' => 0],
            ['key' => 'b', 'text' => '4', 'is_correct' => 1],
            ['key' => 'c', 'text' => '5', 'is_correct' => 0],
        ], $snapshot['options']);
        $this->assertSame('mcq_multi', $question[1]->question_type);

        $bank = $db->table('qb_questions')->where('id', $question[0]->question_id)->first();
        $this->assertSame('private', $bank->visibility);
        $this->assertSame(self::TEACHER_USER, (int) $bank->created_by);
        $this->assertSame(['ক', 'খ', 'গ'], $db->table('qb_question_options')->where('question_id', $bank->id)->orderBy('sort_order')->pluck('label')->all());

        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1, 'Primes?' => [2, 0]]))->assertOk();

        $this->assertSame(['b', 'a,c'], $db->table('qz_attempt_answers')->orderBy('quiz_question_id')->pluck('selected_option_ids')->all());
        $this->assertSame('app', $db->table('qz_attempts')->value('via'));
        $this->assertSame('app', $db->table('qz_quiz_students')->where('student_id', self::STUDENT_ROW)->value('accepted_via'));
    }

    public function test_scoring_negative_marks_multi_answers_and_never_below_zero(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');

        // Partly right on a multi question is wrong, and costs its negative marks.
        $this->submit($quizId, $this->answersFor($attempt, ['Primes?' => [0]]))->assertOk();

        $this->assertSame(0, $this->entry($this->roster($quizId), self::STUDENT_ROW)['auto_score']);

        $row = $this->school()->table('qz_attempt_answers')->first();
        $this->assertSame(0, (int) $row->is_correct);
        $this->assertSame(-0.5, (float) $row->auto_marks);
    }

    public function test_the_best_attempt_counts(): void
    {
        $quizId = $this->createQuiz(['max_attempts' => 2]);
        $this->accept($quizId)->assertOk();

        $first = $this->start($quizId)->assertJsonPath('data.attempt_no', 1)->json('data');
        $this->submit($quizId, $this->answersFor($first, ['2 + 2 = ?' => 1, 'Primes?' => [0, 2]]))->assertOk()->assertJsonPath('data.attempts_left', 1);

        $second = $this->start($quizId)->assertJsonPath('data.attempt_no', 2)->json('data');
        $this->submit($quizId, $this->answersFor($second, ['2 + 2 = ?' => 0]))->assertOk()->assertJsonPath('data.attempts_left', 0);

        $this->start($quizId)->assertStatus(422)->assertJsonPath('message', 'All 2 attempt(s) are used.');

        $this->release($quizId)->assertOk();
        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()
            ->assertJsonPath('data.result.obtained', 4)
            ->assertJsonPath('data.result.remarks', 'চমৎকার')
            ->assertJsonPath('data.result.review.0.chosen', 1);
    }

    public function test_resuming_returns_the_same_attempt_and_shuffled_order(): void
    {
        $quizId = $this->createQuiz(['shuffle_questions' => true, 'shuffle_options' => true]);
        $this->accept($quizId)->assertOk();

        $first = $this->start($quizId)->assertOk()->json('data');
        $this->travel(2)->minutes();
        $second = $this->start($quizId)->assertOk()->json('data');

        $this->assertSame($first['ends_at'], $second['ends_at']);
        $this->assertSame($first['questions'], $second['questions']);
        $this->assertLessThan($first['seconds_left'], $second['seconds_left']);
        $this->assertSame(1, $this->school()->table('qz_attempts')->count());
    }

    public function test_answers_use_the_shuffled_option_order_shown_to_the_student(): void
    {
        $quizId = $this->createQuiz(['shuffle_options' => true]);
        $this->accept($quizId)->assertOk();

        $attempt = $this->start($quizId)->json('data');
        $single = collect($attempt['questions'])->firstWhere('text', '2 + 2 = ?');
        $multi = collect($attempt['questions'])->firstWhere('text', 'Primes?');

        $this->submit($quizId, [
            $single['id'] => array_search('4', $single['options'], true),
            $multi['id'] => [array_search('2', $multi['options'], true), array_search('3', $multi['options'], true)],
        ])->assertOk();

        $this->assertSame(4, $this->entry($this->roster($quizId), self::STUDENT_ROW)['auto_score']);
    }

    public function test_bad_answers_list_every_question_and_save_nothing(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $valid = $this->answersFor($attempt, ['2 + 2 = ?' => 1]);
        $multiId = array_key_first($this->answersFor($attempt, ['Primes?' => 0]));

        $response = $this->submit($quizId, $valid + [$multiId => [9], 999999 => 0])->assertStatus(422);

        $this->assertArrayHasKey('answers.999999', $response->json('errors'));
        $this->assertArrayHasKey('answers.'.$multiId, $response->json('errors'));
        $this->assertSame(0, $this->school()->table('qz_attempt_answers')->count());
        $this->assertSame('in_progress', $this->school()->table('qz_attempts')->value('status'));

        // A list is refused on a question with one right answer.
        $this->submit($quizId, [array_key_first($valid) => [1]])->assertStatus(422);

        $this->submit($quizId, $valid)->assertOk();
    }

    public function test_submitting_twice_or_before_starting(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();

        $this->submit($quizId, [])->assertStatus(422)->assertJsonPath('message', 'Start the quiz first.');

        $attempt = $this->start($quizId)->json('data');
        $answers = $this->answersFor($attempt, ['2 + 2 = ?' => 1]);

        $this->submit($quizId, $answers)->assertOk();
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 0]))->assertOk()->assertJsonPath('data.attempt', 'submitted');

        $this->assertSame('b', $this->school()->table('qz_attempt_answers')->value('selected_option_ids'));
    }

    public function test_a_submit_within_the_30_second_grace_is_accepted(): void
    {
        $quizId = $this->createQuiz(['duration_minutes' => 1]);
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');

        $this->travel(80)->seconds();

        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();
        $this->assertSame('submitted', $this->school()->table('qz_attempts')->value('status'));
    }

    public function test_a_late_submit_is_refused_and_the_attempt_is_closed_without_the_answers(): void
    {
        $quizId = $this->createQuiz(['duration_minutes' => 1]);
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');

        $this->travel(2)->minutes();

        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertStatus(422)
            ->assertJsonPath('message', 'The time for this attempt has ended. Your answers were not saved.');

        $row = $this->school()->table('qz_attempts')->first();
        $this->assertSame('auto_submitted', $row->status);
        $this->assertSame(0.0, (float) $row->final_score);
        $this->assertSame($row->expires_at, $row->submitted_at);
        $this->assertSame(0, $this->school()->table('qz_attempt_answers')->count());
    }

    public function test_a_timer_that_ran_out_closes_the_attempt_on_the_next_read(): void
    {
        $quizId = $this->createQuiz(['duration_minutes' => 1]);
        $this->accept($quizId)->assertOk();
        $this->start($quizId)->assertOk();

        $this->travel(2)->minutes();

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.attempt', 'submitted');
        $this->assertSame('auto_submitted', $this->school()->table('qz_attempts')->value('status'));
        $this->assertSame('auto_scored', $this->school()->table('qz_quiz_students')->where('student_id', self::STUDENT_ROW)->value('evaluation_status'));
    }

    public function test_the_timer_never_runs_past_the_time_the_quiz_closes(): void
    {
        $closes = now()->addMinutes(5);
        $quizId = $this->createQuiz([
            'due_at' => $closes->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $closes->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        $this->accept($quizId)->assertOk();

        $this->start($quizId)->assertOk()->assertJsonPath('data.ends_at', $closes->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_a_quiz_cannot_be_started_before_its_start_time_or_after_it_closes(): void
    {
        $quizId = $this->createQuiz(['start_at' => now()->addHours(2)->utc()->format('Y-m-d\TH:i:s\Z')]);
        $this->accept($quizId)->assertOk();

        $this->start($quizId)->assertStatus(422)->assertJsonPath('message', 'This quiz has not started yet.');

        $this->travel(3)->days();

        $this->start($quizId)->assertStatus(422)->assertJsonPath('message', 'This quiz is closed.');
    }

    public function test_acceptance_deadline_makes_pending_not_accepted(): void
    {
        $quizId = $this->createQuiz();

        $this->travel(25)->hours();

        $this->accept($quizId)->assertStatus(422)->assertJsonPath('message', 'The acceptance deadline has passed.');

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.acceptance', 'not_accepted');
        $this->assertSame('not_accepted', $this->school()->table('qz_quiz_students')->where('student_id', self::STUDENT_ROW)->value('status'));

        $this->roster($quizId)->assertOk()->assertJsonPath('data.quiz.not_accepted', 2)->assertJsonPath('data.quiz.acceptance_rate', 0);
    }

    public function test_a_reopened_student_has_their_own_deadline(): void
    {
        $quizId = $this->createQuiz();
        $this->travel(3)->days();

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertJsonPath('data.acceptance', 'not_accepted');

        $this->school()->table('qz_quiz_students')->where('student_id', self::STUDENT_ROW)->update([
            'status' => 'reopened',
            'reopen_deadline' => now()->addDay()->setTimezone('Asia/Dhaka')->toDateTimeString(),
        ]);

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.acceptance', 'pending');
        $this->accept($quizId)->assertOk();

        $this->start($quizId)->assertOk();
    }

    public function test_offline_quiz_cycle(): void
    {
        $quizId = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->offlineBody())->assertCreated()
            ->assertJsonPath('data.mode', 'offline')
            ->assertJsonPath('data.question_count', 0)
            ->json('data.id');

        $this->accept($quizId)->assertOk()->assertJsonPath('data.attempts_left', null);
        $this->start($quizId)->assertStatus(422)->assertJsonPath('message', 'This quiz is held in class.');

        $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 2]])->assertOk();
        $this->release($quizId)->assertOk();

        $response = $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()
            ->assertJsonPath('data.result.obtained', 2)
            ->assertJsonPath('data.result.percent', 50)
            ->assertJsonPath('data.result.remarks', 'Poor')
            ->assertJsonMissingPath('data.result.review');

        $this->assertNotNull($response->json('data.result'));
    }

    public function test_marks_are_all_checked_and_one_bad_row_saves_nothing(): void
    {
        $quizId = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->offlineBody())->assertCreated()->json('data.id');
        $this->accept($quizId)->assertOk();

        $response = $this->saveMarks($quizId, [
            ['student_id' => (string) self::STUDENT_ROW, 'obtained' => 3],
            ['student_id' => (string) self::OTHER_STUDENT_ROW, 'obtained' => 3],
            ['student_id' => '1', 'obtained' => 3],
        ])->assertStatus(422);

        $this->assertSame(['marks.1', 'marks.2'], array_keys($response->json('errors')));
        $this->assertSame(0, $this->school()->table('qz_quiz_students')->whereNotNull('obtained_marks')->count());

        $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 5]])->assertStatus(422);
        $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 3, 'feedback' => str_repeat('x', 2001)]])->assertStatus(422);
        $this->saveMarks($quizId, [])->assertStatus(422);
    }

    public function test_release_needs_something_scored_and_can_be_repeated(): void
    {
        $quizId = $this->createQuiz();

        $this->release($quizId)->assertStatus(422)->assertJsonPath('message', 'There are no results to release yet.');

        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();

        // An unconfirmed in-app score is released as it is.
        $this->release($quizId)->assertOk();
        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertJsonPath('data.result.obtained', 2);

        $this->release($quizId)->assertOk();
        $this->assertSame(1, $this->school()->table('qz_notification_events')->where('event_type', 'result_released')->count());
    }

    public function test_results_set_to_release_after_the_due_time_are_released_on_the_next_read(): void
    {
        $quizId = $this->createQuiz(['result_release' => 'auto_after_due']);
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertJsonPath('data.result', null);

        $this->travel(3)->days();

        $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.result.obtained', 2);
    }

    public function test_create_lists_every_wrong_field_and_saves_nothing(): void
    {
        $response = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', ['mode' => 'in_app', 'questions' => [['text' => '', 'options' => ['a'], 'correct' => 5, 'marks' => 0]]])->assertStatus(422);

        foreach (['title', 'class_id', 'subject', 'total_marks', 'due_at', 'duration_minutes', 'questions.0.text', 'questions.0.options', 'questions.0.correct', 'questions.0.marks'] as $field) {
            $this->assertArrayHasKey($field, $response->json('errors'));
        }

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(['total_marks' => 10]))->assertStatus(422)->assertJsonStructure(['errors' => ['questions']]);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(['due_at' => now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z')]))->assertStatus(422)->assertJsonStructure(['errors' => ['due_at']]);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(['ends_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z')]))->assertStatus(422)->assertJsonStructure(['errors' => ['ends_at']]);

        $this->assertSame(0, $this->school()->table('qz_quizzes')->count());
        $this->assertSame(0, $this->school()->table('qb_questions')->count());
    }

    public function test_a_teacher_can_only_create_for_what_they_teach(): void
    {
        $post = fn (array $overrides) => $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody($overrides));

        // Teaches section A only, so neither section B nor the whole class.
        $post(['class_id' => self::CLASS_ID.'-2'])->assertStatus(422)->assertJsonStructure(['errors' => ['class_id']]);
        $post(['class_id' => (string) self::CLASS_ID])->assertStatus(422)->assertJsonStructure(['errors' => ['class_id']]);
        $post(['subject_id' => '1'])->assertStatus(422)->assertJsonStructure(['errors' => ['subject']]);
        $post(['teacher_id' => 701])->assertStatus(403);

        $body = $this->quizBody();
        unset($body['subject_id']);
        $body['subject'] = 'Math';

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $body)->assertCreated()->assertJsonPath('data.subject', 'Math');
    }

    public function test_a_teacher_of_every_section_gets_the_whole_class_key(): void
    {
        $this->school()->table('subject_teacher_info')->update(['section_code' => 0]);

        $data = $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/quizzes')->assertOk()->json('data');

        $this->assertSame('Eight', $data['classes'][(string) self::CLASS_ID]);
        $this->assertSame('Eight A', $data['classes'][self::CLASS_ID.'-1']);
        $this->assertSame('Eight B', $data['classes'][self::CLASS_ID.'-2']);
        $this->assertSame(['Math'], $data['subjects']);
        $this->assertSame([['id' => (string) self::SUBJECT_ID, 'name' => 'Math']], $data['subjects_by_class'][(string) self::CLASS_ID]);

        $quizId = $this->createQuiz(['class_id' => (string) self::CLASS_ID]);

        $this->assertSame(3, $this->school()->table('qz_quiz_students')->where('quiz_id', $quizId)->count());
    }

    public function test_teacher_list_and_filters(): void
    {
        $inApp = $this->createQuiz(['title' => 'Algebra']);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->offlineBody(['title' => 'Geometry']))->assertCreated();

        $list = fn (string $query = '') => $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/quizzes'.$query)->assertOk();

        $list()->assertJsonCount(2, 'data.quizzes')
            ->assertJsonPath('data.quizzes.0.title', 'Geometry')
            ->assertJsonPath('data.quizzes.1.id', $inApp)
            ->assertJsonPath('data.quizzes.1.targeted', 2)
            ->assertJsonPath('data.quizzes.1.teacher.name', 'Hasan')
            ->assertJsonPath('data.quizzes.1.created_by.id', (string) self::TEACHER_USER);

        $list('?search=Alg')->assertJsonCount(1, 'data.quizzes');
        $list('?class_id='.self::CLASS_ID.'-1')->assertJsonCount(2, 'data.quizzes');
        $list('?class_id='.self::CLASS_ID.'-2')->assertJsonCount(0, 'data.quizzes');
        $list('?status=released')->assertJsonCount(0, 'data.quizzes');
        $list('?subject_id=1')->assertJsonCount(0, 'data.quizzes');

        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/quizzes?status=nope')->assertStatus(422);
        $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/quizzes?class_id=abc')->assertStatus(422);
    }

    public function test_teacher_statistics_cover_scored_students(): void
    {
        $quizId = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->offlineBody())->assertCreated()->json('data.id');

        $this->accept($quizId)->assertOk();
        $this->accept($quizId, $this->otherStudentToken)->assertStatus(403);
        $this->withToken($this->otherStudentToken)->postJson($this->url($quizId, '/accept', self::OTHER_STUDENT_ROW))->assertOk();

        $this->saveMarks($quizId, [
            ['student_id' => (string) self::STUDENT_ROW, 'obtained' => 4],
            ['student_id' => (string) self::OTHER_STUDENT_ROW, 'obtained' => 1],
        ])->assertOk()
            ->assertJsonPath('data.quiz.evaluated', 2)
            ->assertJsonPath('data.quiz.highest_percent', 100)
            ->assertJsonPath('data.quiz.lowest_percent', 25)
            ->assertJsonPath('data.quiz.average_percent', 62.5)
            ->assertJsonPath('data.quiz.acceptance_rate', 100);
    }

    public function test_the_zero_policy_counts_not_accepted_quizzes(): void
    {
        $this->school()->table('qz_settings')->update(['not_accepted_policy' => 'zero']);

        $quizId = $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->offlineBody())->assertCreated()->json('data.id');
        $this->accept($quizId)->assertOk();
        $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 4]])->assertOk();

        $this->travel(3)->days();

        $this->roster($quizId)->assertOk()->assertJsonPath('data.quiz.average_percent', 50)->assertJsonPath('data.quiz.lowest_percent', 0);

        $this->withToken($this->otherStudentToken)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/quizzes')->assertOk()
            ->assertJsonPath('data.summary.average_percent', 0);
    }

    public function test_student_report_summary_filters_and_order(): void
    {
        $offline = fn (string $title, int $days) => $this->withToken($this->teacherToken)
            ->postJson('/api/v1/teacher/quizzes', $this->offlineBody([
                'title' => $title,
                'due_at' => now()->addDays($days)->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => now()->addDays($days)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]))->assertCreated()->json('data.id');

        $marked = $offline('Marked', 1);
        $offline('Later', 10);
        $offline('Sooner', 5);

        $this->accept($marked)->assertOk();
        $this->saveMarks($marked, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 3, 'feedback' => 'Good']])->assertOk();
        $this->release($marked)->assertOk();

        $url = '/api/v1/students/'.self::STUDENT_ROW.'/quizzes';

        $data = $this->withToken($this->studentToken)->getJson($url.'?year='.now()->addDays(10)->year)->assertOk()->json('data');

        $this->assertSame((string) now()->addDays(10)->year, $data['year']);
        $this->assertContains((string) now()->addDays(10)->year, $data['years']);
        $this->assertSame(['Sooner', 'Later', 'Marked'], array_column($data['quizzes'], 'title'));

        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['accepted']);
        $this->assertSame(2, $data['summary']['pending']);
        $this->assertSame(0, $data['summary']['not_accepted']);
        $this->assertSame(4, $data['summary']['total_marks']);
        $this->assertSame(3, $data['summary']['total_obtained']);
        $this->assertEquals(75, $data['summary']['average_percent']);
        $this->assertSame('Marked', $data['summary']['best']['title']);

        $this->withToken($this->studentToken)->getJson($url.'?status=accepted&year='.now()->addDays(10)->year)->assertOk()->assertJsonCount(1, 'data.quizzes');
        $this->withToken($this->studentToken)->getJson($url.'?year=2001')->assertOk()->assertJsonCount(0, 'data.quizzes')->assertJsonPath('data.summary.average_percent', null);
        $this->withToken($this->studentToken)->getJson($url.'?month=13')->assertStatus(422);
        $this->withToken($this->studentToken)->getJson($url.'?status=nope')->assertStatus(422);
    }

    public function test_the_same_idempotency_key_changes_nothing(): void
    {
        $post = fn () => $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(), ['Idempotency-Key' => 'tap-1']);

        $first = $post()->assertCreated();
        $this->assertNull($first->headers->get('Idempotent-Replayed'));

        $second = $post()->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, $this->school()->table('qz_quizzes')->count());

        $quizId = $first->json('data.id');

        // Each caller has their own keys, so two students can use the same one.
        foreach ([[$this->studentToken, self::STUDENT_ROW], [$this->otherStudentToken, self::OTHER_STUDENT_ROW]] as [$token, $student]) {
            $accepted = $this->withToken($token)->postJson($this->url($quizId, '/accept', $student), [], ['Idempotency-Key' => 'accept-1'])->assertOk();
            $this->assertNull($accepted->headers->get('Idempotent-Replayed'));
        }

        $this->assertSame(2, $this->school()->table('qz_quiz_students')->where('status', 'accepted')->count());
    }

    public function test_a_failed_create_frees_its_key(): void
    {
        $headers = ['Idempotency-Key' => 'tap-2'];

        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(['title' => '']), $headers)->assertStatus(422);
        $this->withToken($this->teacherToken)->postJson('/api/v1/teacher/quizzes', $this->quizBody(), $headers)->assertCreated();
    }

    public function test_access_rules(): void
    {
        $quizId = $this->createQuiz();

        $this->withoutToken()->getJson('/api/v1/teacher/quizzes')->assertStatus(401);
        $this->withoutToken()->getJson('/api/v1/students/'.self::STUDENT_ROW.'/quizzes')->assertStatus(401);

        // Roles and other students.
        $this->withToken($this->studentToken)->getJson('/api/v1/teacher/quizzes')->assertStatus(403);
        $this->withToken($this->teacherToken)->getJson('/api/v1/students/'.self::STUDENT_ROW.'/quizzes')->assertStatus(403);
        $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/quizzes')->assertStatus(403);
        $this->withToken($this->studentToken)->postJson($this->url($quizId, '/accept', self::OTHER_STUDENT_ROW))->assertStatus(403);

        // Another teacher's quiz looks like it does not exist.
        $this->roster($quizId, $this->otherTeacherToken)->assertStatus(404);
        $this->withToken($this->otherTeacherToken)->putJson("/api/v1/teacher/quizzes/$quizId/marks", ['marks' => []])->assertStatus(404);
        $this->withToken($this->otherTeacherToken)->postJson("/api/v1/teacher/quizzes/$quizId/release")->assertStatus(404);
        $this->withToken($this->otherTeacherToken)->getJson('/api/v1/teacher/quizzes')->assertOk()->assertJsonCount(0, 'data.quizzes');

        // Not given to this student.
        $sectionB = $this->tokenFor('01700000014', 'student', '9004');
        $this->withToken($sectionB)->getJson($this->url($quizId, '', self::SECTION_B_ROW))->assertStatus(404);
    }

    public function test_an_inactive_student_can_read_but_not_act(): void
    {
        $quizId = $this->createQuiz();
        $this->school()->table('qz_quiz_students')->insert(['quiz_id' => $quizId, 'student_id' => self::INACTIVE_STUDENT_ROW, 'status' => 'pending', 'evaluation_status' => 'not_evaluated', 'is_released' => 0]);

        $token = $this->tokenFor('01700000015', 'student', '9003');

        $this->withToken($token)->getJson($this->url($quizId, '', self::INACTIVE_STUDENT_ROW))->assertOk();
        $this->withToken($token)->postJson($this->url($quizId, '/accept', self::INACTIVE_STUDENT_ROW))->assertStatus(403);
    }

    public function test_draft_cancelled_and_deleted_quizzes_are_hidden_from_students(): void
    {
        $quizId = $this->createQuiz();

        foreach ([['status' => 'draft'], ['status' => 'cancelled'], ['status' => 'published', 'is_deleted' => 1]] as $change) {
            $this->school()->table('qz_quizzes')->where('id', $quizId)->update($change);
            $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertStatus(404);
        }
    }

    public function test_a_guardian_sees_the_same_as_the_student_and_cannot_act(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();
        $this->release($quizId)->assertOk();

        [$token] = $this->guardianFor('9001');

        // The app gets the student id from the guardian's list of children.
        $this->withToken($token)->getJson('/api/v1/guardian/dashboard')->assertOk()->assertJsonPath('data.child.homework_student_id', (string) self::STUDENT_ROW);

        $detail = $this->withToken($token)->getJson($this->url($quizId))->assertOk()
            ->assertJsonPath('data.result.obtained', 2);

        $this->assertSame($this->withToken($this->studentToken)->getJson($this->url($quizId))->json('data'), $detail->json('data'));

        $year = now()->setTimezone('Asia/Dhaka')->addDay()->year;
        $report = $this->withToken($token)->getJson('/api/v1/students/'.self::STUDENT_ROW."/quizzes?year=$year")->assertOk()->assertJsonPath('data.summary.total', 1);
        $this->assertSame(
            $this->withToken($this->studentToken)->getJson('/api/v1/students/'.self::STUDENT_ROW."/quizzes?year=$year")->json('data'),
            $report->json('data'),
        );

        $this->withToken($token)->postJson($this->url($quizId, '/accept'))->assertStatus(403);
        $this->withToken($token)->postJson($this->url($quizId, '/attempt'))->assertStatus(403);
        $this->withToken($token)->postJson($this->url($quizId, '/attempt/submit'), ['answers' => []])->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/teacher/quizzes')->assertStatus(403);
    }

    public function test_a_guardian_cannot_see_another_child_or_a_child_they_removed(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();

        [$token, $childId] = $this->guardianFor('9001');

        $this->withToken($token)->getJson($this->url($quizId))->assertOk()->assertJsonPath('data.result', null);
        $this->withToken($token)->getJson('/api/v1/students/'.self::OTHER_STUDENT_ROW.'/quizzes')->assertStatus(403);
        $this->withToken($token)->getJson($this->url($quizId, '', self::OTHER_STUDENT_ROW))->assertStatus(403);

        AppGuardianChild::where('id', $childId)->delete();

        $this->withToken($token)->getJson($this->url($quizId))->assertStatus(403);
    }

    public function test_two_children_with_the_same_student_id_are_told_apart_with_child_id(): void
    {
        $quizId = $this->createQuiz();

        DB::connection('master')->table('school_information')->insert([
            'schl_id' => self::SECOND_SCHOOL_ID, 'schl_name' => 'Second School', 'schl_url' => 'http://example.test', 'schl_code' => 'qz_second',
            'api_code' => 'secret-api-code', 'school_secret' => 'secret', 'db_name' => self::TEST_SCHOOL_DB,
        ]);

        [$token, $childId, $userId] = $this->guardianFor('9001');
        AppGuardianChild::create(['app_user_id' => $userId, 'schl_id' => self::SECOND_SCHOOL_ID, 'student_id' => '9001']);

        $this->withToken($token)->getJson($this->url($quizId))->assertStatus(409)->assertJsonCount(2, 'data.child_ids');
        $this->withToken($token)->getJson($this->url($quizId, "?child_id=$childId"))->assertOk();
        $this->withToken($token)->getJson($this->url($quizId, '?child_id=999999'))->assertStatus(403);
    }

    public function test_the_quiz_report_answers_304_when_nothing_changed(): void
    {
        $this->createQuiz();
        [$guardian] = $this->guardianFor('9001');
        $url = '/api/v1/students/'.self::STUDENT_ROW.'/quizzes';

        foreach ([$this->studentToken, $guardian] as $token) {
            $etag = $this->withToken($token)->getJson($url)->assertOk()->headers->get('ETag');

            $this->assertNotNull($etag);
            $this->withToken($token)->getJson($url, ['If-None-Match' => $etag])->assertStatus(304);
        }
    }

    public function test_the_attempt_and_the_roster_follow_the_contract(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();

        $attempt = $this->start($quizId)->assertOk()->assertJsonPath('data.quiz_id', $quizId)->json('data');

        $this->assertSame(['id', 'text', 'options', 'marks', 'type', 'negative_marks', 'selected'], array_keys($attempt['questions'][0]));
        $this->assertSame(2, $attempt['questions'][0]['marks']);

        $this->school()->table('student_current_status_info')->where('current_status_info_id', self::OTHER_STUDENT_ROW)->update(['section_code' => 2, 'roll_number' => 1]);

        $this->assertSame(
            [(string) self::OTHER_STUDENT_ROW, (string) self::STUDENT_ROW],
            array_column($this->roster($quizId)->json('data.entries'), 'student_id'),
        );
    }

    public function test_a_school_without_the_quiz_tables_answers_503(): void
    {
        $quizId = $this->createQuiz();
        $db = $this->school();

        $db->statement('RENAME TABLE qz_quizzes TO qz_quizzes_hidden');

        try {
            $this->withToken($this->teacherToken)->getJson('/api/v1/teacher/quizzes')->assertStatus(503);
            $this->withToken($this->studentToken)->getJson($this->url($quizId))->assertStatus(503);
        } finally {
            $db->statement('RENAME TABLE qz_quizzes_hidden TO qz_quizzes');
        }
    }

    public function test_wrong_method_answers_405(): void
    {
        $quizId = $this->createQuiz();

        $this->withToken($this->teacherToken)->getJson("/api/v1/teacher/quizzes/$quizId/marks")->assertStatus(405);
    }

    public function test_changes_are_logged_without_answers_or_marks(): void
    {
        $quizId = $this->createQuiz();
        $this->accept($quizId)->assertOk();
        $attempt = $this->start($quizId)->json('data');
        $this->submit($quizId, $this->answersFor($attempt, ['2 + 2 = ?' => 1]))->assertOk();
        $this->saveMarks($quizId, [['student_id' => (string) self::STUDENT_ROW, 'obtained' => 3, 'feedback' => 'secret feedback']])->assertOk();
        $this->release($quizId)->assertOk();

        // Reads are not logged.
        $this->roster($quizId)->assertOk();

        $entries = app(ActivityLogService::class)->read((int) now()->year, (int) now()->month);

        $this->assertSame(
            ['create_quiz', 'accept_quiz', 'start_quiz_attempt', 'submit_quiz', 'save_quiz_marks', 'release_quiz'],
            array_column($entries, 'action'),
        );

        $text = json_encode($entries, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secret feedback', $text);
        $this->assertStringNotContainsString(self::TEST_SCHOOL_DB, $text);
        $this->assertStringNotContainsString('"obtained"', $text);
    }
}
