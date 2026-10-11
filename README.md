# DECISIONS.md

## 1. Locking Strategy

I use a combination of database transactions, pessimistic locking, database constraints, and Redis locks.

- **Wallet and order operations:** Database transactions and row-level locks protect balances and order state from concurrent updates.
- **Idempotency:** A unique database constraint prevents duplicate orders for the same user and idempotency key.
- **Market-summary cache:** Redis locks prevent multiple requests from rebuilding the same summary simultaneously.

Database locking is used for financial correctness, while Redis locks are used for cache coordination. With more time, I would add further MySQL concurrency tests for cancellation-versus-execution races and worker retries.

## 2. Money Precision and Rounding

Prices, quantities, and balances use decimal database fields instead of floating-point fields. BCMath and decimal strings are used for precision-sensitive calculations where applicable.

Input precision is validated against the asset's supported precision. The rounding rules for execution values and fees must remain consistent across order processing and ledger entries.

**Further improvement:** Document the exact rounding mode and fee-calculation scale, then add boundary tests for fractional quantities, fees, and rounding differences.

## 3. Database Indexes and EXPLAIN

I added indexes to improve the performance of frequently used queries. The `orders_user_idempotency_unique` index prevents the same user from placing duplicate orders with the same idempotency key. The `orders_matching_idx` index helps find orders that are eligible for matching. The `orders_user_cursor_idx` index supports fetching a user's order history, while `orders_expiration_idx` helps find pending orders that have expired. The `orders_uuid_unique` index ensures that every order has a unique UUID.

I also added `orders_user_status_cursor_idx` to support filtering a user's orders by status and `orders_user_asset_cursor_idx` to support filtering orders by asset. Other migrations contain indexes for ledger entries, market ticks, executions, and audit logs.

I ran `EXPLAIN` on three queries using MySQL 8.0.46. For the user order history query, MySQL selected `orders_user_idempotency_unique`, estimated one row, and reported `Using filesort`. For the expired-order query, it selected `orders_matching_idx` instead of `orders_expiration_idx`, estimated one row, and reported `Using where`. For the pending buy-limit matching query, it selected `orders_matching_idx`, estimated one row, and reported `Using index`.

These results are from my current development database, which has a small amount of data. The order history query still requires a filesort, and the expiration query does not use the expiration index. With more time, I would test these queries with a larger dataset, review the actual application queries, and make further index changes only if the results show that they are needed.

## 4. Cache Freshness Strategy

The market-summary endpoint uses Redis caching with a TTL of up to **60 seconds** and a Redis lock to reduce duplicate cache rebuilds. Redis also stores recent market prices for fast access.

**Trade-off:** Caching reduces database load and response time, but summary data may be stale for up to the cache lifetime. For stricter freshness, I would use event-driven invalidation after executions and market updates, with reliable job delivery and monitoring.

## 5. Known Gaps and One More Day of Work

The following items need final verification against the assessment requirements and the submitted code:

- Capture actual MySQL `EXPLAIN` output and confirm every documented index definition.
- Verify all required BUY/SELL and LIMIT/MARKET combinations and their failure cases.
- Add or confirm MySQL tests for cancellation-versus-execution races and duplicate worker processing.
- Verify ledger reconciliation, append-only protections, audit-event reliability, and queue retry behavior.
- Validate the OpenAPI specification against actual API responses and confirm the CI workflow passes.

With another day, I would prioritize concurrency and failure tests first, then query-plan evidence, ledger reconciliation, and final API/documentation validation. Any requirement still incomplete would be listed explicitly rather than marked as finished.

## 6. Scaling to 100× Load

For approximately **5,000 market ticks per second and 1 million orders per day**, I would:

- Batch market-tick inserts and separate ingestion from order matching.
- Scale queue workers horizontally and partition matching work by asset while preserving order-book consistency.
- Keep financial updates transactional and idempotent.
- Optimize indexes using real query plans and consider read replicas for suitable read-heavy queries.
- Use incremental market-summary aggregation and event-driven cache invalidation.
- Add monitoring for queue backlog, lock contention, order latency, stale prices, and ledger mismatches.

I would load-test these changes before claiming the system can handle this scale. The priority would remain financial correctness while scaling market-data ingestion, matching, and read traffic independently.

## 7. AI Tools Disclosure

I used ChatGPT as an AI assistance tool during development for understanding requirements, discussing implementation approaches, debugging issues, preparing test ideas, and improving documentation.

I reviewed the suggested changes, ran the automated tests and code quality checks, and validated the OpenAPI specification. The final implementation and its limitations are documented in this repository.
