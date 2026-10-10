<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'price_precision' => 2,
            'quantity_precision' => 8,
            'is_tradable' => true,
        ];
    }
}
