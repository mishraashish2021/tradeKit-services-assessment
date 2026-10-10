<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MarketTick;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketTick>
 */
class MarketTickFactory extends Factory
{
    protected $model = MarketTick::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'price' => '100.00000000',
            'recorded_at' => now(),
        ];
    }
}
