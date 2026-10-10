# Trading Order System — Engineering Decisions

## 1. Money and Quantity Precision

- Store monetary values and quantities using MySQL DECIMAL columns.
- Use decimal arithmetic through BCMath for financial calculations.
- Avoid floating-point arithmetic for balances, prices, and quantities.
- Validate positive amounts before performing wallet operations.

## 2. Order Placement and Idempotency

- Scope idempotency keys to the user.
- Enforce uniqueness through the database constraint
  orders_user_idempotency_unique (user_id, idempotency_key).
- Store a request hash to detect reuse of a key with different request details.
- Perform order creation and wallet reservation transactionally.
- Lock wallet rows when updating balances to prevent concurrent overspending.

## 3. Wallet Ledger and Transaction Safety

- Record reservations and releases as separate ledger entries.
- Update wallet balances and create corresponding ledger entries within
  database transactions.
- Release reserved funds when an eligible order is cancelled or fails.
- Prevent repeated cancellation or job processing from releasing funds twice.
- Ledger entries should be treated as append-only accounting records.

## 4. Order Execution and Failure Handling

- Use queued execution processing.
- Make execution processing safe to retry.
- Protect wallet and order updates with database transactions and row locks.
- Prevent duplicate executions with a unique constraint on execution order_id.
- Verify order state before cancellation, execution, or failure handling.

## 5. Database Indexes

The following indexes were verified in the MySQL database:

- orders_user_idempotency_unique (user_id, idempotency_key)
- orders_user_cursor_idx (user_id, id)
- orders_matching_idx (status, asset_id, side, type, limit_price)
- orders_expiration_idx (status, expires_at)
- orders_uuid_unique (uuid)
- market_ticks_asset_recorded_idx (asset_id, recorded_at)

Other primary-key and foreign-key indexes are present as well.

## 6. Verified MySQL EXPLAIN Results

The following plans were captured from the development database. Estimates
reflect the current, small dataset and are not production benchmarks.

### 6.1 User order listing

Query:

    SELECT id, status, created_at
    FROM orders
    WHERE user_id = 4
    ORDER BY id DESC
    LIMIT 20;

Observed plan:

- Access type: ref
- Selected index: orders_user_idempotency_unique
- Estimated rows: 1
- Extra: Using filesort

Observation: MySQL used an index for the user lookup, but performed a
separate sort. The user-order index orders_user_cursor_idx (user_id, id)
exists; test with representative data before deciding whether to change it.

### 6.2 Order matching

Query:

    SELECT id, asset_id, side, type, quantity, limit_price
    FROM orders
    WHERE status = 'PENDING'
      AND asset_id = 1
      AND side = 'BUY'
      AND type = 'LIMIT'
      AND limit_price >= 100.00000000
    ORDER BY created_at ASC
    LIMIT 50;

Observed plan:

- Access type: ref
- Selected index: orders_expiration_idx
- Estimated rows: 1
- Filtered estimate: 50%
- Extra: Using where; Using filesort

Observation: MySQL selected the expiration index rather than the matching
index. The query also performs filtering and sorting. Re-evaluate with
representative order volumes and the actual matching query before adding
or changing indexes.

### 6.3 Latest market tick

Query:

    SELECT id, asset_id, price, recorded_at
    FROM market_ticks
    WHERE asset_id = 1
    ORDER BY recorded_at DESC
    LIMIT 1;

Observed plan:

- Access type: ref
- Selected index: market_ticks_asset_recorded_idx
- Estimated rows: 14
- Filtered estimate: 100%
- Extra: Backward index scan

Observation: MySQL used the (asset_id, recorded_at) index and scanned it
backwards to retrieve the latest tick without a separate filesort.

## 7. Testing and Validation

Verified locally:

- Automated tests: 26 passed, 100 assertions.
- PHPStan: no errors.
- Laravel Pint: formatting check passed.
- Manual API checks covered idempotent BUY order placement, insufficient
  available balance, cancellation, and repeated cancellation.
- Wallet and ledger checks confirmed that a reservation of 200 was
  released once and that the final wallet balance returned to its
  original amount.

These checks cover the tested scenarios only and do not establish that
every assessment requirement or production-scale behavior has been verified.

## 8. Limitations and Further Validation

- Repeat EXPLAIN checks with representative data volumes.
- Verify API contract requirements, including idempotency header handling
  and expected HTTP status codes.
- Verify SELL order handling and market summary requirements.
- Confirm ledger immutability is enforced beyond application conventions.
- Document any remaining assessment gaps honestly in the README.

## 9. Locking Strategy and Trade-offs

- **Pessimistic locking:** Use database transactions and row-level locks when updating wallet balances and processing order state. This helps prevent concurrent requests from overspending funds or processing the same order inconsistently.
- **Database constraints:** Use unique constraints as a final safeguard against duplicate idempotency keys and executions.
- **Redis locks:** Redis is used for caching, not as the primary mechanism for protecting financial transactions. Database transactions, row locks, and constraints remain responsible for correctness.
- **Trade-off:** Row locking provides strong consistency but can increase contention when many requests update the same wallet or order.

## 10. Cache Freshness

- Cache the market summary in Redis with a TTL of 10 seconds.
- Invalidate the market-summary cache when a new market tick is recorded.
- This balances response speed with reasonably fresh market data.
- **Trade-off:** Cached data may be briefly stale, particularly if invalidation fails. Financial order validation must not depend solely on cached market data.

## 11. Known Gaps and Further Improvements

Given another development day, I would:

- Complete and test any missing API contract requirements, including idempotency-key header handling and expected HTTP status codes.
- Verify SELL and MARKET order support against the assessment requirements.
- Strengthen ledger immutability and add tests for concurrent cancellation and execution.
- Capture additional EXPLAIN plans for important indexed queries.
- Expand integration and concurrency testing and improve API documentation.

These items require verification against the final code and assessment requirements; they should not be considered completed merely because they are listed here.

## 12. Scaling to 100× Load

For a workload approaching 5,000 market ticks per second and one million orders per day, I would:

- Batch market-tick writes where appropriate and move market-data ingestion to a dedicated queue or streaming pipeline.
- Scale queue workers horizontally and monitor retry rates, queue depth, and processing latency.
- Partition or archive historical market ticks based on measured query and storage requirements.
- Review slow queries and index effectiveness using representative production-like data.
- Consider read replicas for suitable read-heavy endpoints and expand caching where stale data is acceptable.
- Add load testing, database contention metrics, and monitoring before selecting further scaling changes.
