<?php

use App\Services\PlaceBuyOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Ensure child workers use the database connection from the CI environment.
config([
    'database.connections.mysql.host' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3306',
    'database.connections.mysql.database' => getenv('DB_DATABASE') ?: 'trading_order_system_test',
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'laravel',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'laravel_dev_password',
]);

DB::purge('mysql');

$database = config('database.connections.mysql.database');

if ($database !== 'trading_order_system_test') {
    fwrite(STDERR, "Refusing to run outside the test database.\n");
    exit(2);
}

$userId = (int) ($argv[1] ?? 0);
$assetId = (int) ($argv[2] ?? 0);
$key = (string) ($argv[3] ?? '');

try {
    $order = app(PlaceBuyOrderService::class)->place($userId, [
        'asset_id' => $assetId,
        'side' => 'BUY',
        'type' => 'LIMIT',
        'quantity' => '2.00000000',
        'limit_price' => '100.00000000',
        'idempotency_key' => $key,
    ]);

    echo "SUCCESS:{$order->id}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'REJECTED:'.$exception->getMessage()."\n");
    exit(1);
}
