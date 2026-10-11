<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\LedgerEntry;
use App\Models\MarketTick;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class TradingSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Demo Trader 1',
                'email' => 'trader1@example.com',
                'password' => 'password',
                'balance' => '10000.00000000',
                'token' => 'assessment-trader1-token-2026',
            ],
            [
                'name' => 'Demo Trader 2',
                'email' => 'trader2@example.com',
                'password' => 'password',
                'balance' => '25000.00000000',
                'token' => 'assessment-trader2-token-2026',
            ],
            [
                'name' => 'Demo Trader 3',
                'email' => 'trader3@example.com',
                'password' => 'password',
                'balance' => '50000.00000000',
                'token' => 'assessment-trader3-token-2026',
            ],
        ];

        $assets = [
            ['symbol' => 'DEMO', 'name' => 'Demo Asset', 'price' => '100.00000000'],
            ['symbol' => 'ALPHA', 'name' => 'Alpha Asset', 'price' => '250.00000000'],
            ['symbol' => 'BETA', 'name' => 'Beta Asset', 'price' => '75.00000000'],
            ['symbol' => 'GAMMA', 'name' => 'Gamma Asset', 'price' => '500.00000000'],
            ['symbol' => 'DELTA', 'name' => 'Delta Asset', 'price' => '125.00000000'],
        ];

        DB::transaction(function () use ($users, $assets): void {
            foreach ($users as $data) {
                $user = User::firstOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make($data['password']),
                    ]
                );

                $wallet = Wallet::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'currency' => 'INR',
                    ],
                    [
                        'available_balance' => $data['balance'],
                        'reserved_balance' => '0.00000000',
                    ]
                );

                // Record opening funding only when the wallet is first created.
                if ($wallet->wasRecentlyCreated) {
                    LedgerEntry::create([
                        'wallet_id' => $wallet->id,
                        'order_id' => null,
                        'type' => 'INITIAL_FUNDING',
                        'amount' => $data['balance'],
                        'reserved_amount' => '0.00000000',
                        'balance_after' => $data['balance'],
                        'reserved_balance_after' => '0.00000000',
                    ]);
                }

                // Fixed demo-only token: its hash is stored by Sanctum.
                $plainToken = $data['token'];

                PersonalAccessToken::firstOrCreate(
                    ['token' => hash('sha256', $plainToken)],
                    [
                        'tokenable_type' => $user->getMorphClass(),
                        'tokenable_id' => $user->id,
                        'name' => 'assessment-token',
                        'abilities' => ['*'],
                    ]
                );
            }

            foreach ($assets as $data) {
                $asset = Asset::firstOrCreate(
                    ['symbol' => $data['symbol']],
                    [
                        'name' => $data['name'],
                        'price_precision' => 2,
                        'quantity_precision' => 8,
                        'is_tradable' => true,
                    ]
                );

                // Twelve deterministic ticks per asset, five minutes apart.
                // Fixed timestamps prevent duplicate ticks on repeat runs
                // on the same date.
                $today = now()->startOfDay();

                for ($i = 0; $i < 12; $i++) {
                    $offset = ($i % 5) - 2;

                    $price = bcadd(
                        $data['price'],
                        (string) ($offset * 1),
                        8
                    );

                    $recordedAt = $today->copy()->addMinutes($i * 5);

                    MarketTick::firstOrCreate(
                        [
                            'asset_id' => $asset->id,
                            'recorded_at' => $recordedAt,
                        ],
                        ['price' => $price]
                    );
                }

                // Publish a fresh current tick so MARKET orders can be exercised immediately after seeding.
                $currentTickTime = now();
                MarketTick::firstOrCreate(
                    ['asset_id' => $asset->id, 'recorded_at' => $currentTickTime],
                    ['price' => $data['price']]
                );

                $latestPrice = MarketTick::query()->where('asset_id', $asset->id)->orderByDesc('recorded_at')->orderByDesc('id')->value('price');
                if (is_string($latestPrice)) {
                    Cache::put("market:latest:{$asset->id}", $latestPrice, now()->addDay());
                    $latestAt = MarketTick::query()->where('asset_id', $asset->id)->orderByDesc('recorded_at')->orderByDesc('id')->value('recorded_at');
                    Cache::put("market:latest_at:{$asset->id}", $latestAt ? strtotime((string) $latestAt) : now()->timestamp, now()->addDay());
                }
            }
        });
    }
}
