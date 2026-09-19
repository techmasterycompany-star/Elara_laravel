<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'coupon_id',
        'shipping_name',
        'shipping_phone',
        'shipping_street',
        'shipping_city',
        'shipping_governorate',
        'guest_email',
        'status',
        'subtotal',
        'discount',
        'shipping_fee',
        'total',
        'payment_method',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // ---- Relationships ----

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ---- Helpers ----

    public function isGuestOrder(): bool
    {
        return is_null($this->user_id);
    }

    public function isPaid(): bool
    {
        return $this->payment()->where('status', 'paid')->exists();
    }

    public static function generateOrderNumber(int $id): string
{
    return 'GT-'.now()->format('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}
public function computedStatus(): string
{
    $statuses = $this->items->pluck('status');

    if ($statuses->isEmpty()) {
        return $this->status;
    }

    $activeStatuses = $statuses->filter(fn ($s) => $s !== 'cancelled');

    if ($activeStatuses->isEmpty()) {
        return 'cancelled'; // كل الـ items اتلغت
    }

    $rank = ['pending' => 0, 'processing' => 1, 'shipped' => 2, 'delivered' => 3];

    $lowestRank = $activeStatuses->min(fn ($s) => $rank[$s] ?? 99);

    return array_search($lowestRank, $rank, true);
}
}