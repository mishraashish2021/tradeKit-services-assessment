-- Run after migrations and seeding against MySQL. Replace user_id/asset_id with real IDs.
EXPLAIN SELECT id, status, created_at FROM orders WHERE user_id = 1 ORDER BY id DESC LIMIT 20;
EXPLAIN SELECT id, status, created_at FROM orders WHERE user_id = 1 AND status = 'PENDING' ORDER BY id DESC LIMIT 20;
EXPLAIN SELECT id, status, created_at FROM orders WHERE user_id = 1 AND asset_id = 1 ORDER BY id DESC LIMIT 20;
EXPLAIN SELECT id, asset_id, side, type, quantity, limit_price FROM orders WHERE status = 'PENDING' AND asset_id = 1 AND side = 'BUY' AND type = 'LIMIT' AND limit_price >= 100.00000000 ORDER BY id LIMIT 50;
EXPLAIN SELECT id, asset_id, price, recorded_at FROM market_ticks WHERE asset_id = 1 ORDER BY recorded_at DESC, id DESC LIMIT 1;
EXPLAIN SELECT order_id, price, quantity, executed_at FROM executions WHERE executed_at >= NOW() - INTERVAL 1 DAY;
EXPLAIN SELECT order_id, type, amount, reserved_amount FROM ledger_entries WHERE wallet_id = 1 ORDER BY id DESC LIMIT 20;
EXPLAIN SELECT id, action, subject_type, subject_id, created_at FROM audit_logs WHERE actor_id = 1 ORDER BY created_at DESC LIMIT 20;
EXPLAIN SELECT COUNT(orders.id)
FROM executions
JOIN orders ON orders.id = executions.order_id
WHERE orders.status = 'FILLED'
  AND executions.executed_at >= NOW() - INTERVAL 1 DAY;

EXPLAIN SELECT assets.id, assets.symbol, SUM(executions.price * executions.quantity) AS notional_volume
FROM executions
JOIN orders ON orders.id = executions.order_id
JOIN assets ON assets.id = orders.asset_id
WHERE orders.status = 'FILLED'
  AND executions.executed_at >= NOW() - INTERVAL 1 DAY
GROUP BY assets.id, assets.symbol
ORDER BY notional_volume DESC
LIMIT 5;
