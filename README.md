# Trading Order System

A Laravel-based trading order API demonstrating order placement, wallet reservations, ledger accounting, idempotency, queued execution, cancellation, and market tick processing.

## Tech Stack

- **Framework:** Laravel 13
- **Language:** PHP 8.5
- **Database:** MySQL 8
- **Cache and queue infrastructure:** Redis
- **Authentication:** Laravel Sanctum
- **Testing:** PHPUnit
- **Code quality:** Laravel Pint and PHPStan
- **API documentation:** OpenAPI 3.1

## Features

### Authentication and API

- Token-based authentication using Laravel Sanctum.
- Protected endpoints with user ownership checks.
- Rate limiting for login and order placement.
- Consistent JSON error responses for documented application errors.
- OpenAPI specification for API consumers.

### Order Management

- BUY and SELL order support.
- LIMIT and MARKET order support.
- Idempotent order placement using the `Idempotency-Key` request header.
- Request-hash validation when an idempotency key is reused.
- HTTP `202 Accepted` for accepted orders.
- Cursor-based order pagination with status and asset filters.
- Order details with associated execution records.
- Order cancellation with reservation release.
- Expiry handling for eligible pending orders.

### Financial Correctness

- Decimal database columns and BCMath arithmetic for financial calculations.
- Database transactions and row locking for wallet operations.
- Wallet balance reservation and release.
- Ledger entries for financial movements.
- Duplicate-execution protection.
- Ledger reconciliation support.
- Database constraints and indexes for important order operations.

### Market Data

- Market tick recording and Redis-based latest prices.
- Cache invalidation for market summary updates.
- Market summary for tradable assets.
- Latest prices and 24-hour price changes.
- Filled-order counts over the last 24 hours.
- Top five assets by notional trading volume.
- Configurable market tick simulation with batched inserts.

## Requirements

- PHP 8.3 or later, with the required PHP extensions.
- Composer
- Docker Desktop
- MySQL 8
- Redis 7
- BCMath PHP extension

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

Configure the database and Redis connection in `.env`:

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

Ensure the Predis package is installed when using `REDIS_CLIENT=predis`. These credentials are for local development only; use appropriate secrets and credentials in other environments.

### 3. Start MySQL and Redis

```bash
docker compose up -d
docker compose ps
```

Wait until both services are healthy before continuing.

### 4. Run migrations and seed data

```bash
php artisan migrate --seed
```

**Warning:** `php artisan migrate:fresh --seed` drops existing application tables and recreates them. Use it only when you intentionally want to reset the database.

### 5. Start the application

In one terminal:

```bash
php artisan serve
```

In another terminal:

```bash
php artisan queue:work
```

### 6. Run the market simulator

Generate market ticks at the default rate:

```bash
php artisan market:simulate
```

Specify a tick rate and duration:

```bash
php artisan market:simulate --rate=50 --duration=60
```

The default rate is 50 ticks per second across all tradable assets. A duration of `0` runs continuously until the process is stopped.

For ongoing environments, configure the simulator and queue workers using the appropriate process supervisor or deployment setup.

## Demo Users

The development seeder creates three demo users:

| Email | Password | Initial INR Balance |
|---|---|---:|
| `trader1@example.com` | `password` | ₹10,000 |
| `trader2@example.com` | `password` | ₹25,000 |
| `trader3@example.com` | `password` | ₹50,000 |

### Seeded Development API Tokens

| User | Bearer Token |
|---|---|
| Trader 1 | `assessment-trader1-token-2026` |
| Trader 2 | `assessment-trader2-token-2026` |
| Trader 3 | `assessment-trader3-token-2026` |

**Note:** These credentials and tokens are intended for local development and assessment testing only. Do not use them in production.


The database seeder provides development data for testing. Refer to `database/seeders/TradingSeeder.php` for the current demo users, initial balances, and seeded assets.

Use the login endpoint to obtain a bearer token. Any seeded credentials or tokens should be restricted to local assessment use and must not be used in production.

## API Documentation

**OpenAPI specification:** [`openapi.yaml`](openapi.yaml)

Base URL:

```text
http://127.0.0.1:8000/api/v1
```

### Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/login` | Authenticate and obtain a bearer token |
| POST | `/logout` | Revoke the current access token |
| POST | `/orders` | Place an order |
| GET | `/orders` | List orders using cursor pagination |
| GET | `/orders/{order}` | Retrieve order details and executions |
| DELETE | `/orders/{order}` | Cancel an eligible order |
| GET | `/wallet` | Retrieve wallet balances and positions |
| GET | `/market/summary` | Retrieve market summary metrics |

Protected endpoints require a bearer token:

```http
Authorization: Bearer YOUR_API_TOKEN
Accept: application/json
```

### Example: Login

```http
POST /api/v1/login
Content-Type: application/json
Accept: application/json
```

```json
{
  "email": "trader1@example.com",
  "password": "password"
}
```

Use the credentials configured by the development seeder.

### Example: Place a BUY LIMIT order

```http
POST /api/v1/orders
Content-Type: application/json
Accept: application/json
Authorization: Bearer YOUR_API_TOKEN
Idempotency-Key: demo-buy-001
```

```json
{
  "asset_id": 1,
  "side": "BUY",
  "type": "LIMIT",
  "quantity": "2.00000000",
  "limit_price": "100.00000000"
}
```

The idempotency key is supplied in the HTTP header, not the JSON body. Repeating a request with the same key and identical details should not create a duplicate order. Reusing that key with different details is rejected.

### Order listing and filtering

The order listing endpoint supports cursor-based pagination.

Example:

```http
GET /api/v1/orders?per_page=20&status=PENDING&asset_id=1
```

Use the cursor returned by the previous response to retrieve the next page. The endpoint returns only orders belonging to the authenticated user.

### Market summary

```http
GET /api/v1/market/summary
Authorization: Bearer YOUR_API_TOKEN
Accept: application/json
```

The summary includes the reporting period, filled-order count, top five assets by notional volume, and tradable asset price information.

## Financial Correctness and Concurrency

The implementation uses transactions, row locking, decimal arithmetic, and database constraints to protect important financial operations.

Order execution and cancellation must preserve reservation consistency and prevent duplicate financial movements. The automated tests cover selected duplicate-execution, reservation-release, idempotency, and concurrent-buy-order scenarios.

The reconciliation command can be used to check wallet ledger consistency:

```bash
php artisan list
```

Refer to the registered `ledger:reconcile` command for its available options.

## Running Tests

Run the complete test suite:

```bash
php artisan test
```

Run Laravel Pint in check mode:

```bash
./vendor/bin/pint --test
```

Run PHPStan:

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

Use a dedicated test database configured for the test environment. Confirm it is safe to reset before running database-backed tests.

### Latest verified local results

- **PHPUnit:** 36 tests passed, 142 assertions.
- **PHPStan:** No errors.
- **Laravel Pint:** 97 files passed.

These results reflect the latest test and code-quality outputs shared during development. Rerun all checks after further code changes.

## Database and Engineering Decisions

See [`DECISIONS.md`](DECISIONS.md) for the reasoning behind decimal precision, idempotency, transactional wallet operations, ledger accounting, database indexes, and MySQL `EXPLAIN` results.

## Security Notes

- Keep credentials and secrets in environment variables.
- Never commit production secrets or real API tokens.
- Restrict seeded credentials to local development.
- Enforce authentication and ownership checks for private resources.
- Use appropriate rate limits and request validation.
- Configure queue workers, Redis, database credentials, and logging appropriately for deployment.
- Review the migration and database constraints before deploying to a production database.

## Project Status

The project includes order placement, execution, cancellation, wallet accounting, market data, API documentation, and automated tests.

The local test suite, PHPStan, and Laravel Pint have passed in the latest reported run. Review the GitHub Actions workflow and remaining assessment requirements before treating the project as fully submission-ready.

## AI Tools Disclosure

I used ChatGPT as an AI assistance tool during development for understanding requirements, discussing implementation approaches, debugging issues, preparing test ideas, and improving documentation.

I reviewed the suggested changes, ran the automated tests and code quality checks, and validated the OpenAPI specification. The final implementation and its limitations are documented in this repository.

## Time Spent

I spent approximately 15 - 18 hours on this assessment.

Most of the time went into implementing the order placement and execution flow, handling wallet balances and concurrent requests, and testing order cancellation and failure scenarios. I also worked on the market simulator, Redis caching, API responses, and database indexes.

The remaining time was spent writing and validating the API documentation, updating the README and DECISIONS.md, fixing issues found during testing, and checking that the CI workflow passed.
