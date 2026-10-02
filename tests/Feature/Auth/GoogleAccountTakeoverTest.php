<?php
// tests/Feature/Auth/GoogleAccountTakeoverTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAccountTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_ID = 'google-123';
    private const EMAIL     = 'victim@example.com';

    private function fakeGoogle(string $id = self::GOOGLE_ID, array $raw = []): void
    {
        $googleUser = (new SocialiteUser)
            ->map(['id' => $id, 'name' => 'Victim', 'email' => self::EMAIL])
            ->setRaw($raw);

        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function googleCallback()
    {
        return $this->getJson('/api/auth/google/callback?code=fake-code');
    }

    public function test_linking_an_unverified_local_account_removes_the_registrants_password(): void
    {
        // The attacker registered with the victim's email before the victim ever signed up.
        $planted = User::factory()->unverified()->create(['email' => self::EMAIL]);
        $this->fakeGoogle();

        $this->googleCallback()->assertOk()->assertJsonPath('user.id', $planted->id);

        $fresh = $planted->fresh();
        $this->assertNull($fresh->password);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame('google', $fresh->provider);
        $this->assertSame(self::GOOGLE_ID, $fresh->provider_id);

        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_linking_an_unverified_local_account_revokes_the_registrants_tokens(): void
    {
        $planted = User::factory()->unverified()->create(['email' => self::EMAIL]);
        $planted->createToken('attacker');
        $this->assertSame(1, $planted->tokens()->count());
        $this->fakeGoogle();

        $this->googleCallback()->assertOk();

        // Only the fresh Google login token remains.
        $this->assertSame(1, $planted->tokens()->count());
        $this->assertNotSame('attacker', $planted->tokens()->first()->name);
    }

    public function test_linking_a_verified_local_account_keeps_its_password_and_tokens(): void
    {
        $local = User::factory()->create(['email' => self::EMAIL]);
        $local->createToken('phone');
        $this->fakeGoogle();

        $this->googleCallback()->assertOk();

        $this->assertNotNull($local->fresh()->password);
        $this->assertSame(2, $local->tokens()->count());
        $this->postJson('/api/auth/login', ['login' => self::EMAIL, 'password' => 'password'])->assertOk();
    }

    public function test_an_email_google_says_is_unverified_is_rejected_without_linking(): void
    {
        $local = User::factory()->create(['email' => self::EMAIL]);
        $this->fakeGoogle(raw: ['email_verified' => false]);

        $this->googleCallback()
            ->assertStatus(401)
            ->assertJsonPath('message', 'Google authentication failed.');

        $this->assertNull($local->fresh()->provider_id);
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_google_email_cannot_create_an_account(): void
    {
        $this->fakeGoogle(raw: ['email_verified' => false]);

        $this->googleCallback()->assertStatus(401);

        $this->assertSame(0, User::count());
    }

    public function test_an_account_linked_to_another_google_identity_is_not_hijacked(): void
    {
        $local = User::factory()->create([
            'email'       => self::EMAIL,
            'provider'    => 'google',
            'provider_id' => 'someone-elses-google-id',
        ]);
        $this->fakeGoogle(id: self::GOOGLE_ID);

        $this->googleCallback()
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');

        $this->assertSame('someone-elses-google-id', $local->fresh()->provider_id);
        $this->assertSame(0, $local->tokens()->count());
    }
}
