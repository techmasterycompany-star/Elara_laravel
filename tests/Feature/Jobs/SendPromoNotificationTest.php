<?php
// tests/Feature/Jobs/SendPromoNotificationTest.php

namespace Tests\Feature\Jobs;

use App\Jobs\SendPromoNotification;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendPromoNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_notification_to_eligible_users(): void
    {
        $banner = Banner::factory()->create(['title' => 'Big Sale']);
        $user = User::factory()->create(['is_active' => true, 'promo_notifications' => true]);

        SendPromoNotification::dispatchSync($banner->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => 'promotion',
            'message' => 'New offer: Big Sale',
        ]);
    }

    public function test_does_not_duplicate_notification_on_second_dispatch(): void
    {
        $banner = Banner::factory()->create(['title' => 'Big Sale']);
        User::factory()->create(['is_active' => true, 'promo_notifications' => true]);

        SendPromoNotification::dispatchSync($banner->id);
        SendPromoNotification::dispatchSync($banner->id); // نفس البانر، تاني مرة

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_skips_inactive_users(): void
    {
        $banner = Banner::factory()->create();
        $inactiveUser = User::factory()->create(['is_active' => false, 'promo_notifications' => true]);

        SendPromoNotification::dispatchSync($banner->id);

        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveUser->id]);
    }

    public function test_skips_users_who_opted_out(): void
    {
        $banner = Banner::factory()->create();
        $optedOutUser = User::factory()->create(['is_active' => true, 'promo_notifications' => false]);

        SendPromoNotification::dispatchSync($banner->id);

        $this->assertDatabaseMissing('notifications', ['user_id' => $optedOutUser->id]);
    }

    public function test_skips_inactive_banner(): void
    {
        $banner = Banner::factory()->create(['is_active' => false]);
        $user = User::factory()->create(['is_active' => true, 'promo_notifications' => true]);

        SendPromoNotification::dispatchSync($banner->id);

        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id]);
    }
}