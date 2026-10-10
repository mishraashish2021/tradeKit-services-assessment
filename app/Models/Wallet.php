<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property numeric-string $available_balance
 * @property numeric-string $reserved_balance
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'available_balance',
        'reserved_balance',
    ];

    protected function casts(): array
    {
        return [
            'available_balance' => 'decimal:8',
            'reserved_balance' => 'decimal:8',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
