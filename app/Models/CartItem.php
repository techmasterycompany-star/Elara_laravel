<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price_at_add',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price_at_add' => 'decimal:2',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
{
    $price = ($this->product && $this->product->status === 'active')
        ? $this->product->currentPrice()
        : (float) $this->price_at_add;

    return (float) ($price * $this->quantity);
}
}