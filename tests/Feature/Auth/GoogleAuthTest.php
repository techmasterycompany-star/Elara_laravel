<?php
// tests/Feature/Auth/GoogleAuthTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_ID = 'google-123';
    private const EMAIL     = 'gina@example.com';

    /** Fakes the Socialite google driver so the callback receives this Google profile. */
    private function fakeGoogle(string $id = self::GOOGLE_ID, ?string $email = self::EMAIL, string $name = 'Gina Google'): void
    {
        $googleUser = (new SocialiteUser)->map(['id' => $id, 'name' => $name, 'email' => $email]);

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function googleCallback()
    {
        return $this->getJson('/api/auth/google/callback?code=fake-code');
    }

    // ------------------------------------------------------------------
    // GET /api/auth/google/redirect
    // ------------------------------------------------------------------

    public function test_redirect_sends_the_user_to_google_without_a_session_state(): void
    {
        config(['services.google' => [
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-secret',
            'redirect'      => 'http://localhost/api/auth/google/callback',
        ]]);

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('scope=', $location);
        // The API is stateless, so no CSRF "state" is stored in a session.
        $this->assertStringNotContainsString('state=', $location);
    }

    // ------------------------------------------------------------------
    // GET /api/auth/google/callback  (happy paths)
    // ------------------------------------------------------------------

    public function test_first_google_login_creates_a_verified_customer_and_returns_a_token(): void
    {
        $this->fakeGoogle();

        $response = $this->googleCallback()
            ->assertOk()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonPath('user.email', self::EMAIL);

        $this->assertArrayNotHasKey('password', $response->json('user'));

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertSame('google', $user->provider);
        $this->assertSame(self::GOOGLE_ID, $user->provider_id);
        $this->assertSame('customer', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at); // Google already verified the email
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_returned_token_authenticates_protected_routes(): void
    {
        $this->fakeGoogle();

        $token = $this->googleCallback()->json('token');

        $this->withToken($token)->getJson('/api/profile')->assertOk();
    }

    public function test_returning_google_user_gets_the_same_account_and_a_fresh_token(): void
    {
        $this->fakeGoogle();

        $first  = $this->googleCallback()->assertOk();
        $second = $this->googleCallback()->assertOk();

        $this->assertSame(1, User::count());
        $this->assertSame($first->json('user.id'), $second->json('user.id'));
        $this->assertNotSame($first->json('token'), $second->json('token'));
        $this->assertSame(2, User::first()->tokens()->count());
    }

    /**
     * Characterisation of CURRENT behaviour: a Google login whose email matches an existing
     * local account is linked to it. The local password is left untouched. See the note in the
     * answer about "pre-account takeover" if the local account's email was never verified.
     */
    public function test_google_login_links_an_existing_local_account_with_the_same_email(): void
    {
        $local = User::factory()->create(['email' => self::EMAIL]);
        $this->fakeGoogle();

        $this->googleCallback()
            ->assertOk()
            ->assertJsonPath('user.id', $local->id);

        $fresh = $local->fresh();
        $this->assertSame(1, User::count());
        $this->assertSame('google', $fresh->provider);
        $this->assertSame(self::GOOGLE_ID, $fresh->provider_id);
        $this->assertTrue(Hash::check('password', $fresh->password));
    }

    public function test_linking_keeps_the_existing_role(): void
    {
        $seller = User::factory()->create(['email' => self::EMAIL, 'role' => 'seller']);
        $this->fakeGoogle();

        $this->googleCallback()->assertOk();

        $this->assertSame('seller', $seller->fresh()->role);
    }

    // ------------------------------------------------------------------
    // BUG 1: Google login ignores is_active, so a suspended user gets a token.
    // Expected after the fix: 422 with the same message the normal login uses.
    // These two tests are DESIGNED TO FAIL until the controller is fixed.
    // ------------------------------------------------------------------

    public function test_suspended_google_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email'       => self::EMAIL,
            'provider'    => 'google',
            'provider_id' => self::GOOGLE_ID,
            'is_active'   => false,
        ]);
        $this->fakeGoogle();

        $this->googleCallback()
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account has been suspended.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_suspended_local_account_cannot_be_linked_or_logged_in_through_google(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL, 'is_active' => false]);
        $this->fakeGoogle();

        $this->googleCallback()
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account has been suspended.');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->provider);
    }

    // ------------------------------------------------------------------
    // BUG 2: a soft-deleted account with the same email is invisible to User::where(),
    // so the controller tries to INSERT a new user and hits the unique(email) index -> 500.
    // Expected after the fix: 422 and no new row. DESIGNED TO FAIL until fixed.
    // ------------------------------------------------------------------

    public function test_google_login_for_a_soft_deleted_account_is_rejected_cleanly(): void
    {
        $deleted = User::factory()->create(['email' => self::EMAIL]);
        $deleted->delete();
        $this->fakeGoogle();

        $this->googleCallback()
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(0, User::withTrashed()->find($deleted->id)->tokens()->count());
    }

    // ------------------------------------------------------------------
    // BUG 3: a cancelled consent screen / invalid code makes Socialite throw and the
    // callback returns a raw 500. Expected after the fix: 401 with a clean message.
    // DESIGNED TO FAIL until fixed.
    // ------------------------------------------------------------------

    public function test_failed_google_exchange_returns_a_clean_401_instead_of_500(): void
    {
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('invalid_grant'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->googleCallback()
            ->assertStatus(401)
            ->assertJsonPath('message', 'Google authentication failed.');

        $this->assertSame(0, User::count());
    }
}