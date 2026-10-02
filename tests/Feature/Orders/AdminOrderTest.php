<?php
// tests/Feature/Admin/AdminOrderTest.php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function actAs(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function orderWithItems(array $itemStatuses, array $orderAttributes = []): Order
    {
        $order = Order::factory()->create($orderAttributes);

        foreach ($itemStatuses as $status) {
            OrderItem::factory()->create(['order_id' => $order->id, 'status' => $status]);
        }

        return $order;
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_guests_get_401_on_every_admin_order_endpoint(): void
    {
        $order = Order::factory()->create(['shipping_city' => 'Cairo']);

        foreach ($this->endpoints($order) as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertUnauthorized();
        }

        $this->assertSame('Cairo', $order->fresh()->shipping_city);
    }

    public function test_customers_and_sellers_get_403_on_every_admin_order_endpoint(): void
    {
        $order = Order::factory()->create(['shipping_city' => 'Cairo']);

        foreach (['customer', 'seller'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($this->endpoints($order) as [$method, $url, $data]) {
                $this->actAs($user)->json($method, $url, $data)->assertForbidden();
            }
        }

        // Not even the customer who owns the order may use the admin route.
        $this->actAs($order->user)
            ->putJson("/api/admin/orders/{$order->id}/shipping", ['shipping_city' => 'Giza'])
            ->assertForbidden();

        $this->assertSame('Cairo', $order->fresh()->shipping_city);
    }

    private function endpoints(Order $order): array
    {
        return [
            ['GET', '/api/admin/orders', []],
            ['GET', "/api/admin/orders/{$order->id}", []],
            ['PUT', "/api/admin/orders/{$order->id}/shipping", ['shipping_city' => 'Giza']],
        ];
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_is_paginated_20_per_page_newest_first(): void
    {
        foreach (range(1, 25) as $i) {
            Order::factory()->create(['created_at' => now()->subMinutes(30 - $i)]);
        }

        $newest = Order::orderByDesc('created_at')->first();

        $this->actAs($this->admin())
            ->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('data.0.id', $newest->id);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        Order::factory()->count(2)->create(['status' => 'paid']);
        Order::factory()->create(['status' => 'pending_payment']);
        Order::factory()->create(['status' => 'cancelled']);
        $admin = $this->admin();

        $this->actAs($admin)->getJson('/api/admin/orders?status=paid')
            ->assertOk()->assertJsonPath('total', 2);

        $this->actAs($admin)->getJson('/api/admin/orders?status=pending_payment')
            ->assertOk()->assertJsonPath('total', 1);

        $this->actAs($admin)->getJson('/api/admin/orders?status=cancelled')
            ->assertOk()->assertJsonPath('total', 1);

        $this->actAs($admin)->getJson('/api/admin/orders')
            ->assertOk()->assertJsonPath('total', 4);
    }

    public function test_list_can_be_filtered_by_an_inclusive_date_range(): void
    {
        Order::factory()->create(['created_at' => '2026-09-01 10:00:00']);
        $inFirst = Order::factory()->create(['created_at' => '2026-09-10 00:00:00']);
        $inLast = Order::factory()->create(['created_at' => '2026-09-30 23:30:00']);
        Order::factory()->create(['created_at' => '2026-10-01 00:00:00']);

        $response = $this->actAs($this->admin())
            ->getJson('/api/admin/orders?date_from=2026-09-10&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$inFirst->id, $inLast->id], $ids);
    }

    public function test_list_filters_are_validated(): void
    {
        $admin = $this->admin();

        foreach ([
            '?status=shipped',
            '?date_from=not-a-date',
            '?date_to=not-a-date',
            '?date_from=2026-09-30&date_to=2026-09-01',
        ] as $query) {
            $this->actAs($admin)->getJson('/api/admin/orders' . $query)->assertStatus(422);
        }
    }

    public function test_list_shows_the_overall_status_computed_from_the_items(): void
    {
        $delivered = $this->orderWithItems(['delivered', 'delivered']);
        $mixed = $this->orderWithItems(['pending', 'shipped']);
        $cancelled = $this->orderWithItems(['cancelled', 'cancelled']);
        $partlyCancelled = $this->orderWithItems(['cancelled', 'shipped']);

        $response = $this->actAs($this->admin())->getJson('/api/admin/orders')->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame('delivered', $rows[$delivered->id]['overall_status']);
        $this->assertSame('pending', $rows[$mixed->id]['overall_status']);
        $this->assertSame('cancelled', $rows[$cancelled->id]['overall_status']);
        $this->assertSame('shipped', $rows[$partlyCancelled->id]['overall_status']);
        $this->assertCount(2, $rows[$delivered->id]['items']);
    }

    public function test_list_exposes_only_the_safe_user_fields(): void
    {
        $order = Order::factory()->create();

        $this->actAs($this->admin())
            ->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $order->user_id)
            ->assertJsonPath('data.0.user.email', $order->user->email)
            ->assertJsonMissingPath('data.0.user.password')
            ->assertJsonMissingPath('data.0.user.wallet_balance');
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_the_order_with_items_customer_payment_and_overall_status(): void
    {
        $order = $this->orderWithItems(['shipped', 'shipped'], ['status' => 'paid']);
        Payment::create([
            'order_id' => $order->id,
            'gateway'  => 'cod',
            'amount'   => $order->total,
            'status'   => 'paid',
        ]);

        $this->actAs($this->admin())
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.user.id', $order->user_id)
            ->assertJsonPath('order.payment.0.gateway', 'cod')
            ->assertJsonPath('overall_status', 'shipped')
            ->assertJsonCount(2, 'order.items')
            ->assertJsonMissingPath('order.user.password');
    }

    public function test_show_works_for_a_guest_order(): void
    {
        $order = Order::factory()->guest()->create();

        $this->actAs($this->admin())
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.guest_email', $order->guest_email)
            ->assertJsonPath('order.user', null);
    }

    public function test_show_unknown_order_gets_404(): void
    {
        $this->actAs($this->admin())->getJson('/api/admin/orders/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Update shipping
    // ------------------------------------------------------------------

    public function test_admin_can_update_only_some_shipping_fields(): void
    {
        $order = Order::factory()->create([
            'shipping_name'        => 'Old Name',
            'shipping_city'        => 'Cairo',
            'shipping_governorate' => 'Cairo',
        ]);

        $this->actAs($this->admin())
            ->putJson("/api/admin/orders/{$order->id}/shipping", [
                'shipping_city'   => 'Giza',
                'shipping_street' => '5 New Street',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Shipping information updated successfully.')
            ->assertJsonPath('order.shipping_city', 'Giza');

        $fresh = $order->fresh();

        $this->assertSame('Giza', $fresh->shipping_city);
        $this->assertSame('5 New Street', $fresh->shipping_street);
        $this->assertSame('Old Name', $fresh->shipping_name);
        $this->assertSame('Cairo', $fresh->shipping_governorate);
    }

    public function test_updating_shipping_cannot_change_money_status_or_owner(): void
    {
        $order = Order::factory()->create(['status' => 'pending_payment']);
        $other = User::factory()->create();
        $before = $order->only(['status', 'subtotal', 'discount', 'shipping_fee', 'total', 'user_id', 'payment_method']);

        $this->actAs($this->admin())
            ->putJson("/api/admin/orders/{$order->id}/shipping", [
                'shipping_city'  => 'Giza',
                'status'         => 'paid',
                'total'          => 1,
                'shipping_fee'   => 0,
                'discount'       => 9999,
                'user_id'        => $other->id,
                'payment_method' => 'wallet',
            ])
            ->assertOk();

        $fresh = $order->fresh();

        $this->assertSame('Giza', $fresh->shipping_city);
        $this->assertEquals($before, $fresh->only(array_keys($before)));
    }

    public function test_shipping_update_validates_the_input(): void
    {
        $order = Order::factory()->create(['shipping_city' => 'Cairo', 'shipping_name' => 'Keep Me']);
        $admin = $this->admin();

        foreach ([
            ['shipping_name'        => ''],
            ['shipping_name'        => str_repeat('A', 256)],
            ['shipping_phone'       => str_repeat('1', 51)],
            ['shipping_street'      => str_repeat('A', 256)],
            ['shipping_city'        => str_repeat('A', 101)],
            ['shipping_governorate' => str_repeat('A', 101)],
            ['shipping_city'        => ['not', 'a', 'string']],
        ] as $invalid) {
            $this->actAs($admin)
                ->putJson("/api/admin/orders/{$order->id}/shipping", $invalid)
                ->assertStatus(422);
        }

        $fresh = $order->fresh();

        $this->assertSame('Cairo', $fresh->shipping_city);
        $this->assertSame('Keep Me', $fresh->shipping_name);
    }

    public function test_updating_shipping_of_an_unknown_order_gets_404(): void
    {
        $this->actAs($this->admin())
            ->putJson('/api/admin/orders/999999/shipping', ['shipping_city' => 'Giza'])
            ->assertNotFound();
    }

    public function test_shipping_can_still_be_edited_on_cancelled_and_delivered_orders(): void
    {
        // Documents current behaviour (kept on list #50): there is no status guard.
        $admin = $this->admin();
        $cancelled = Order::factory()->cancelled()->create();
        $delivered = $this->orderWithItems(['delivered']);

        foreach ([$cancelled, $delivered] as $order) {
            $this->actAs($admin)
                ->putJson("/api/admin/orders/{$order->id}/shipping", ['shipping_city' => 'Giza'])
                ->assertOk();

            $this->assertSame('Giza', $order->fresh()->shipping_city);
        }
    }
}
