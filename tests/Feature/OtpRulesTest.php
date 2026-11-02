<?php

namespace Tests\Feature;

use App\Models\AppLoginAttempt;
use App\Services\OtpService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OtpRulesTest extends TestCase
{
    private const LIMIT_MESSAGE = 'OTP limit reached. Please check your number and try again later.';
    private const BLOCKED_MESSAGE = 'OTP blocked. Please try again after 1 hour.';

    private function send()
    {
        return $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'student']);
    }

    private function verify(string $otp)
    {
        return $this->postJson('/api/v1/auth/verify-otp', ['mobile_number' => self::MOBILE, 'otp' => $otp]);
    }

    private function attempt(): ?AppLoginAttempt
    {
        return AppLoginAttempt::where('mobile_number', self::MOBILE)->first();
    }

    public function test_send_returns_masked_number_and_stores_a_hashed_otp(): void
    {
        $this->send()->assertOk()->assertExactJson([
            'status' => true,
            'message' => 'OTP sent to 017*****001',
            'data' => ['expires_in_seconds' => 1800, 'resend_left' => 1],
        ]);

        $this->assertNotSame('123456', $this->attempt()->otp);
    }

    public function test_resend_is_refused_while_the_otp_is_valid(): void
    {
        $this->send();

        $this->send()->assertStatus(429)
            ->assertJsonPath('message', 'Please wait before requesting a new OTP')
            ->assertJsonStructure(['data' => ['retry_after_seconds']]);
    }

    public function test_resend_is_allowed_after_30_minutes_and_a_third_send_hits_the_limit(): void
    {
        $this->send();
        $this->travel(31)->minutes();

        $this->send()->assertOk()->assertJsonPath('data.resend_left', 0);

        $this->send()->assertStatus(429)->assertJsonPath('message', self::LIMIT_MESSAGE);
    }

    public function test_correct_otp_deletes_the_record(): void
    {
        $this->send();

        $this->verify('123456')->assertOk()
            ->assertJsonPath('message', 'Mobile verified')
            ->assertJsonStructure(['data' => ['flow_token']]);

        $this->assertNull($this->attempt());
    }

    public function test_two_wrong_entries_lock_for_one_hour_then_counters_start_again(): void
    {
        $this->send();

        $this->verify('000000')->assertStatus(422)
            ->assertJsonPath('message', 'Incorrect OTP')
            ->assertJsonPath('data.attempts_left', 1);

        $this->verify('000000')->assertStatus(422)->assertJsonPath('message', self::BLOCKED_MESSAGE);
        $this->assertNotNull($this->attempt()->locked_at);

        // Even the right OTP and a resend are refused during the lock.
        $this->verify('123456')->assertStatus(422)->assertJsonPath('message', self::BLOCKED_MESSAGE);
        $this->send()->assertStatus(429)->assertJsonPath('message', self::LIMIT_MESSAGE);

        $this->travel(59)->minutes();
        $this->send()->assertStatus(429);

        $this->travel(2)->minutes();
        $this->send()->assertOk()->assertJsonPath('data.resend_left', 1);
        $this->assertSame(1, $this->attempt()->send_count);
        $this->assertNull($this->attempt()->locked_at);
    }

    public function test_both_otps_expiring_unused_locks_from_the_second_expiry(): void
    {
        $this->send();
        $this->travel(31)->minutes();
        $this->send();

        // 2nd OTP expired 1 minute ago: locked.
        $this->travel(31)->minutes();
        $this->verify('123456')->assertStatus(422)->assertJsonPath('message', self::BLOCKED_MESSAGE);
        $this->send()->assertStatus(429)->assertJsonPath('message', self::LIMIT_MESSAGE);
        $this->assertNotNull($this->attempt()->locked_at);

        $this->travel(61)->minutes();
        $this->send()->assertOk()->assertJsonPath('data.resend_left', 1);
    }

    public function test_expired_first_otp_says_expired(): void
    {
        $this->send();
        $this->travel(31)->minutes();

        $this->verify('123456')->assertStatus(422)->assertJsonPath('message', 'OTP expired. Please request a new OTP.');
    }

    public function test_login_and_contact_counters_are_separate(): void
    {
        $otp = app(OtpService::class);

        $otp->send(self::MOBILE, OtpService::PURPOSE_LOGIN);
        $otp->send(self::MOBILE, OtpService::PURPOSE_CONTACT);

        $this->assertSame(2, AppLoginAttempt::where('mobile_number', self::MOBILE)->count());
    }

    public function test_prune_command_removes_only_records_past_their_hour(): void
    {
        $this->send();
        $this->verify('000000');
        $this->verify('000000');

        app(OtpService::class)->send('01700000002', OtpService::PURPOSE_LOGIN);

        Artisan::call('otp:prune');
        $this->assertSame(2, AppLoginAttempt::count());

        $this->travel(40)->minutes();
        Artisan::call('otp:prune');
        $this->assertSame(2, AppLoginAttempt::count());

        $this->travel(21)->minutes();
        Artisan::call('otp:prune');
        $this->assertSame(1, AppLoginAttempt::count());
        $this->assertNull($this->attempt());
    }

    public function test_invalid_input_uses_the_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => '123', 'role' => 'student'])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['message', 'errors' => ['mobile_number']]);

        $this->postJson('/api/v1/auth/send-otp', ['mobile_number' => self::MOBILE, 'role' => 'teacher'])
            ->assertStatus(422);
    }
}
