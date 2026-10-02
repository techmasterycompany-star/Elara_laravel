<?php
// tests/Feature/Auth/RegisterTest.php

namespace Tests\Feature\Auth;

use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_successfully_with_email(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role'  => 'customer',
        ]);
    }

    public function test_registers_without_newsletter_opt_in_by_default(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'nosub@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_registers_with_newsletter_opt_in_true(): void
    {
        $this->postJson('/api/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'sub@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'newsletter_opt_in'     => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'sub@example.com',
        ]);
    }

    public function test_registration_fails_without_email_or_phone(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Test User',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'taken@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }
}