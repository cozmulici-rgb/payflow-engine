# Payflow Engine

High-volume financial transaction processing platform built on PHP 8.4 / Laravel 11. Handles the full payment lifecycle — merchant onboarding, transaction ingestion, authorization, ledger posting, capture/refund, webhooks, and settlement batching.

## Architecture

```
payflow-engine/
├── apps/
│   └── api/          # Laravel 11 + Octane (Swoole) HTTP API
├── modules/
│   ├── Audit/        # Immutable audit log
│   ├── FXCrossBorder/# FX rate locking
│   ├── Ledger/       # Double-entry bookkeeping
│   ├── MerchantManagement/
│   ├── PaymentProcessing/
│   ├── Settlement/   # Batch generation
│   └── Shared/       # Value objects, contracts
└── platform/         # Local Docker services
```

Each module follows a layered DDD structure: `Domain` → `Application` → `Infrastructure` → `Interfaces`.

## API Surface

| Method | Path | Description |
|--------|------|-------------|
| GET | `/internal/health` | Liveness probe |
| GET | `/internal/ready` | Readiness probe |
| POST | `/internal/v1/merchants` | Create merchant |
| POST | `/internal/v1/merchants/credentials` | Issue API key |
| POST | `/v1/transactions` | Create transaction |
| GET | `/v1/transactions/{id}` | Get transaction status |
| POST | `/v1/transactions/{id}/capture` | Manual capture |
| POST | `/v1/transactions/{id}/refund` | Refund |
| POST | `/v1/webhook-endpoints` | Register webhook |

Internal routes require an `Operator-Secret` header. Merchant routes use API key authentication.

## Local Development

**Start services:**

```bash
cd platform
docker compose up -d
```

Services: MySQL 8 (`3306`), Redis 7 (`6379`), Kafka 3 (`9092`).

**Configure the API:**

```bash
cd apps/api
cp .env.example .env
php artisan key:generate
php artisan migrate
```

**Run with Octane (production-like):**

```bash
php artisan octane:start
```

**Or the standard dev server:**

```bash
php artisan serve
```

## Testing

```bash
cd apps/api

# PHPUnit (unit + feature + integration)
php artisan test

# Behat scenarios
composer behat

# Lint
composer lint
```

Test coverage spans six phases:

| Phase | Scope |
|-------|-------|
| 1 | Platform bootstrap, merchant access |
| 2 | Transaction ingestion, idempotency |
| 3 | Authorization worker, processor routing |
| 4 | Ledger posting, chart of accounts |
| 5 | Capture, refund, webhook dispatch |
| 6 | Settlement batch generation |

## Key Dependencies

| Package | Purpose |
|---------|---------|
| `laravel/framework ^11` | Application framework |
| `laravel/octane ^2.5` | Swoole-powered server |
| `mateusjunges/laravel-kafka ^1.14` | Kafka producer/consumer |
| `predis/predis ^2.3` | Redis (cache, idempotency) |
| `sanchescom/laravel-clickhouse ^1.2` | Analytics store |
| `ext-bcmath` | Precise monetary arithmetic |
| `ext-rdkafka` | Native Kafka bindings |

## Environment Variables

See `apps/api/.env.example` for the full reference. Key groups:

- **Database** — `DB_*` (MySQL/Aurora)
- **Cache/Queue** — `REDIS_*`, `QUEUE_CONNECTION=kafka`
- **Kafka topics** — `KAFKA_TOPIC_*`
- **ClickHouse** — `CLICKHOUSE_*`
- **FX** — `FX_LOCK_TTL_SECONDS`
- **Settlement** — `SETTLEMENT_ARTIFACT_DISK`, `SETTLEMENT_ARTIFACT_BUCKET`
- **AWS** — `AWS_*` (S3 for settlement artifacts, ca-central-1 by default)

## Database Schema

Core tables (all created by the bundled migrations):

- `merchants`, `api_credentials` — merchant identity
- `transactions`, `transaction_status_history`, `idempotency_records` — payment lifecycle
- `accounts`, `journal_entries`, `ledger_entries` — double-entry ledger
- `fx_rate_locks` — FX snapshot at authorization time
- `webhook_endpoints`, `webhook_deliveries` — outbound webhooks
- `settlement_batches`, `settlement_items` — settlement
- `audit_log`, `processed_events` — auditability and event deduplication
