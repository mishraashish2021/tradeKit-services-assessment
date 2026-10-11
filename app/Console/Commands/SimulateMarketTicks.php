<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\MarketTickService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SimulateMarketTicks extends Command
{
    protected $signature = 'market:simulate
                            {--rate=50 : Total ticks per second across all assets}
                            {--duration=0 : Duration in seconds; 0 runs until stopped}';

    protected $description = 'Continuously generate batched random-walk market ticks';

    public function handle(MarketTickService $service): int
    {
        $rate = (int) $this->option('rate');
        $duration = (int) $this->option('duration');

        if ($rate < 1 || $rate > 10000) {
            $this->error('Rate must be between 1 and 10000 ticks per second.');

            return self::FAILURE;
        }

        if ($duration < 0) {
            $this->error('Duration cannot be negative.');

            return self::FAILURE;
        }

        $assets = Asset::query()
            ->where('is_tradable', true)
            ->orderBy('id')
            ->get();

        if ($assets->isEmpty()) {
            $this->error('No tradable assets found.');

            return self::FAILURE;
        }

        $this->info(
            "Simulating {$rate} ticks/second across {$assets->count()} assets. "
            .'Press Ctrl+C to stop.'
        );

        $cursor = 0;
        $latestPrices = [];
        $startedAt = microtime(true);
        $deadline = $duration > 0 ? $startedAt + $duration : null;

        while ($deadline === null || microtime(true) < $deadline) {
            $batchStartedAt = microtime(true);
            $batch = [];

            for ($i = 0; $i < $rate; $i++) {
                $asset = $assets[$cursor % $assets->count()];
                $cursor++;

                $latest = $latestPrices[$asset->id]
                    ?? Cache::get("market:latest:{$asset->id}");

                if (! is_string($latest)) {
                    $latest = $asset->marketTicks()
                        ->orderByDesc('recorded_at')
                        ->orderByDesc('id')
                        ->value('price');
                }

                if (! is_string($latest) || bccomp($latest, '0', 8) <= 0) {
                    continue;
                }

                $basisPoints = random_int(-100, 100);

                $multiplier = bcadd(
                    '1',
                    bcdiv((string) $basisPoints, '10000', 8),
                    8
                );

                $price = bcmul(
                    $latest,
                    $multiplier,
                    (int) $asset->price_precision
                );

                if (bccomp($price, '0', 8) > 0) {
                    $batch[] = [
                        'asset_id' => (int) $asset->id,
                        'price' => $price,
                    ];

                    // Keep the generated price in memory.
                    // Redis is updated after database persistence.
                    $latestPrices[$asset->id] = $price;
                }
            }

            // Persist the batch before updating Redis.
            $service->recordBatch($batch);

            $remaining = 1 - (microtime(true) - $batchStartedAt);

            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        $this->info('Market tick simulation stopped.');

        return self::SUCCESS;
    }
}
