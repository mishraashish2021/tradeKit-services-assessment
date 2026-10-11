<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int $asset_id
 * @property OrderSide $side
 * @property OrderType $type
 * @property OrderStatus $status
 * @property numeric-string $quantity
 * @property numeric-string|null $limit_price
 * @property numeric-string $reserved_amount
 */
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'quantity' => 'decimal:8',
            'limit_price' => 'decimal:8',
            'reserved_amount' => 'decimal:8',
        ];
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'asset_id',
        'side',
        'type',
        'quantity',
        'limit_price',
        'status',
        'idempotency_key',
        'request_hash',
        'failure_reason',
        'expires_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return HasOne<Execution, $this> */
    public function execution(): HasOne
    {
        return $this->hasOne(Execution::class);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
