<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'expires_at',
        'usage_limit',
        'used_count',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'expires_at' => 'date',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // ---- Validity helpers ----

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasReachedLimit(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->hasReachedLimit();
    }

    public function calculateDiscount(float $subtotal): float
    {
        return $this->discount_type === 'percent'
            ? round($subtotal * ($this->discount_value / 100), 2)
            : min($this->discount_value, $subtotal);
    }
}