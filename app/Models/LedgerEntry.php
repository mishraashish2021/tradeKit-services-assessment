<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 * @property numeric-string $reserved_amount
 * @property numeric-string $balance_after
 * @property numeric-string $reserved_balance_after
 */
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'order_id',
        'type',
        'amount',
        'reserved_amount',
        'balance_after',
        'reserved_balance_after',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'reserved_amount' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'reserved_balance_after' => 'decimal:8',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
