<?php
// tests/Feature/Auth/PasswordResetTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Every /api/auth/* route shares one "throttle:6,1" bucket per IP,
 * so each test sends at most 6 auth requests.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'reset@example.com';

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['email' => self::EMAIL], $overrides));
    }

    private function forgot(string $email = self::EMAIL)
    {
        return $this->postJson('/api/auth/forgot-password', ['email' => $email]);
    }

    private function reset(string $token, array $overrides = [])
    {
        return $this->postJson('/api/auth/reset-password', array_merge([
            'token'                 => $token,
            'email'                 => self::EMAIL,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // POST /api/auth/forgot-password
    // ------------------------------------------------------------------

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', [])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_reset_link_is_sent_to_an_existing_user_and_a_token_is_stored(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->forgot()
            ->assertOk()
            ->assertJsonPath('message', __('passwords.sent'));

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => self::EMAIL]);
    }

    /**
     * Characterisation of CURRENT behaviour: an unknown email returns 422, while login
     * deliberately hides whether an account exists. Worth knowing for #50 (user enumeration).
     */
    public function test_unknown_email_gets_422_and_no_mail_is_sent(): void
    {
        Notification::fake();

        $this->forgot('ghost@example.com')
            ->assertStatus(422)
            ->assertJsonPath('message', __('passwords.user'));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_soft_deleted_user_cannot_request_a_reset_link(): void
    {
        Notification::fake();
        $user = $this->user();
        $user->delete();

        $this->forgot()->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_requesting_twice_within_the_throttle_window_sends_only_one_mail(): void
    {
        Notification::fake();
        $this->user();

        $this->forgot()->assertOk();
        $this->forgot()
            ->assertStatus(422)
            ->assertJsonPath('message', __('passwords.throttled'));

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    // ------------------------------------------------------------------
    // BUG (found by reading the code): the project has no 'password.reset' route and
    // never calls ResetPassword::createUrlUsing(), so building the real reset mail
    // should throw RouteNotFoundException and forgot-password would return 500.
    // These two tests are DESIGNED TO FAIL until the reset URL points to the frontend
    // (same way VerifyEmailNotification already does with config('app.frontend_url')).
    // ------------------------------------------------------------------

    public function test_forgot_password_really_builds_and_sends_the_reset_mail(): void
    {
        $this->user();

        // No Notification::fake(): the notification is rendered and sent via the "array" mailer.
        $this->forgot()
            ->assertOk()
            ->assertJsonPath('message', __('passwords.sent'));
    }

    public function test_reset_mail_link_points_to_the_frontend_with_token_and_email(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->forgot()->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url'))
                && str_contains($url, 'token='.$notification->token)
                && str_contains($url, 'email=');
        });
    }

    // ------------------------------------------------------------------
    // POST /api/auth/reset-password
    // ------------------------------------------------------------------

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);

        $this->reset($token)
            ->assertOk()
            ->assertJsonPath('message', __('passwords.reset'));

        $fresh = $user->fresh();
        $this->assertNotSame('new-password-123', $fresh->password);
        $this->assertTrue(Hash::check('new-password-123', $fresh->password));

        // End to end: the new password logs in, the old one no longer does.
        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'new-password-123'])
            ->assertOk();
        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_reset_revokes_every_existing_api_token(): void
    {
        $user = $this->user();
        $user->createToken('phone');
        $user->createToken('laptop');
        $this->assertSame(2, $user->tokens()->count());

        $this->reset(Password::createToken($user))->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_dispatches_the_password_reset_event(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->user();

        $this->reset(Password::createToken($user))->assertOk();

        Event::assertDispatched(PasswordReset::class, fn ($e) => $e->user->is($user));
    }

    public function test_a_reset_token_can_only_be_used_once(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);

        $this->reset($token)->assertOk();
        $this->reset($token, ['password' => 'another-pass-999', 'password_confirmation' => 'another-pass-999'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('passwords.token'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_an_invalid_token_is_rejected_and_the_password_is_unchanged(): void
    {
        $user = $this->user();
        Password::createToken($user);

        $this->reset('totally-wrong-token')
            ->assertStatus(422)
            ->assertJsonPath('message', __('passwords.token'));

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_token_does_not_work_for_a_different_email(): void
    {
        $owner = $this->user();
        $other = $this->user(['email' => 'other@example.com']);
        $token = Password::createToken($owner);

        $this->reset($token, ['email' => 'other@example.com'])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $other->fresh()->password));
        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->reset($token)
            ->assertStatus(422)
            ->assertJsonPath('message', __('passwords.token'));

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_token_still_works_just_before_it_expires(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);

        $this->travel(59)->minutes();

        $this->reset($token)->assertOk();
    }

    public function test_reset_validates_its_input(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);

        $this->reset('', ['token' => null])
            ->assertStatus(422)->assertJsonValidationErrors('token');

        $this->reset($token, ['email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->reset($token, ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->reset($token, ['password_confirmation' => 'does-not-match'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_soft_deleted_user_cannot_reset_a_password(): void
    {
        $user  = $this->user();
        $token = Password::createToken($user);
        $user->delete();

        $this->reset($token)->assertStatus(422);

        $this->assertTrue(Hash::check('password', User::withTrashed()->find($user->id)->password));
    }

    public function test_google_only_user_can_set_a_password_through_reset(): void
    {
        $user  = $this->user(['password' => null]);
        $token = Password::createToken($user);

        $this->reset($token)->assertOk();

        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'new-password-123'])
            ->assertOk();
    }

    public function test_suspended_user_can_reset_the_password_but_still_cannot_login(): void
    {
        $user  = $this->user(['is_active' => false]);
        $token = Password::createToken($user);

        $this->reset($token)->assertOk();

        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'new-password-123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account has been suspended.');
    }
}