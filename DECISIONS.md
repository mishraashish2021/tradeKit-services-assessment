# DECISIONS.md

## 1. Locking Strategy

I use a combination of database transactions, pessimistic locking, database constraints, and Redis locks.

- **Wallet and order operations:** Database transactions and row-level locks protect balances and order state from concurrent updates.
- **Idempotency:** A unique database constraint prevents duplicate orders for the same user and idempotency key.
- **Market-summary cache:** Redis locks prevent multiple requests from rebuilding the same summary simultaneously.

Database locking is used for financial correctness, while Redis locks are used for cache coordination. The concurrent buy-order test confirms that concurrent requests cannot overspend a wallet. With more time, I would add further MySQL concurrency tests for cancellation-versus-execution races and worker failures.

## 2. Money Precision and Rounding

Prices, quantities, and balances use decimal database fields instead of floating-point fields. BCMath and decimal strings are used for precision-sensitive calculations where applicable.

Input precision is validated against the asset's supported precision. Rounding must remain consistent across order execution, fee calculation, wallet balances, and ledger entries.

With more time, I would document the exact rounding mode and fee-calculation scale and add boundary tests for fractional quantities, fees, and rounding differences.

## 3. Database Indexes and EXPLAIN

I added indexes to support frequently used queries. The `orders_user_idempotency_unique` index prevents duplicate idempotency keys for the same user. The `orders_matching_idx` index supports order-matching queries. The `orders_user_cursor_idx` index supports user order history, while `orders_expiration_idx` helps find expired pending orders. The `orders_uuid_unique` index ensures that each order UUID is unique.

I also added `orders_user_status_cursor_idx` for status-filtered user order history and `orders_user_asset_cursor_idx` for asset-filtered order history. Other migrations define indexes for ledger entries, market ticks, executions, audit logs, and other application tables.

I ran `EXPLAIN` on three queries using MySQL 8.0.46. For the user order history query, MySQL selected `orders_user_idempotency_unique`, estimated one row, and reported `Using filesort`. For the expired-order query, it selected `orders_matching_idx` instead of `orders_expiration_idx`, estimated one row, and reported `Using where`. For the pending buy-limit matching query, it selected `orders_matching_idx`, estimated one row, and reported `Using index`.

These results are from my development database, which has a small amount of data. The order history query still requires a filesort, and the expiration query does not use the expiration index in this plan. With more time, I would test the actual application queries using a larger, representative dataset and review execution plans before making further index changes.

## 4. Cache Freshness Strategy

The market-summary endpoint uses Redis caching with a TTL of up to 60 seconds and a Redis lock to reduce duplicate cache rebuilds. Redis also stores recent market prices for fast access.

This reduces database load and improves response times, but cached summaries may be stale. The actual freshness of market prices also depends on how they are updated and validated.

With more time, I would verify stale-price handling in every market-order path and improve event-driven cache invalidation, including reliable job delivery and monitoring.

## 5. Known Gaps and What I Would Do With Another Day

The main areas I would review further are:

- Add or confirm MySQL tests for cancellation-versus-execution races and concurrent worker failures.
- Verify worker retry behavior and reliable delivery of audit events.
- Test ledger reconciliation against inconsistent balances and confirm append-only protections in MySQL.
- Verify all required BUY/SELL and LIMIT/MARKET combinations, including their failure cases.
- Review market-price freshness and cache invalidation under failures.
- Validate the OpenAPI specification against actual API responses after any further API changes.

I have validated the OpenAPI file, and the reported CI workflow passed. The local test suite passed 36 tests with 142 assertions, PHPStan reported no errors, and Pint passed 97 files.

With another day, I would prioritize concurrency and failure testing, market-price freshness, and ledger reconciliation. I would also rerun the full test suite and CI after any changes.

## 6. Scaling to 100× Load

For approximately 5,000 market ticks per second and 1 million orders per day, I would:

- Batch market-tick inserts and separate market-data ingestion from order matching.
- Scale queue workers horizontally and partition matching work by asset while preserving order-book consistency.
- Keep financial updates transactional and idempotent.
- Review indexes using real query plans and consider read replicas for suitable read-heavy queries.
- Use incremental market-summary aggregation and event-driven cache invalidation.
- Monitor queue backlog, lock contention, order latency, stale prices, and ledger mismatches.

I would load-test these changes before claiming the system can handle this scale. The priority would remain financial correctness while scaling market-data ingestion, matching, and read traffic independently.