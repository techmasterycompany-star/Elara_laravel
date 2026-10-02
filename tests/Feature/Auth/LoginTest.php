<?php
// tests/Feature/Auth/LoginTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NOTE: every /api/auth/* route (register, login, forgot/reset password) shares one
 * "throttle:6,1" bucket per IP, so each test below sends at most 6 auth requests.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password'; // UserFactory default

    // ------------------------------------------------------------------
    // POST /api/auth/login
    // ------------------------------------------------------------------

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->create(['email' => 'yousef@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'login'    => 'yousef@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token']);

        $this->assertIsString($response->json('token'));
        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_user_can_login_with_phone(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '01012345678']);

        $this->postJson('/api/auth/login', [
            'login'    => '01012345678',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_issued_token_authenticates_protected_routes(): void
    {
        User::factory()->create(['email' => 'token@example.com']);

        $token = $this->postJson('/api/auth/login', [
            'login'    => 'token@example.com',
            'password' => self::PASSWORD,
        ])->json('token');

        $this->withToken($token)->getJson('/api/profile')->assertOk();
    }

    public function test_each_login_creates_a_separate_token(): void
    {
        $user = User::factory()->create(['email' => 'two@example.com']);
        $payload = ['login' => 'two@example.com', 'password' => self::PASSWORD];

        $this->postJson('/api/auth/login', $payload)->assertOk();
        $this->postJson('/api/auth/login', $payload)->assertOk();

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_wrong_password_is_rejected_and_no_token_is_created(): void
    {
        $user = User::factory()->create(['email' => 'wrong@example.com']);

        $this->postJson('/api/auth/login', [
            'login'    => 'wrong@example.com',
            'password' => 'not-the-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unknown_account_gets_the_same_error_as_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'login' => 'real@example.com', 'password' => 'nope-nope',
        ])->assertStatus(422)->json('errors.login.0');

        $unknownAccount = $this->postJson('/api/auth/login', [
            'login' => 'ghost@example.com', 'password' => 'nope-nope',
        ])->assertStatus(422)->json('errors.login.0');

        $this->assertSame($wrongPassword, $unknownAccount);
    }

    public function test_login_and_password_are_required(): void
    {
        $this->postJson('/api/auth/login', ['password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('login');

        $this->postJson('/api/auth/login', ['login' => 'a@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create(['email' => 'banned@example.com', 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'login'    => 'banned@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account has been suspended.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_suspended_status_is_not_revealed_without_the_right_password(): void
    {
        User::factory()->create(['email' => 'banned2@example.com', 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'login'    => 'banned2@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'The provided credentials are incorrect.');
    }

    public function test_google_only_user_without_a_password_cannot_login_with_password(): void
    {
        User::factory()->create(['email' => 'google@example.com', 'password' => null]);

        $this->postJson('/api/auth/login', [
            'login'    => 'google@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create(['email' => 'deleted@example.com']);
        $user->delete();

        $this->postJson('/api/auth/login', [
            'login'    => 'deleted@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_login_is_throttled_after_six_attempts(): void
    {
        $payload = ['login' => 'brute@example.com', 'password' => 'guess-guess'];

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // POST /api/auth/logout
    // ------------------------------------------------------------------

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_logout_deletes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('phone');
        $other   = $user->createToken('laptop');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_token_stops_working_after_logout(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Sanctum caches the resolved user on the guard inside one test; clear it
        // so the second request is authenticated from scratch.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
    }
}
