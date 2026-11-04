<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AppUserLog;
use App\Services\SchoolDbService;
use Illuminate\Database\Connection;
use Tests\TestCase;

/**
 * Real teacher passwords are unknown, so the login itself uses a stand-in account
 * with the same $2a$ hash format. The account query is tested on the real school data.
 */
class TeacherLoginFlowTest extends TestCase
{
    private const USERNAME = 'test.teacher';
    private const OTHER_USERNAME = 'other.teacher';
    private const PASSWORD = 'correct-password';
    private const USER_ID = 42;
    private const TEACHER_CODE = 105001;
    private const TEACHER_NAME = 'Test Teacher';

    private function tenant(): Connection
    {
        return app(SchoolDbService::class)->connect(self::SCHOOL_DB);
    }

    private function useTeacherAccount(): void
    {
        $hash = str_replace('$2y$', '$2a$', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

        $this->partialMock(SchoolDbService::class, function ($mock) use ($hash) {
            $mock->shouldReceive('findTeacherAccount')->andReturnUsing(fn ($tenant, $username) => match ($username) {
                self::USERNAME => (object) ['id' => self::USER_ID, 'password' => $hash, 'teacher_code' => self::TEACHER_CODE, 'teacher_name' => self::TEACHER_NAME],
                self::OTHER_USERNAME => (object) ['id' => self::USER_ID + 1, 'password' => $hash, 'teacher_code' => self::TEACHER_CODE + 1, 'teacher_name' => 'Other Teacher'],
                default => null,
            });
            $mock->shouldReceive('findTeacher')->andReturn((object) ['teacher_code' => self::TEACHER_CODE, 'teacher_name' => self::TEACHER_NAME]);
        });
    }

    private function mobileFlow(string $role = 'teacher'): string
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => $role, 'reset_pin' => true])->assertOk();

        return $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => self::MOBILE, 'otp' => '123456', 'role' => $role])
            ->assertOk()
            ->json('data.flow_token');
    }

    private function verify(string $flow, string $password = self::PASSWORD, string $username = self::USERNAME)
    {
        return $this->postJson('/api/v1/auth/teacher/verify', [
            'flow_token' => $flow,
            'schl_id' => self::SCHOOL_ID,
            'username' => $username,
            'password' => $password,
        ]);
    }

    private function setPin(string $flow)
    {
        return $this->postJson('/api/v1/auth/set-pin', ['flow_token' => $flow, 'pin' => '1234', 'pin_confirmation' => '1234']);
    }

    public function test_teacher_full_flow_then_logout(): void
    {
        $this->useTeacherAccount();

        $verified = $this->verify($this->mobileFlow())->assertOk()->assertJsonPath('data.next', 'set_pin');
        $this->assertStringNotContainsString(self::PASSWORD, $verified->getContent());

        $login = $this->setPin($verified->json('data.flow_token'))->assertOk()
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonPath('data.user.teacher_name', self::TEACHER_NAME)
            ->assertJsonMissingPath('data.user.student_name');

        $user = AppUser::where('mobile_number', self::MOBILE)->where('role', 'teacher')->first();
        $this->assertSame((string) self::USER_ID, $user->verified_id);
        $this->assertSame('password', $user->verified_by);
        $this->assertSame(self::SCHOOL_ID, $user->schl_id);
        $this->assertNull($user->entered_student_id);

        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.teacher_name', self::TEACHER_NAME)
            ->assertJsonPath('data.teacher_code', self::TEACHER_CODE)
            ->assertJsonMissingPath('data.placement')
            ->assertJsonMissingPath('data.student_id');

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'teacher', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.teacher_name', self::TEACHER_NAME);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertSame(
            [AppUserLog::LOGIN, AppUserLog::LOGIN, AppUserLog::LOGOUT],
            AppUserLog::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_wrong_password_and_unknown_username_look_the_same_and_are_limited(): void
    {
        $this->useTeacherAccount();
        $flow = $this->mobileFlow();

        $this->verify($flow, 'wrong')->assertStatus(422)->assertJsonPath('message', 'The information you entered is not valid');
        $this->verify($flow, self::PASSWORD, 'nobody')->assertStatus(422)->assertJsonPath('message', 'The information you entered is not valid');

        for ($i = 1; $i < config('pathshala.login_limit.attempts'); $i++) {
            $this->verify($flow, 'wrong')->assertStatus(422);
        }

        $this->verify($flow, self::PASSWORD)->assertStatus(429);
    }

    public function test_others_school_logs_in_as_guest_without_a_username_or_password(): void
    {
        $guest = $this->postJson('/api/v1/auth/teacher/guest', [
            'flow_token' => $this->mobileFlow(),
            'school_name' => 'ABC High School',
        ])->assertOk()->assertJsonPath('data.next', 'set_pin');

        $login = $this->setPin($guest->json('data.flow_token'))->assertOk()
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonPath('data.user.is_verified', false)
            ->assertJsonPath('data.user.school_name', 'ABC High School')
            ->assertJsonPath('data.user.teacher_name', null);

        $user = AppUser::where('mobile_number', self::MOBILE)->where('role', 'teacher')->first();
        $this->assertNull($user->schl_id);
        $this->assertNull($user->verified_id);
        $this->assertNull($user->verified_by);

        $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.school_name', 'ABC High School')
            ->assertJsonPath('data.teacher_code', null);

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'teacher', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.is_verified', false);
    }

    public function test_teacher_guest_needs_a_school_name_and_a_teacher_flow(): void
    {
        $teacherFlow = $this->mobileFlow('teacher');

        $this->postJson('/api/v1/auth/teacher/guest', ['flow_token' => $teacherFlow])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['school_name']]);

        $this->postJson('/api/v1/auth/teacher/guest', ['flow_token' => $this->mobileFlow('student'), 'school_name' => 'A'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Session expired or invalid. Please start again.');
    }

    public function test_a_verified_number_cannot_be_taken_by_another_teacher_or_a_guest(): void
    {
        $this->useTeacherAccount();

        $token = $this->setPin($this->verify($this->mobileFlow())->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->verify($this->mobileFlow(), self::PASSWORD, self::OTHER_USERNAME)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This number is already linked to another account. Log in with your PIN or use a different number.');

        $this->postJson('/api/v1/auth/teacher/guest', ['flow_token' => $this->mobileFlow(), 'school_name' => 'A'])->assertStatus(409);

        $user = AppUser::where('mobile_number', self::MOBILE)->where('role', 'teacher')->first();
        $this->assertSame((string) self::USER_ID, $user->verified_id);
        $this->assertSame(1, AppUser::where('role', 'teacher')->count());
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_same_teacher_can_verify_again_to_reset_the_pin(): void
    {
        $this->useTeacherAccount();

        $oldToken = $this->setPin($this->verify($this->mobileFlow())->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');
        $newToken = $this->setPin($this->verify($this->mobileFlow())->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, AppUserLog::where('action', AppUserLog::PIN_RESET)->count());
    }

    public function test_a_flow_finished_before_another_login_cannot_replace_it(): void
    {
        $this->useTeacherAccount();

        $otherReady = $this->verify($this->mobileFlow(), self::PASSWORD, self::OTHER_USERNAME)->assertOk()->json('data.flow_token');

        $this->setPin($this->verify($this->mobileFlow())->assertOk()->json('data.flow_token'))->assertOk();

        $this->setPin($otherReady)->assertStatus(409);
        $this->assertSame((string) self::USER_ID, AppUser::where('role', 'teacher')->first()->verified_id);
    }

    public function test_a_teacher_guest_number_can_later_be_verified(): void
    {
        $this->useTeacherAccount();

        $guest = $this->postJson('/api/v1/auth/teacher/guest', ['flow_token' => $this->mobileFlow(), 'school_name' => 'A'])->assertOk();
        $this->setPin($guest->json('data.flow_token'))->assertOk();

        $this->setPin($this->verify($this->mobileFlow())->assertOk()->json('data.flow_token'))->assertOk()
            ->assertJsonPath('data.user.is_verified', true);
    }

    public function test_same_number_keeps_separate_student_and_teacher_users(): void
    {
        $this->mobileFlow('student');
        $this->mobileFlow('teacher');

        $this->assertSame(1, AppUser::where('role', 'student')->count());
        $this->assertSame(1, AppUser::where('role', 'teacher')->count());
    }

    public function test_flow_tokens_cannot_cross_roles(): void
    {
        $teacherFlow = $this->mobileFlow('teacher');

        $this->postJson('/api/v1/auth/student/check', [
            'flow_token' => $teacherFlow, 'schl_id' => self::SCHOOL_ID, 'class_code' => 1, 'student_id' => '1',
        ])->assertStatus(422)->assertJsonPath('message', 'Session expired or invalid. Please start again.');

        $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $teacherFlow, 'school_name' => 'A', 'class_name' => 'B', 'student_id' => '1',
        ])->assertStatus(422);

        $studentFlow = $this->mobileFlow('student');

        $this->verify($studentFlow)->assertStatus(422)->assertJsonPath('message', 'Session expired or invalid. Please start again.');
    }

    public function test_teacher_login_without_a_teacher_info_row_can_be_used(): void
    {
        $service = app(SchoolDbService::class);
        $tenant = $this->tenant();

        $login = $tenant->table('users as u')
            ->leftJoin('teacher_info as t', 't.teacher_code', '=', 'u.teacher_code')
            ->whereNull('t.teacher_code')
            ->where('u.user_type', 'Teacher')->where('u.activated', 1)->where('u.banned', 0)
            ->first(['u.id', 'u.username', 'u.user_full_name']);

        if (! $login) {
            $this->markTestSkipped('The school data has no such login.');
        }

        $account = $service->findTeacherAccount($tenant, $login->username);

        $this->assertSame($login->id, $account->id);
        $this->assertNull($account->teacher_code);
        $this->assertSame($login->user_full_name, $account->teacher_name);
    }

    public function test_only_active_teacher_logins_match_on_the_real_school_data(): void
    {
        $service = app(SchoolDbService::class);
        $tenant = $this->tenant();

        $teacher = $tenant->table('users as u')
            ->join('teacher_info as t', 't.teacher_code', '=', 'u.teacher_code')
            ->where('u.user_type', 'Teacher')->where('u.activated', 1)->where('u.banned', 0)->where('t.teacher_active_status', 1)
            ->first(['u.username', 't.teacher_code']);

        $this->assertSame($teacher->teacher_code, $service->findTeacherAccount($tenant, $teacher->username)->teacher_code);

        $other = $tenant->table('users')->where('user_type', '<>', 'Teacher')->value('username');
        $this->assertNull($service->findTeacherAccount($tenant, $other));

        $banned = $tenant->table('users')->where('banned', 1)->value('username');
        $this->assertNull($service->findTeacherAccount($tenant, $banned));

        $this->assertNull($service->findTeacherAccount($tenant, 'no-such-user'));
    }
}
