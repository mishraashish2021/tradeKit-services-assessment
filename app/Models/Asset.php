<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read numeric-string|null $latest_price
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'price_precision',
        'quantity_precision',
        'is_tradable',
    ];

    protected function casts(): array
    {
        return [
            'is_tradable' => 'boolean',
        ];
    }

    /** @return HasMany<MarketTick, $this> */
    public function marketTicks(): HasMany
    {
        return $this->hasMany(MarketTick::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Position, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
