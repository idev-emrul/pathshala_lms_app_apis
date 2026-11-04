<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AppUserLog;
use App\Services\SchoolDbService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Real passwords are unknown, so the login itself uses stand-in accounts with the same
 * $2a$ hash format. The account query is tested on the real school data.
 */
class AdminStaffLoginFlowTest extends TestCase
{
    private const USERNAME = 'test.account';
    private const OTHER_USERNAME = 'other.account';
    private const PASSWORD = 'correct-password';
    private const USER_ID = 42;
    private const NAME = 'Test Account';
    private const INVALID = 'The information you entered is not valid';
    private const FLOW_INVALID = 'Session expired or invalid. Please start again.';

    private function useAccounts(): void
    {
        $hash = str_replace('$2y$', '$2a$', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

        $this->partialMock(SchoolDbService::class, function ($mock) use ($hash) {
            $mock->shouldReceive('findAdminStaffAccount')->andReturnUsing(fn ($tenant, $username) => match ($username) {
                self::USERNAME => (object) ['id' => self::USER_ID, 'password' => $hash, 'user_full_name' => self::NAME],
                self::OTHER_USERNAME => (object) ['id' => self::USER_ID + 1, 'password' => $hash, 'user_full_name' => 'Other Account'],
                default => null,
            });
            $mock->shouldReceive('findAdminStaffName')->andReturn(self::NAME);
        });
    }

    private function mobileFlow(string $role): string
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => $role, 'reset_pin' => true])->assertOk();

        return $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => self::MOBILE, 'otp' => '123456', 'role' => $role])
            ->assertOk()
            ->json('data.flow_token');
    }

    private function verify(string $role, string $flow, string $password = self::PASSWORD, string $username = self::USERNAME)
    {
        return $this->postJson("/api/v1/auth/{$role}/verify", [
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

    #[DataProvider('roles')]
    public function test_full_flow_then_logout(string $role): void
    {
        $this->useAccounts();

        $verified = $this->verify($role, $this->mobileFlow($role))->assertOk()->assertJsonPath('data.next', 'set_pin');
        $this->assertStringNotContainsString(self::PASSWORD, $verified->getContent());

        $login = $this->setPin($verified->json('data.flow_token'))->assertOk()
            ->assertJsonPath('data.role', AppUser::appRoleName($role))
            ->assertJsonPath('data.user.role', AppUser::appRoleName($role))
            ->assertJsonPath('data.name', self::NAME)
            ->assertJsonPath('data.school_id', (string) self::SCHOOL_ID)
            ->assertJsonPath('data.detail', null)
            ->assertJsonPath('data.permissions', config("pathshala.permissions.$role"))
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonPath('data.user.name', self::NAME)
            ->assertJsonMissingPath('data.user.student_name')
            ->assertJsonMissingPath('data.user.teacher_name');

        $user = AppUser::where('mobile_number', self::MOBILE)->where('role', $role)->first();
        $this->assertSame((string) self::USER_ID, $user->verified_id);
        $this->assertSame('password', $user->verified_by);
        $this->assertSame(self::SCHOOL_ID, $user->schl_id);
        $this->assertNull($user->entered_student_id);

        $token = $login->json('data.token');

        // users.id can equal a student ID, so the student fields must never be filled from it.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.name', self::NAME)
            ->assertJsonMissingPath('data.placement')
            ->assertJsonMissingPath('data.student_id')
            ->assertJsonMissingPath('data.student_name');

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => $role, 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.user.name', self::NAME);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertSame(
            [AppUserLog::LOGIN, AppUserLog::LOGIN, AppUserLog::LOGOUT],
            AppUserLog::orderBy('id')->pluck('action')->all(),
        );
    }

    #[DataProvider('roles')]
    public function test_wrong_password_and_unknown_username_look_the_same_and_are_limited(string $role): void
    {
        $this->useAccounts();
        $flow = $this->mobileFlow($role);

        $this->verify($role, $flow, 'wrong')->assertStatus(422)->assertJsonPath('message', self::INVALID);
        $this->verify($role, $flow, self::PASSWORD, 'nobody')->assertStatus(422)->assertJsonPath('message', self::INVALID);

        for ($i = 1; $i < config('pathshala.login_limit.attempts'); $i++) {
            $this->verify($role, $flow, 'wrong')->assertStatus(422);
        }

        $this->verify($role, $flow)->assertStatus(429);
    }

    #[DataProvider('roles')]
    public function test_there_is_no_guest_login(string $role): void
    {
        $flow = $this->mobileFlow($role);

        $this->postJson('/api/v1/auth/guest', ['flow_token' => $flow, 'school_name' => 'A', 'class_name' => 'B', 'student_id' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::FLOW_INVALID);

        $this->postJson('/api/v1/auth/teacher/guest', ['flow_token' => $flow, 'school_name' => 'A'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::FLOW_INVALID);

        $this->postJson("/api/v1/auth/{$role}/guest", ['flow_token' => $flow, 'school_name' => 'A'])->assertStatus(404);

        // The mobile flow token alone cannot skip the password step.
        $this->setPin($flow)->assertStatus(422)->assertJsonPath('message', self::FLOW_INVALID);

        $this->assertNull(AppUser::where('role', $role)->first()->pin);
    }

    #[DataProvider('roles')]
    public function test_a_school_is_required(string $role): void
    {
        $this->postJson("/api/v1/auth/{$role}/verify", [
            'flow_token' => $this->mobileFlow($role),
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['schl_id']]);
    }

    public function test_flow_tokens_cannot_cross_roles(): void
    {
        $this->useAccounts();

        $this->verify('staff', $this->mobileFlow('admin'))->assertStatus(422)->assertJsonPath('message', self::FLOW_INVALID);
        $this->verify('admin', $this->mobileFlow('staff'))->assertStatus(422)->assertJsonPath('message', self::FLOW_INVALID);
        $this->verify('admin', $this->mobileFlow('teacher'))->assertStatus(422)->assertJsonPath('message', self::FLOW_INVALID);

        $this->postJson('/api/v1/auth/teacher/verify', [
            'flow_token' => $this->mobileFlow('admin'),
            'schl_id' => self::SCHOOL_ID,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('message', self::FLOW_INVALID);
    }

    public function test_a_verified_number_cannot_be_taken_by_another_account(): void
    {
        $this->useAccounts();

        $token = $this->setPin($this->verify('admin', $this->mobileFlow('admin'))->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->verify('admin', $this->mobileFlow('admin'), self::PASSWORD, self::OTHER_USERNAME)->assertStatus(409);

        $this->assertSame((string) self::USER_ID, AppUser::where('role', 'admin')->first()->verified_id);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_app_can_use_its_own_role_names_at_every_step(): void
    {
        $this->useAccounts();

        foreach (['schoolAdmin' => 'admin', 'accounts' => 'staff'] as $appName => $stored) {
            $flow = $this->mobileFlow($appName);

            $this->setPin($this->verify($appName, $flow)->assertOk()->json('data.flow_token'))->assertOk()
                ->assertJsonPath('data.role', $appName);

            $this->assertSame(1, AppUser::where('role', $stored)->count());

            $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => $appName, 'pin' => '1234'])
                ->assertOk()
                ->assertJsonPath('data.role', $appName);
        }
    }

    public function test_the_school_header_must_match_the_token(): void
    {
        $this->useAccounts();

        $token = $this->setPin($this->verify('admin', $this->mobileFlow('admin'))->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->withToken($token)->withHeader('X-School-Id', '999')->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->withToken($token)->withHeader('X-School-Id', (string) self::SCHOOL_ID)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_errors_have_the_code_and_one_message_per_field(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => 'bad', 'role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.mobile_number', fn ($message) => is_string($message))
            ->assertJsonStructure(['errors' => ['mobile_number']]);

        $this->withoutToken()->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_the_same_account_can_verify_again_to_reset_the_pin(): void
    {
        $this->useAccounts();

        $oldToken = $this->setPin($this->verify('admin', $this->mobileFlow('admin'))->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');
        $newToken = $this->setPin($this->verify('admin', $this->mobileFlow('admin'))->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, AppUserLog::where('action', AppUserLog::PIN_RESET)->count());
    }

    public function test_a_number_with_a_pin_is_sent_to_pin_login(): void
    {
        $this->useAccounts();

        $this->setPin($this->verify('staff', $this->mobileFlow('staff'))->assertOk()->json('data.flow_token'))->assertOk();

        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'staff'])
            ->assertStatus(422)
            ->assertJsonPath('error.next', 'pin_login');
    }

    #[DataProvider('roles')]
    public function test_these_roles_cannot_use_teacher_student_or_guardian_routes(string $role): void
    {
        $this->useAccounts();

        $token = $this->setPin($this->verify($role, $this->mobileFlow($role))->assertOk()->json('data.flow_token'))->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/teacher/homework')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/teacher/quizzes')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/students/1/homework')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/guardian/children')->assertStatus(403);
    }

    public function test_only_the_right_active_logins_match_on_the_real_school_data(): void
    {
        $service = app(SchoolDbService::class);
        $tenant = $service->connect(self::SCHOOL_DB);

        $username = fn (string $type) => $tenant->table('users')
            ->where('user_type', $type)->where('activated', 1)->where('banned', 0)->value('username');

        $admin = $username('Admin');
        $superAdmin = $username('Super Admin');
        $accountant = $username('Accountant');
        $teacher = $username('Teacher');

        $this->assertNotNull($service->findAdminStaffAccount($tenant, $admin, 'admin'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $superAdmin, 'admin'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $superAdmin, 'staff'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $admin, 'staff'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $teacher, 'admin'));

        $this->assertNotNull($service->findAdminStaffAccount($tenant, $accountant, 'staff'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $accountant, 'admin'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $teacher, 'staff'));

        // The only banned login in the school is a Super Admin, so that type is allowed here to test the ban alone.
        config(['pathshala.login_user_types.admin' => ['Super Admin']]);
        $banned = $tenant->table('users')->where('user_type', 'Super Admin')->where('banned', 1)->value('username');
        $this->assertNotNull($service->findAdminStaffAccount($tenant, $superAdmin, 'admin'));
        $this->assertNull($service->findAdminStaffAccount($tenant, $banned, 'admin'));

        $this->assertNull($service->findAdminStaffAccount($tenant, 'no-such-user', 'admin'));
    }

    public static function roles(): array
    {
        return ['admin' => ['admin'], 'staff' => ['staff']];
    }
}
