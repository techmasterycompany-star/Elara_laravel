<?php
// tests/Feature/Orders/UpdateItemStatusTest.php

namespace Tests\Feature\Orders;

use App\Mail\OrderStatusChangedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UpdateItemStatusTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Creates an order item (in its own order unless one is given).
     */
    private function item(?Seller $seller = null, string $status = 'pending', array $attributes = [], ?Order $order = null): OrderItem
    {
        $order ??= Order::factory()->create();
        $product = Product::factory()->create(['stock' => 10, 'seller_id' => $seller?->id]);

        return OrderItem::factory()->create(array_merge([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'seller_id'  => $seller?->id,
            'quantity'   => 2,
            'price'      => 40,
            'status'     => $status,
        ], $attributes));
    }

    private function url(OrderItem|int $item): string
    {
        $id = $item instanceof OrderItem ? $item->id : $item;

        return "/api/order-items/{$id}/status";
    }

    private function setStatus(User $user, OrderItem $item, string $status)
    {
        return $this->actingAs($user, 'sanctum')->patchJson($this->url($item), ['status' => $status]);
    }

    // ------------------------------------------------------------------
    // Permissions & validation
    // ------------------------------------------------------------------

    public function test_guest_gets_401(): void
    {
        $item = $this->item();

        $this->patchJson($this->url($item), ['status' => 'processing'])->assertUnauthorized();

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_customers_get_403(): void
    {
        $item = $this->item();
        $customer = User::factory()->create(['role' => 'customer']);

        $this->setStatus($customer, $item, 'processing')->assertForbidden();

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_status_is_required_and_must_be_a_known_value(): void
    {
        $item = $this->item();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->patchJson($this->url($item), [])->assertStatus(422);
        $this->setStatus($admin, $item, 'refunded')->assertStatus(422);

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_unknown_item_gets_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson($this->url(999999), ['status' => 'processing'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Seller rules
    // ------------------------------------------------------------------

    public function test_seller_moves_own_item_pending_to_processing_to_shipped(): void
    {
        $seller = Seller::factory()->create();
        $item = $this->item($seller);

        $this->setStatus($seller->user, $item, 'processing')
            ->assertOk()
            ->assertJsonPath('item.status', 'processing');

        $this->setStatus($seller->user, $item, 'shipped')
            ->assertOk()
            ->assertJsonPath('item.status', 'shipped');

        $this->assertSame('shipped', $item->fresh()->status);
    }

    public function test_seller_cannot_skip_go_backwards_deliver_or_cancel(): void
    {
        $seller = Seller::factory()->create();

        $cases = [
            ['pending',    'shipped'],
            ['pending',    'delivered'],
            ['pending',    'cancelled'],
            ['processing', 'pending'],
            ['processing', 'delivered'],
            ['processing', 'cancelled'],
            ['shipped',    'delivered'],
            ['shipped',    'processing'],
            ['delivered',  'shipped'],
            ['cancelled',  'processing'],
        ];

        foreach ($cases as [$from, $to]) {
            $item = $this->item($seller, $from);

            $this->setStatus($seller->user, $item, $to)->assertForbidden();

            $this->assertSame($from, $item->fresh()->status, "{$from} -> {$to} must be refused");
        }
    }

    public function test_seller_cannot_update_another_sellers_item(): void
    {
        $seller = Seller::factory()->create();
        $other = Seller::factory()->create();
        $item = $this->item($other);

        $this->setStatus($seller->user, $item, 'processing')->assertForbidden();

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_seller_cannot_update_an_admin_owned_item(): void
    {
        $seller = Seller::factory()->create();
        $item = $this->item(null);   // seller_id is null

        $this->setStatus($seller->user, $item, 'processing')->assertForbidden();

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_sellers_who_are_not_approved_cannot_update_items(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $seller = Seller::factory()->create(['status' => $status]);
            $item = $this->item($seller);

            $this->setStatus($seller->user, $item, 'processing')->assertForbidden();

            $this->assertSame('pending', $item->fresh()->status, "a {$status} seller must be refused");
        }
    }

    public function test_seller_role_without_a_profile_gets_403(): void
    {
        $user = User::factory()->create(['role' => 'seller']);
        $item = $this->item(Seller::factory()->create());

        $this->setStatus($user, $item, 'processing')->assertForbidden();

        $this->assertSame('pending', $item->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Admin rules
    // ------------------------------------------------------------------

    public function test_admin_can_set_any_status_even_skipping_steps(): void
    {
        $admin = $this->admin();
        $item = $this->item(null, 'pending');

        $this->setStatus($admin, $item, 'delivered')->assertOk();

        $this->assertSame('delivered', $item->fresh()->status);
    }

    public function test_admin_can_move_an_item_backwards(): void
    {
        $item = $this->item(null, 'shipped');

        $this->setStatus($this->admin(), $item, 'pending')->assertOk();

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_delivered_and_cancelled_items_can_no_longer_be_changed(): void
    {
        $admin = $this->admin();

        foreach (['delivered', 'cancelled'] as $final) {
            $item = $this->item(null, $final);

            $this->setStatus($admin, $item, 'processing')
                ->assertStatus(422)
                ->assertJsonPath('message', 'A delivered or cancelled item can no longer be changed.');

            $this->assertSame($final, $item->fresh()->status);
        }
    }

    // ------------------------------------------------------------------
    // Stock
    // ------------------------------------------------------------------

    public function test_cancelling_an_item_restores_its_stock_exactly_once(): void
    {
        $admin = $this->admin();
        $item = $this->item(null, 'pending', ['quantity' => 3]);

        $this->setStatus($admin, $item, 'cancelled')->assertOk();
        $this->assertSame(13, $item->product->fresh()->stock);

        // A second cancel is refused and must not restore the stock again.
        $this->setStatus($admin, $item, 'cancelled')->assertStatus(422);
        $this->assertSame(13, $item->product->fresh()->stock);
    }

    public function test_other_status_changes_do_not_touch_stock(): void
    {
        $item = $this->item(null, 'pending', ['quantity' => 3]);

        $this->setStatus($this->admin(), $item, 'shipped')->assertOk();

        $this->assertSame(10, $item->product->fresh()->stock);
    }

    public function test_cancelling_an_item_of_a_paid_order_does_not_refund_the_customer(): void
    {
        // Documents current behaviour (kept on list #50): only the stock is
        // restored; no refund, no payment change and no order status change.
        $user = User::factory()->create(['wallet_balance' => 0]);
        $order = Order::factory()->create([
            'user_id'        => $user->id,
            'status'         => 'paid',
            'payment_method' => 'wallet',
            'total'          => 80,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'gateway'  => 'wallet',
            'amount'   => 80,
            'status'   => 'paid',
        ]);
        $item = $this->item(null, 'pending', ['quantity' => 2, 'price' => 40], $order);

        $this->setStatus($this->admin(), $item, 'cancelled')->assertOk();

        $this->assertEquals(0, (float) $user->fresh()->wallet_balance);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Customer e-mail
    // ------------------------------------------------------------------

    public function test_customer_is_emailed_when_the_order_status_changes(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $item = $this->item(null, 'pending', [], $order);

        $this->setStatus($this->admin(), $item, 'processing')->assertOk();

        Mail::assertQueued(
            OrderStatusChangedMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->newStatus === 'processing'
        );
    }

    public function test_guest_is_emailed_at_the_guest_email(): void
    {
        Mail::fake();

        $order = Order::factory()->guest()->create();
        $item = $this->item(null, 'pending', [], $order);

        $this->setStatus($this->admin(), $item, 'processing')->assertOk();

        Mail::assertQueued(
            OrderStatusChangedMail::class,
            fn ($mail) => $mail->hasTo($order->guest_email)
        );
    }

    public function test_no_email_when_the_overall_order_status_does_not_change(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $order = Order::factory()->create();
        $first = $this->item(null, 'pending', [], $order);
        $second = $this->item(null, 'pending', [], $order);

        // One of two items moves: the order as a whole is still "pending".
        $this->setStatus($admin, $first, 'processing')->assertOk();
        Mail::assertNothingQueued();

        // The second one moves too: now the whole order is "processing".
        $this->setStatus($admin, $second, 'processing')->assertOk();
        Mail::assertQueued(OrderStatusChangedMail::class, 1);
    }

    // ------------------------------------------------------------------
    // Seller earnings follow delivered items
    // ------------------------------------------------------------------

    public function test_seller_earnings_grow_only_when_an_item_is_delivered(): void
    {
        $seller = Seller::factory()->create();
        $admin = $this->admin();

        $delivered = $this->item($seller, 'shipped', ['quantity' => 2, 'price' => 40]);   // 80
        $cancelled = $this->item($seller, 'pending', ['quantity' => 5, 'price' => 10]);   // never counted

        $balance = fn () => (float) $this->actingAs($seller->user, 'sanctum')
            ->getJson('/api/seller/earnings')
            ->assertOk()
            ->json('available_balance');

        $this->assertSame(0.0, $balance());

        $this->setStatus($admin, $delivered, 'delivered')->assertOk();
        $this->setStatus($admin, $cancelled, 'cancelled')->assertOk();

        $this->assertSame(80.0, $balance());
    }
}
