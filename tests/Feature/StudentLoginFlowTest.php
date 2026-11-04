<?php

namespace Tests\Feature;

use App\Models\AppLoginAttempt;
use App\Models\AppUser;
use App\Models\AppUserLog;
use App\Services\ActivityLogService;
use App\Services\SchoolDbService;
use App\Support\Phone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class StudentLoginFlowTest extends TestCase
{
    private function tenant(): Connection
    {
        return app(SchoolDbService::class)->connect(self::SCHOOL_DB);
    }

    /**
     * A real student in a running session. Passwords are never printed.
     */
    private function student(string $contactCondition): object
    {
        return $this->tenant()->table('student_info as s')
            ->join('student_current_status_info as c', 'c.student_registration_code', '=', 's.student_registration_code')
            ->join('session_info as i', 'i.session_code', '=', 'c.session_code')
            ->where('c.active_status', 1)
            ->where('i.running_session', 1)
            ->whereRaw('CHAR_LENGTH(s.student_auth) = 6')
            ->whereRaw($contactCondition)
            ->orderBy('s.student_registration_code')
            ->first(['s.student_registration_code as id', 's.student_auth as password', 's.student_name as name', 's.contact_number as contact', 'c.class_code as class_code']);
    }

    private function studentWithContact(): object
    {
        return $this->student("s.contact_number REGEXP '^01[3-9][0-9]{8}$'");
    }

    private function studentWithoutContact(): object
    {
        return $this->student("(s.contact_number IS NULL OR TRIM(s.contact_number) = '')");
    }

    private function mobileFlow(): string
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'student', 'reset_pin' => true])->assertOk();

        return $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => self::MOBILE, 'otp' => '123456'])
            ->assertOk()
            ->json('data.flow_token');
    }

    private function check(string $flow, int $classCode, string $studentId)
    {
        return $this->postJson('/api/v1/auth/student/check', [
            'flow_token' => $flow,
            'schl_id' => self::SCHOOL_ID,
            'class_code' => $classCode,
            'student_id' => $studentId,
        ]);
    }

    private function foundFlow(object $student): string
    {
        return $this->check($this->mobileFlow(), (int) $student->class_code, (string) $student->id)
            ->assertOk()
            ->json('data.flow_token');
    }

    private function setPin(string $flow, string $pin = '1234')
    {
        return $this->postJson('/api/v1/auth/set-pin', ['flow_token' => $flow, 'pin' => $pin, 'pin_confirmation' => $pin]);
    }

    private function verifiedLogin(): array
    {
        $student = $this->studentWithContact();

        $flow = $this->postJson('/api/v1/auth/student/verify-password', [
            'flow_token' => $this->foundFlow($student),
            'password' => $student->password,
        ])->assertOk()->json('data.flow_token');

        return [$student, $this->setPin($flow)->assertOk()];
    }

    public function test_verified_student_full_flow_then_logout(): void
    {
        [$student, $login] = $this->verifiedLogin();

        $login->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonPath('data.user.student_name', $student->name);

        $placement = app(SchoolDbService::class)->currentPlacement($this->tenant(), (string) $student->id);

        $login->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.name', $student->name)
            ->assertJsonPath('data.school_id', (string) self::SCHOOL_ID)
            ->assertJsonPath('data.school_name', 'Test School')
            ->assertJsonPath('data.student_id', (string) $placement->current_status_info_id)
            ->assertJsonPath('data.access_token', $login->json('data.token'))
            ->assertJsonPath('data.permissions', config('pathshala.permissions.student'))
            ->assertJsonPath('data.feature_flags', config('pathshala.feature_flags'))
            ->assertJsonMissingPath('data.refresh_token')
            ->assertJsonMissingPath('data.linked_students');
        $this->assertStringContainsString((string) $placement->class_name, $login->json('data.detail'));

        $user = AppUser::where('mobile_number', self::MOBILE)->first();
        $this->assertSame((string) $student->id, $user->verified_id);
        $this->assertSame('password', $user->verified_by);
        $this->assertNotSame('1234', $user->pin);

        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.student_name', $student->name)
            ->assertJsonPath('data.student_id', (string) $placement->current_status_info_id)
            ->assertJsonPath('data.user_id', (string) $user->id)
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonPath('data.mobile_number', Phone::mask(self::MOBILE))
            ->assertJsonStructure(['data' => ['placement' => ['session_name', 'class_name', 'section_name', 'roll_number']]]);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertSame(
            [AppUserLog::LOGIN, AppUserLog::LOGOUT],
            AppUserLog::orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_user_is_listed_with_an_empty_pin_once_the_mobile_is_verified(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'student'])->assertOk();
        $this->assertSame(0, AppUser::count());

        $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => self::MOBILE, 'otp' => '123456'])->assertOk();

        $user = AppUser::where('mobile_number', self::MOBILE)->where('role', 'student')->first();
        $this->assertNull($user->pin);
        $this->assertNull($user->auth_token);
        $this->assertFalse($user->is_verified);
        $this->assertSame(0, AppUserLog::count());
    }

    public function test_user_without_a_pin_cannot_log_in_with_a_pin(): void
    {
        $this->mobileFlow();

        $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'student', 'pin' => '1234'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('message', 'Incorrect mobile number or PIN');
    }

    public function test_first_pin_is_not_logged_as_a_pin_reset(): void
    {
        $this->verifiedLogin();

        $this->assertSame([AppUserLog::LOGIN], AppUserLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AppUser::count());
    }

    public function test_verifying_the_mobile_again_keeps_the_existing_login(): void
    {
        [, $login] = $this->verifiedLogin();
        $before = AppUser::first();

        $this->mobileFlow();

        $after = AppUser::first();
        $this->assertSame(1, AppUser::count());
        $this->assertSame($before->pin, $after->pin);
        $this->assertSame($before->auth_token, $after->auth_token);
        $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_shared_salt_signs_the_token_and_is_never_inside_it(): void
    {
        [, $login] = $this->verifiedLogin();
        $token = $login->json('data.token');

        $payload = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'));
        $this->assertStringNotContainsString(config('pathshala.token_salt'), $payload);
        $this->assertStringNotContainsString(config('pathshala.token_salt'), $login->getContent());

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        config(['pathshala.token_salt' => 'a-different-salt']);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_wrong_class_fails_without_revealing_the_real_class(): void
    {
        $student = $this->studentWithContact();
        $otherClass = (int) $student->class_code === 1 ? 2 : 1;

        $this->check($this->mobileFlow(), $otherClass, (string) $student->id)
            ->assertStatus(404)
            ->assertExactJson([
                'status' => false,
                'message' => 'The information you entered is not valid',
                'error' => ['code' => 'not_found', 'message' => 'The information you entered is not valid', 'can_continue_as_guest' => true],
                'data' => ['can_continue_as_guest' => true],
            ]);
    }

    public function test_unknown_id_fails_the_same_way(): void
    {
        $this->check($this->mobileFlow(), 1, '999999')
            ->assertStatus(404)
            ->assertExactJson([
                'status' => false,
                'message' => 'The information you entered is not valid',
                'error' => ['code' => 'not_found', 'message' => 'The information you entered is not valid', 'can_continue_as_guest' => true],
                'data' => ['can_continue_as_guest' => true],
            ]);
    }

    public function test_wrong_password_is_refused_and_limited(): void
    {
        $student = $this->studentWithContact();
        $flow = $this->foundFlow($student);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/student/verify-password', ['flow_token' => $flow, 'password' => 'wrong!'])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Incorrect password');
        }

        // Even the right password is refused once the limit is reached.
        $this->postJson('/api/v1/auth/student/verify-password', ['flow_token' => $flow, 'password' => $student->password])
            ->assertStatus(429);
    }

    public function test_forgot_password_verifies_with_contact_otp(): void
    {
        $student = $this->studentWithContact();
        $flow = $this->foundFlow($student);

        $this->postJson('/api/v1/auth/student/send-contact-otp', ['flow_token' => $flow])->assertOk()
            ->assertJsonPath('message', 'OTP sent to '.Phone::mask($student->contact))
            ->assertJsonPath('data.resend_left', 1);

        $ready = $this->postJson('/api/v1/auth/student/verify-contact-otp', ['flow_token' => $flow, 'otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.next', 'set_pin')
            ->json('data.flow_token');

        $this->setPin($ready)->assertOk()->assertJsonPath('data.user.is_verified', true);

        $this->assertSame('contact_otp', AppUser::first()->verified_by);
    }

    public function test_student_without_contact_number_is_offered_guest(): void
    {
        $student = $this->studentWithoutContact();
        $flow = $this->check($this->mobileFlow(), (int) $student->class_code, (string) $student->id)
            ->assertOk()
            ->assertJsonPath('data.has_contact_number', false)
            ->json('data.flow_token');

        $this->postJson('/api/v1/auth/student/send-contact-otp', ['flow_token' => $flow])
            ->assertStatus(422)
            ->assertExactJson([
                'status' => false,
                'message' => 'No contact number found',
                'error' => ['code' => 'validation_failed', 'message' => 'No contact number found', 'can_continue_as_guest' => true],
                'data' => ['can_continue_as_guest' => true],
            ]);
    }

    public function test_others_school_logs_in_as_guest_without_a_tenant(): void
    {
        $ready = $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $this->mobileFlow(),
            'school_name' => 'ABC High School',
            'class_name' => 'Six',
            'student_id' => '1234',
        ])->assertOk()->assertJsonPath('message', 'Continue as guest')->json('data.flow_token');

        $token = $this->setPin($ready)->assertOk()
            ->assertJsonPath('data.user.is_verified', false)
            ->assertJsonPath('data.user.school_name', 'ABC High School')
            ->assertJsonPath('data.user.student_name', null)
            ->json('data.token');

        $user = AppUser::first();
        $this->assertNull($user->schl_id);
        $this->assertNull($user->verified_id);
        $this->assertSame('1234', $user->entered_student_id);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.placement', null);
    }

    public function test_guest_from_the_school_list_gets_names_from_the_school(): void
    {
        $class = $this->tenant()->table('class_info')->where('class_activation_status', 1)->first();

        $ready = $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $this->mobileFlow(),
            'schl_id' => self::SCHOOL_ID,
            'class_code' => $class->class_code,
            'student_id' => '105002',
        ])->assertOk()->json('data.flow_token');

        $this->setPin($ready)->assertOk()
            ->assertJsonPath('data.user.is_verified', false)
            ->assertJsonPath('data.user.school_name', 'Test School')
            ->assertJsonPath('data.user.class_name', $class->class_name);
    }

    public function test_steps_cannot_be_skipped_or_reused(): void
    {
        $flow = $this->mobileFlow();

        // A mobile-verified token cannot jump to set-pin or the password step.
        $this->setPin($flow)->assertStatus(422);
        $this->postJson('/api/v1/auth/student/verify-password', ['flow_token' => $flow, 'password' => 'x'])->assertStatus(422);

        // A made-up token gets nowhere.
        $this->check('not-a-token', 1, '1')->assertStatus(422);

        // A ready token works once.
        $ready = $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $flow, 'school_name' => 'A', 'class_name' => 'B', 'student_id' => '1',
        ])->json('data.flow_token');

        $this->setPin($ready)->assertOk();
        $this->setPin($ready, '9999')->assertStatus(422);
    }

    public function test_flow_token_is_not_accepted_as_a_login_token(): void
    {
        $this->withToken($this->mobileFlow())->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_pin_login_and_wrong_pin_limit(): void
    {
        $this->verifiedLogin();

        $credentials = ['mobile_number' => self::MOBILE, 'role' => 'student'];

        $this->postJson('/api/v1/auth/pin-login', $credentials + ['pin' => '1234'])->assertOk()
            ->assertJsonPath('data.user.is_verified', true)
            ->assertJsonStructure(['data' => ['token']]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/pin-login', $credentials + ['pin' => '0000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/pin-login', $credentials + ['pin' => '1234'])->assertStatus(429);
    }

    public function test_pin_login_after_logout_issues_a_token_that_works(): void
    {
        [, $login] = $this->verifiedLogin();

        $this->withToken($login->json('data.token'))->postJson('/api/v1/auth/logout')->assertOk();

        $token = $this->postJson('/api/v1/auth/pin-login', ['mobile_number' => self::MOBILE, 'role' => 'student', 'pin' => '1234'])
            ->assertOk()
            ->json('data.token');

        $this->assertNotSame($login->json('data.token'), $token);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_pin_reset_is_logged_and_stops_old_tokens(): void
    {
        [$student, $login] = $this->verifiedLogin();
        $oldToken = $login->json('data.token');

        $ready = $this->postJson('/api/v1/auth/student/verify-password', [
            'flow_token' => $this->foundFlow($student),
            'password' => $student->password,
        ])->assertOk()->json('data.flow_token');

        $newToken = $this->setPin($ready, '5678')->assertOk()->json('data.token');

        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame(1, AppUserLog::where('action', AppUserLog::PIN_RESET)->count());
    }

    public function test_a_verified_number_cannot_be_taken_by_another_student_or_a_guest(): void
    {
        [$student, $login] = $this->verifiedLogin();
        $other = $this->studentWithoutContact();
        $this->assertNotEquals($student->id, $other->id);

        $this->postJson('/api/v1/auth/student/verify-password', [
            'flow_token' => $this->foundFlow($other),
            'password' => $other->password,
        ])->assertStatus(409);

        $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $this->mobileFlow(), 'school_name' => 'A', 'class_name' => 'B', 'student_id' => '1',
        ])->assertStatus(409);

        $user = AppUser::where('mobile_number', self::MOBILE)->first();
        $this->assertSame((string) $student->id, $user->verified_id);
        $this->assertTrue($user->is_verified);
        $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_number_with_an_account_is_sent_to_pin_login_instead_of_an_otp(): void
    {
        $send = ['mobile_number' => self::MOBILE, 'role' => 'student'];

        $this->postJson('/api/v1/auth/send-otp', $send)->assertOk();
        $this->postJson('/api/v1/auth/verify-otp', $send + ['otp' => '123456'])->assertOk();

        // Verified the OTP but never set a PIN: no account yet, so another OTP is fine.
        $this->postJson('/api/v1/auth/send-otp', $send)->assertOk();
        $this->postJson('/api/v1/auth/verify-otp', $send + ['otp' => '123456'])->assertOk();

        $this->verifiedLogin();
        $attempts = AppLoginAttempt::count();

        $this->postJson('/api/v1/auth/send-otp', $send)->assertStatus(422)
            ->assertJsonPath('message', 'This number already has an account. Log in with your PIN.')
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.next', 'pin_login')
            ->assertJsonPath('data.next', 'pin_login');
        $this->assertSame($attempts, AppLoginAttempt::count());

        // Forgot PIN still gets an OTP, and the same number under another role is a new account.
        $this->postJson('/api/v1/auth/send-otp', $send + ['reset_pin' => true])->assertOk();
        $this->postJson('/api/v1/auth/verify-otp', $send + ['otp' => '123456'])->assertOk();
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'teacher'])->assertOk();
    }

    public function test_a_guest_number_can_later_be_verified(): void
    {
        $ready = $this->postJson('/api/v1/auth/guest', [
            'flow_token' => $this->mobileFlow(), 'school_name' => 'A', 'class_name' => 'B', 'student_id' => '1',
        ])->assertOk()->json('data.flow_token');
        $this->setPin($ready)->assertOk();

        [$student] = $this->verifiedLogin();

        $user = AppUser::where('mobile_number', self::MOBILE)->first();
        $this->assertSame(1, AppUser::count());
        $this->assertTrue($user->is_verified);
        $this->assertSame((string) $student->id, $user->verified_id);
    }

    public function test_secrets_are_never_returned_or_readable_in_the_token(): void
    {
        $schools = $this->getJson('/api/v1/schools')->assertOk();

        $schools->assertExactJson([
            'status' => true,
            'message' => 'Schools',
            'data' => [['schl_id' => self::SCHOOL_ID, 'schl_name' => 'Test School', 'schl_code' => 'school_one']],
        ]);

        [$student, $login] = $this->verifiedLogin();

        $this->assertStringNotContainsString($student->password, $login->getContent());
        $this->assertStringNotContainsString(self::SCHOOL_DB, $login->getContent());

        $payload = base64_decode(strtr(explode('.', $login->json('data.token'))[1], '-_', '+/'));
        $this->assertStringNotContainsString(self::SCHOOL_DB, $payload);

        $me = $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->getContent();
        foreach ([$student->password, self::SCHOOL_DB, self::MOBILE] as $secret) {
            $this->assertStringNotContainsString($secret, $me);
        }
    }

    public function test_classes_are_listed_in_order_and_unknown_school_is_404(): void
    {
        $this->getJson('/api/v1/schools/'.self::SCHOOL_ID.'/classes')->assertOk()
            ->assertJsonPath('data.0.class_name', 'KG')
            ->assertJsonStructure(['data' => [['class_code', 'class_name', 'class_name_bn']]]);

        $this->getJson('/api/v1/schools/9/classes')->assertStatus(404);
        $this->getJson('/api/v1/no-such-route')->assertStatus(404)->assertExactJson([
            'status' => false,
            'message' => 'Not found',
            'error' => ['code' => 'not_found', 'message' => 'Not found'],
        ]);
    }

    public function test_school_database_is_read_only(): void
    {
        $this->expectException(QueryException::class);

        $this->tenant()->table('class_info')->where('class_code', -1)->update(['class_name' => 'x']);
    }

    public function test_activity_goes_to_a_monthly_file_and_can_be_read_back(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15));

        [, $login] = $this->verifiedLogin();
        $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();

        $activity = app(ActivityLogService::class);
        $user = AppUser::first();
        $userId = $user->id;

        // Only changes are logged, so viewing the profile adds nothing.
        $this->assertSame([], $activity->read(2026, 11));

        $activity->record($user, 'create_homework');

        $this->assertFileExists(config('pathshala.activity_log_path').'/activity_2026_11.log');
        $this->assertSame('create_homework', $activity->read(2026, 11)[0]['action']);
        $this->assertCount(1, $activity->read(2026, 11, $userId));
        $this->assertCount(0, $activity->read(2026, 11, $userId + 1));
        $this->assertSame([], $activity->read(2020, 1));

        $this->travelTo(now()->setDate(2026, 12, 1));
        $activity->record($user, 'create_homework');
        $this->assertFileExists(config('pathshala.activity_log_path').'/activity_2026_12.log');
    }

    public function test_phone_numbers_are_cleaned_up(): void
    {
        $this->assertSame('01712345678', Phone::normalize('+880 1712-345678'));
        $this->assertSame('01712345678', Phone::normalize('01712345678'));
        $this->assertNull(Phone::normalize('1712345678'));
        $this->assertNull(Phone::normalize(null));
        $this->assertSame('017*****678', Phone::mask('01712345678'));
    }
}
