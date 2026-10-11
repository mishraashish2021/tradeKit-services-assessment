<?php

namespace App\Console\Commands;

use App\Services\MarketPriceCacheRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:recover-market-price-cache')]
#[Description('Recover latest market prices and timestamps from persisted market ticks')]
class RecoverMarketPriceCache extends Command
{
    public function handle(MarketPriceCacheRecoveryService $recovery): int
    {
        $count = $recovery->recover();

        $this->info("Recovered market prices for {$count} tradable assets.");

        return self::SUCCESS;
    }
}
