# Trading Order System

A Laravel-based trading order API demonstrating order placement, wallet reservations, ledger accounting, idempotency, queued execution, cancellation, and market tick processing.

## Tech Stack

- **Framework:** Laravel 13
- **Language:** PHP 8.5
- **Database:** MySQL 8
- **Cache / Queue infrastructure:** Redis
- **Authentication:** Laravel Sanctum
- **Testing:** PHPUnit
- **Code quality:** Laravel Pint and PHPStan

## Features

### Implemented

- Token-based API authentication.
- User-specific wallet balances.
- BUY LIMIT order placement and wallet reservation.
- User-scoped idempotency keys with request-hash validation.
- Database transactions and row locking for wallet operations.
- Reservation and release ledger entries.
- Queued order execution with duplicate-execution protection.
- Failed-order handling and reservation release.
- Order listing, order details, and cancellation.
- Market tick recording and Redis cache invalidation.
- Database constraints and indexes for key order operations.
- Market summary endpoint for tradable assets and latest prices.

### Known limitations

The following features require further implementation or verification before claiming full assessment compliance:

- SELL order placement is not supported by the current order service.
- MARKET orders are explicitly rejected until pricing and reservation logic is implemented.
- Order creation currently returns HTTP `201 Created`; change this if the assessment requires `202 Accepted`.
- The idempotency key is currently read from the JSON body as `idempotency_key`, not from the `Idempotency-Key` header.
- Order listing uses page-based pagination rather than cursor pagination.
- The market summary does not yet provide all advanced metrics, such as filled-order counts, top-five asset notional, and 24-hour price changes.
- Ledger immutability and all required concurrent cancellation-versus-execution race scenarios still require verification.

## Requirements

- PHP 8.3 or later
- Composer
- Docker Desktop
- MySQL 8
- Redis 7

## Local Setup

### 1. Install dependencies

```bash
composer install
```

### 2. Configure environment variables

```bash
cp .env.example .env
php artisan key:generate
```

Update `.env` for the local Docker services:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=trading_order_system
DB_USERNAME=laravel
DB_PASSWORD=laravel_dev_password

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Make sure the project has the Predis package installed if `REDIS_CLIENT=predis` is used. Keep local development credentials out of production deployments.

### 3. Start MySQL and Redis

```bash
docker compose up -d
docker compose ps
```

Wait until both services are ready before continuing.

### 4. Run migrations and seed demo data

```bash
php artisan migrate --seed
```

Use `php artisan migrate:fresh --seed` only when you intentionally want to drop and recreate all application tables. It deletes existing database data.

### 5. Start the application

In one terminal:

```bash
php artisan serve
```

In another terminal, run the queue worker:

```bash
php artisan queue:work
```

The market tick simulator can be invoked with:

```bash
php artisan market:simulate-ticks
```

The simulator currently runs when invoked; continuous execution requires an appropriate scheduler or repeated invocation.

## Demo Users

The development seeder creates three demo users:

| Email | Password | Initial INR balance |
|---|---|---:|
| `trader1@example.com` | `password` | ₹10,000 |
| `trader2@example.com` | `password` | ₹25,000 |
| `trader3@example.com` | `password` | ₹50,000 |

Seeded development API tokens:

| User | Bearer token |
|---|---|
| Trader 1 | `assessment-trader1-token-2026` |
| Trader 2 | `assessment-trader2-token-2026` |
| Trader 3 | `assessment-trader3-token-2026` |

These tokens and passwords are for local assessment testing only. Do not use them in production.

## API Endpoints

Base URL: `http://127.0.0.1:8000/api/v1`

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/login` | Authenticate and obtain an API token |
| POST | `/logout` | Revoke the current token |
| GET | `/wallet` | View the authenticated user's wallets |
| GET | `/market/summary` | Retrieve market summary data |
| POST | `/orders` | Submit an order |
| GET | `/orders` | List the authenticated user's orders |
| GET | `/orders/{order}` | Retrieve an order owned by the user |
| DELETE | `/orders/{order}` | Cancel an eligible order |

Protected endpoints require a Bearer token:

```http
Authorization: Bearer YOUR_API_TOKEN
Accept: application/json
```

### Example: Submit a BUY LIMIT order

```http
POST /api/v1/orders
Content-Type: application/json
Authorization: Bearer assessment-trader1-token-2026
```

```json
{
  "asset_id": 1,
  "side": "BUY",
  "type": "LIMIT",
  "quantity": "2.00000000",
  "limit_price": "100.00000000",
  "idempotency_key": "demo-buy-001"
}
```

The current implementation expects `idempotency_key` in the request body. Header-based idempotency must be implemented if required by the assessment.

## Financial Correctness

- Monetary values and quantities use decimal database columns.
- BCMath is used for financial arithmetic.
- Wallet operations use database transactions and row locks.
- Wallet reservation and release operations create ledger entries.
- A database uniqueness constraint prevents duplicate idempotency keys for the same user.
- Execution processing has a unique order constraint to prevent duplicate executions.

These safeguards cover the implemented flows; they do not imply that every order type or every concurrency scenario is complete.

## Running Tests

Run the test suite:

```bash
php artisan test
```

Configure the test environment to use the dedicated MySQL test database, `trading_order_system_test`, rather than SQLite. Confirm the test database exists and is safe to reset before running database-backed tests.

Run code formatting checks:

```bash
./vendor/bin/pint --test
```

Run static analysis:

```bash
./vendor/bin/phpstan analyse
```

### Latest verified results

- PHPUnit: **26 tests passed, 100 assertions**
- PHPStan: **No errors**
- Laravel Pint: **Passed**

Re-run these commands after the final code changes and record the latest results.

## Database and Engineering Decisions

See [`DECISIONS.md`](DECISIONS.md) for the reasoning behind decimal precision, idempotency, transactional wallet operations, ledger accounting, database indexes, and the observed MySQL `EXPLAIN` results.

## Security Notes

- Use environment variables for credentials.
- Never commit production secrets or real API tokens.
- Restrict demo credentials and seeded tokens to local development.
- Apply authentication and ownership checks to private order and wallet resources.
- Verify authorization, rate limiting, validation, and production queue configuration before deployment.

## Project Status

This project demonstrates the core BUY LIMIT order workflow and several financial correctness protections. The known limitations above must be resolved or explicitly agreed as out of scope before describing the implementation as fully assessment-compliant.
