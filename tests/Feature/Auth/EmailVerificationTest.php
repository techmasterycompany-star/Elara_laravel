<?php
// tests/Feature/Auth/EmailVerificationTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Signed URLs embed the host: pin it so signing and requesting always agree.
        URL::forceRootUrl('http://localhost');
    }

    private function unverified(array $overrides = []): User
    {
        return User::factory()->unverified()->create(array_merge(['email' => 'verify@example.com'], $overrides));
    }

    private function signedUrl(User $user, ?string $hashSource = null, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id'   => $user->getKey(),
            'hash' => sha1($hashSource ?? $user->email),
        ]);
    }

    // ------------------------------------------------------------------
    // GET /api/email/verify/{id}/{hash}
    // ------------------------------------------------------------------

    public function test_valid_signed_link_verifies_the_users_email(): void
    {
        $user = $this->unverified();
        $this->assertFalse($user->hasVerifiedEmail());

        $this->actingAs($user, 'sanctum')
            ->getJson($this->signedUrl($user))
            ->assertOk()
            ->assertJsonPath('message', 'Email verified successfully.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_dispatches_the_verified_event(): void
    {
        Event::fake([Verified::class]);
        $user = $this->unverified();

        $this->actingAs($user, 'sanctum')->getJson($this->signedUrl($user))->assertOk();

        Event::assertDispatched(Verified::class, fn ($e) => $e->user->is($user));
    }

    public function test_already_verified_user_gets_a_friendly_message_and_nothing_changes(): void
    {
        Event::fake([Verified::class]);
        $verifiedAt = now()->subDays(5)->startOfSecond();
        $user = User::factory()->create(['email' => 'done@example.com', 'email_verified_at' => $verifiedAt]);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->signedUrl($user))
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
        Event::assertNotDispatched(Verified::class);
    }

    public function test_guest_cannot_use_a_verification_link_even_if_the_signature_is_valid(): void
    {
        $user = $this->unverified();

        $this->getJson($this->signedUrl($user))->assertUnauthorized();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $user = $this->unverified();

        $this->actingAs($user, 'sanctum')
            ->getJson($this->signedUrl($user).'x')
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = $this->unverified();
        $hash = sha1($user->email);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/email/verify/{$user->id}/{$hash}")
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_is_rejected(): void
    {
        $user = $this->unverified();
        $url  = $this->signedUrl($user);

        $this->travel(61)->minutes();

        $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_still_works_just_before_it_expires(): void
    {
        $user = $this->unverified();
        $url  = $this->signedUrl($user);

        $this->travel(59)->minutes();

        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
    }

    public function test_someone_elses_link_cannot_verify_another_account(): void
    {
        $owner    = $this->unverified(['email' => 'owner@example.com']);
        $attacker = $this->unverified(['email' => 'attacker@example.com']);

        $this->actingAs($attacker, 'sanctum')
            ->getJson($this->signedUrl($owner))
            ->assertForbidden();

        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($attacker->fresh()->hasVerifiedEmail());
    }

    public function test_link_with_a_hash_of_a_different_email_is_rejected(): void
    {
        $user = $this->unverified();

        $this->actingAs($user, 'sanctum')
            ->getJson($this->signedUrl($user, 'other@example.com'))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_link_inside_the_real_verification_mail_works_end_to_end(): void
    {
        Notification::fake();
        $user = $this->unverified();
        $link = null;

        $this->actingAs($user, 'sanctum')->postJson('/api/email/resend')->assertOk();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        // The mail points at the frontend; the frontend forwards the same path + query to the API.
        $this->assertStringStartsWith(config('app.frontend_url'), $link);
        $this->assertStringContainsString("/api/email/verify/{$user->id}/".sha1($user->email), $link);

        $apiUrl = str_replace(config('app.frontend_url'), URL::to('/'), $link);

        $this->actingAs($user, 'sanctum')->getJson($apiUrl)->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ------------------------------------------------------------------
    // POST /api/email/resend
    // ------------------------------------------------------------------

    public function test_guest_cannot_resend(): void
    {
        $this->postJson('/api/email/resend')->assertUnauthorized();
    }

    public function test_unverified_user_can_request_a_new_link(): void
    {
        Notification::fake();
        $user = $this->unverified();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Verification link sent.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_really_builds_and_sends_the_mail(): void
    {
        $user = $this->unverified();

        // No Notification::fake(): the mail is rendered and sent through the "array" mailer.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Verification link sent.');
    }

    public function test_verified_user_gets_a_message_and_no_mail_is_sent(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'done@example.com']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        Notification::assertNothingSent();
    }

    public function test_account_without_an_email_gets_422_on_resend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => null, 'phone' => '01012345678']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/resend')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No email on this account to verify.');

        Notification::assertNothingSent();
    }

    public function test_resend_is_throttled_after_six_requests(): void
    {
        Notification::fake();
        $user = $this->unverified();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/email/resend')->assertOk();
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/email/resend')->assertStatus(429);

        Notification::assertSentTimes(VerifyEmailNotification::class, 6);
    }

    // ------------------------------------------------------------------
    // Registration hooks
    // ------------------------------------------------------------------

    public function test_registering_with_an_email_sends_a_verification_mail_and_starts_unverified(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name'                  => 'Mail User',
            'email'                 => 'mailuser@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'mailuser@example.com')->first();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_registering_with_a_phone_only_sends_no_mail_and_is_marked_verified(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name'                  => 'Phone User',
            'phone'                 => '01099998888',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('phone', '01099998888')->first();

        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
    }
}