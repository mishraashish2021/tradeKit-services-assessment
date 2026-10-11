<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $fee
 * @property numeric-string $price
 */
class Execution extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'price', 'quantity', 'fee', 'executed_at'];

    protected function casts(): array
    {
        return ['price' => 'decimal:8', 'quantity' => 'decimal:8', 'fee' => 'decimal:8', 'executed_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
