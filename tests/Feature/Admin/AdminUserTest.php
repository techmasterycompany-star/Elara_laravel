<?php
// tests/Feature/Admin/AdminUserTest.php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_cannot_reach_any_admin_user_endpoint(): void
    {
        $target = User::factory()->create();

        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson("/api/admin/users/{$target->id}")->assertUnauthorized();
        $this->patchJson("/api/admin/users/{$target->id}/suspend")->assertUnauthorized();
        $this->patchJson("/api/admin/users/{$target->id}/activate")->assertUnauthorized();
        $this->deleteJson("/api/admin/users/{$target->id}")->assertUnauthorized();
    }

    public function test_customers_and_sellers_get_403_on_every_admin_user_endpoint(): void
    {
        $target = User::factory()->create();

        foreach (['customer', 'seller'] as $role) {
            $caller = User::factory()->create(['role' => $role]);

            $this->actingAs($caller, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
            $this->actingAs($caller, 'sanctum')->getJson("/api/admin/users/{$target->id}")->assertForbidden();
            $this->actingAs($caller, 'sanctum')->patchJson("/api/admin/users/{$target->id}/suspend")->assertForbidden();
            $this->actingAs($caller, 'sanctum')->patchJson("/api/admin/users/{$target->id}/activate")->assertForbidden();
            $this->actingAs($caller, 'sanctum')->deleteJson("/api/admin/users/{$target->id}")->assertForbidden();
        }

        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertNull($target->deleted_at);
    }

    // ------------------------------------------------------------------
    // GET /api/admin/users
    // ------------------------------------------------------------------

    public function test_index_is_paginated_by_twenty(): void
    {
        User::factory()->count(25)->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk();

        $this->assertCount(20, $response->json('data'));
        $this->assertSame(26, $response->json('total')); // 25 + الأدمن نفسه
    }

    public function test_search_matches_name_email_and_phone(): void
    {
        $byName  = User::factory()->create(['name' => 'Zelda Wonderland']);
        $byEmail = User::factory()->create(['email' => 'quixote@example.com']);
        $byPhone = User::factory()->create(['phone' => '01099887766']);
        User::factory()->count(3)->create();

        $admin = $this->admin();

        $ids = fn (string $term) => $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?search=' . urlencode($term))
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$byName->id], $ids('Wonderland'));
        $this->assertSame([$byEmail->id], $ids('quixote@'));
        $this->assertSame([$byPhone->id], $ids('0109988'));
    }

    public function test_role_filter(): void
    {
        $sellers = User::factory()->count(2)->create(['role' => 'seller']);
        User::factory()->count(3)->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users?role=seller')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $sellers->pluck('id')->all(),
            $response->json('data.*.id')
        );
    }

    public function test_unknown_role_filter_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users?role=superuser')
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_is_active_filter(): void
    {
        $suspended = User::factory()->count(2)->create(['is_active' => false]);
        User::factory()->count(3)->create();

        $admin = $this->admin();

        $inactive = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?is_active=0')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $suspended->pluck('id')->all(),
            $inactive->json('data.*.id')
        );

        $active = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?is_active=1')
            ->assertOk();

        $this->assertSame(4, $active->json('total')); // 3 + الأدمن
    }

    public function test_index_never_exposes_passwords(): void
    {
        User::factory()->create();

        $row = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users')
            ->json('data.0');

        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);
    }

    // ------------------------------------------------------------------
    // GET /api/admin/users/{user}
    // ------------------------------------------------------------------

    public function test_show_returns_the_user_without_secrets(): void
    {
        $target = User::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id);

        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_show_returns_404_for_a_missing_user(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/users/999999')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // PATCH /api/admin/users/{user}/suspend
    // ------------------------------------------------------------------

    public function test_suspend_deactivates_the_user_and_revokes_all_their_tokens(): void
    {
        $target = User::factory()->create();
        $target->createToken('phone');
        $target->createToken('laptop');

        $bystander = User::factory()->create();
        $bystander->createToken('keep-me');

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/suspend")
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $target = User::factory()->create(['email' => 'victim@example.com']);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/suspend")
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'login'    => 'victim@example.com',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$admin->id}/suspend")
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_suspending_a_missing_or_deleted_user_returns_404(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/999999/suspend')
            ->assertNotFound();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$deleted->id}/suspend")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // PATCH /api/admin/users/{user}/activate
    // ------------------------------------------------------------------

    public function test_activate_restores_a_suspended_user_who_can_then_log_in(): void
    {
        $target = User::factory()->create([
            'email'     => 'back@example.com',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/admin/users/{$target->id}/activate")
            ->assertOk()
            ->assertJsonPath('user.is_active', true);

        $this->postJson('/api/auth/login', [
            'login'    => 'back@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_activating_a_missing_user_returns_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/users/999999/activate')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // DELETE /api/admin/users/{user}
    // ------------------------------------------------------------------

    public function test_destroy_soft_deletes_the_user_and_revokes_tokens(): void
    {
        $target = User::factory()->create();
        $target->createToken('phone');

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_a_deleted_user_cannot_log_in_and_disappears_from_the_list(): void
    {
        $target = User::factory()->create(['email' => 'gone@example.com']);
        $admin  = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'login'    => 'gone@example.com',
            'password' => 'password',
        ])->assertStatus(422);

        $ids = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->json('data.*.id');

        $this->assertNotContains($target->id, $ids);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_deleting_a_missing_or_already_deleted_user_returns_404(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/users/999999')
            ->assertNotFound();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$deleted->id}")
            ->assertNotFound();
    }
}
