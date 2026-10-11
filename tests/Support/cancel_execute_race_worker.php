<?php

use App\Models\MarketTick;
use App\Models\Order;
use App\Services\CancelBuyOrderService;
use App\Services\ExecuteBuyOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = getenv('DB_DATABASE') ?: 'trading_order_system_test';

if ($database !== 'trading_order_system_test') {
    fwrite(STDERR, "Refusing to run outside the test database.\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3306',
    'database.connections.mysql.database' => $database,
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'laravel',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'laravel_dev_password',
]);

DB::purge('mysql');

$action = (string) ($argv[1] ?? '');
$orderId = (int) ($argv[2] ?? 0);
$userId = (int) ($argv[3] ?? 0);

try {
    if ($action === 'cancel') {
        $order = app(CancelBuyOrderService::class)->cancel($orderId, $userId);
    } elseif ($action === 'execute') {
        $order = Order::query()->findOrFail($orderId);

        $tick = MarketTick::query()
            ->where('asset_id', $order->asset_id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->firstOrFail();

        Cache::put("market:latest:{$order->asset_id}", (string) $tick->price, 60);
        Cache::put("market:latest_at:{$order->asset_id}", now()->timestamp, 60);

        $order = app(ExecuteBuyOrderService::class)->execute($orderId);
    } else {
        fwrite(STDERR, "Action must be cancel or execute.\n");
        exit(2);
    }

    echo "RESULT:{$action}:{$order->status->value}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR:'.$exception->getMessage()."\n");
    exit(1);
}
