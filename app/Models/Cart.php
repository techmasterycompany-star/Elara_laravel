<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
    'user_id',
    'session_id',
    'coupon_id',
];
public function coupon(): BelongsTo
{
    return $this->belongsTo(Coupon::class);
}

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // ---- Helpers ----

    public function subtotal(): float
{
    return (float) $this->items->sum(fn ($item) => $item->lineTotal());
}

    public function isGuest(): bool
    {
        return is_null($this->user_id);
    }
}