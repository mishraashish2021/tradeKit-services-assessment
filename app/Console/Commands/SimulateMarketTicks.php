<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\MarketTickService;
use Illuminate\Console\Command;

class SimulateMarketTicks extends Command
{
    protected $signature = 'market:simulate-ticks';

    protected $description = 'Generate simulated market price ticks for tradable assets';

    public function handle(MarketTickService $service): int
    {
        $assets = Asset::query()
            ->where('is_tradable', true)
            ->orderBy('id')
            ->get();

        foreach ($assets as $asset) {
            $latestPrice = $asset->marketTicks()
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->value('price');

            if ($latestPrice === null) {
                $this->warn("Skipping {$asset->symbol}: no existing market price.");

                continue;
            }

            // Simulate a small movement between -1% and +1%.
            $movementBasisPoints = random_int(-100, 100);
            $movement = bcdiv((string) $movementBasisPoints, '10000', 8);

            $multiplier = bcadd('1', $movement, 8);
            $newPrice = bcmul((string) $latestPrice, $multiplier, 8);

            if (bccomp($newPrice, '0', 8) <= 0) {
                $this->warn("Skipping {$asset->symbol}: generated price was invalid.");

                continue;
            }

            $service->record($asset->id, $newPrice);

            $this->info(
                "{$asset->symbol}: {$latestPrice} -> {$newPrice}"
            );
        }

        return self::SUCCESS;
    }
}
