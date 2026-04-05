# Design Document: High-Volume Financial Transaction Processing Platform

---

## 1. Architecture Overview

The platform follows a **modular monolith** architecture deployed on AWS, with strict bounded-context separation enforced at the code level (Laravel modules/packages) rather than through network boundaries. This decision reflects the team size (8-10 engineers) and PHP/Laravel expertise: a modular monolith delivers bounded-context isolation without the operational overhead of microservices, while preserving the option to extract services later if organizational scaling demands it. The single deployable unit runs on Laravel Octane (RoadRunner) for high-throughput API handling, with queue workers scaled independently via separate process groups.

The data architecture enforces a hard OLTP/OLAP split. All transactional writes target Aurora MySQL, which serves as the system of record. A Kafka-based event pipeline streams domain events to ClickHouse for analytical workloads. Reports are generated twice daily from ClickHouse exclusively -- no analytical queries ever touch Aurora. Redis provides distributed locking (idempotency enforcement, rate lock management), configuration caching, and rate limiting. This separation ensures that reporting load spikes at 06:00 and 18:00 ET never degrade transaction processing performance.

Compliance shapes every architectural decision. PCI-DSS scope is minimized by delegating card tokenization to an external PCI-compliant vault -- the platform never sees raw PANs, only opaque tokens. All financial mutations flow through a double-entry ledger with append-only semantics, producing an immutable audit trail that satisfies both PCI-DSS logging requirements and FINTRAC record-keeping obligations. GDPR and PIPEDA data subject rights are handled through a dedicated PII isolation layer with field-level encryption and pseudonymization, enabling right-to-erasure without corrupting financial records.

---

## 2. Domain Model & Bounded Contexts

### 2.1 Bounded Contexts

The system is decomposed into 6 bounded contexts. Each context owns its data, exposes behavior through internal interfaces (PHP interfaces/contracts), and communicates with other contexts via domain events published to Kafka.

| Bounded Context | Responsibility | Key Entities | Owner Team Slice |
|----------------|---------------|--------------|-----------------|
| **Payment Processing** | Transaction lifecycle from ingestion through authorization/capture | Transaction, PaymentMethod, ProcessorRoute, FraudScreenResult | Core Payments (3-4 eng) |
| **Ledger** | Double-entry bookkeeping, balance tracking | LedgerEntry, Account, JournalEntry | Core Payments (shared) |
| **Settlement** | Batching, file generation, reconciliation | SettlementBatch, SettlementItem, ReconciliationRecord, Exception | Settlement (2 eng) |
| **FX & Cross-Border** | Rate locking, currency conversion, markup | FxRate, RateLock, ConversionRecord, CorrespondentRoute | Core Payments (shared) |
| **Merchant Management** | Onboarding, configuration, API keys, webhooks | Merchant, MerchantConfig, ApiCredential, WebhookEndpoint, FeeSchedule | Platform (2 eng) |
| **Reporting & Analytics** | OLAP pipeline, report generation, dashboards | ReportDefinition, ReportRun, AnalyticsEvent | Platform (shared) |

### 2.2 Transaction State Machine

```
                    +---> voided
                    |
pending ---> authorized ---> captured ---> settled
   |             |              |
   +---> failed  +---> failed   +---> refund_pending ---> refunded
                 |
                 +---> expired (rate lock timeout)
```

States are persisted as an enum column on the `transactions` table. Every state transition:
1. Validates the transition is legal (enforced by a `TransactionStateMachine` value object).
2. Creates a corresponding double-entry ledger entry.
3. Publishes a domain event (e.g., `TransactionAuthorized`, `TransactionCaptured`).
4. Records the transition in the `transaction_status_history` table with timestamp and actor.

### 2.3 Double-Entry Ledger Design

Every financial movement creates exactly two `ledger_entries` rows: one debit, one credit. The sum of all debits always equals the sum of all credits (enforced by database constraint on journal entries).

**Account hierarchy:**

| Account Type | Examples | Normal Balance |
|-------------|----------|----------------|
| Asset | merchant_receivable, processor_receivable, bank_settlement | Debit |
| Liability | merchant_payable, refund_reserve | Credit |
| Revenue | processing_fees, fx_markup | Credit |
| Expense | processor_costs, chargeback_losses | Debit |

**Example: Card Authorization + Capture flow:**
- Authorization: DEBIT processor_receivable / CREDIT merchant_payable (held amount)
- Capture: No ledger movement (confirms the hold)
- Settlement: DEBIT bank_settlement / CREDIT processor_receivable; DEBIT merchant_payable / CREDIT merchant_payout

### 2.4 Idempotency Strategy

Idempotency is enforced at two levels:

1. **API level:** The `idempotency_key` (provided by the caller or auto-generated) is stored in a UNIQUE index. On duplicate submission, the system returns the original response from a Redis cache (TTL: 24 hours) or re-fetches from the database.
2. **Worker level:** Each event consumed from Kafka is deduplicated by `(event_id, consumer_group)` stored in a `processed_events` table with a UNIQUE constraint. Workers are safe to retry.

---

## 3. Write Path (Transaction Processing)

### 3.1 Transaction Ingestion Flow

```
Merchant API Request
        |
        v
[1] Laravel Octane (RoadRunner) - API Controller
        |
        +-- Validate payload (amount, currency, merchant_id, payment_token)
        +-- Check idempotency_key in Redis -> if exists, return cached response
        +-- Verify merchant is active, supports currency, within rate limits
        |
        v
[2] Create Transaction (Aurora MySQL, status: pending)
        +-- Insert transaction row
        +-- Insert idempotency_key (UNIQUE constraint as safety net)
        +-- Write to transaction_status_history
        |
        v
[3] Dispatch ProcessTransaction job to Kafka (payment-commands topic)
        +-- Message includes transaction_id, idempotency_key
        +-- Producer uses acks=all for durability
        |
        v
[4] Return 202 Accepted with transaction_id and status: pending
        +-- Cache response in Redis keyed by idempotency_key (TTL: 24h)
```

**Latency budget for ingestion:** Target p95 < 500ms
- Payload validation: ~5ms
- Redis idempotency check: ~2ms
- Aurora write: ~15ms
- Kafka produce (acks=all): ~20ms
- HTTP overhead + serialization: ~10ms
- Total typical: ~52ms (well within budget)

### 3.2 Transaction Processing Worker

```
[5] ProcessTransaction Worker (Laravel Queue Worker, Kafka consumer)
        |
        +-- Deduplication check (processed_events table)
        |
        v
[6] FX Resolution (if cross-border)
        +-- Acquire rate lock from FX provider
        +-- Store RateLock (rate, expiry, source, markup)
        +-- Convert amount to settlement currency
        |
        v
[7] Fraud Screening
        +-- Call fraud service (internal rules engine or 3rd party)
        +-- If score > threshold: transition to failed, publish event, stop
        |
        v
[8] Processor Gateway
        +-- Select processor via routing rules (merchant config, BIN, currency)
        +-- Call processor API (authorization request)
        +-- Handle response: approved -> authorized, declined -> failed
        |
        v
[9] State Transition
        +-- Update transaction status in Aurora (optimistic lock via version column)
        +-- Create ledger entries (DEBIT processor_receivable / CREDIT merchant_payable)
        +-- Insert transaction_status_history record
        +-- All within a single database transaction
        |
        v
[10] Publish Domain Event to Kafka (transaction-events topic)
        +-- TransactionAuthorized or TransactionFailed
        +-- Consumers: webhook dispatcher, analytics pipeline, audit logger
```

### 3.3 Settlement Batch Flow

```
[Scheduled: per merchant/processor config, typically daily]

[11] Settlement Job
        +-- Query authorized+captured transactions for the batch window
        +-- Group by processor and settlement currency
        +-- Create SettlementBatch (status: open)
        |
        v
[12] Generate Settlement File
        +-- Format per processor specification (CSV, ISO 20022, proprietary)
        +-- Upload to S3 (encrypted, versioned bucket)
        +-- Transmit to processor via SFTP/API
        +-- SettlementBatch status -> submitted
        |
        v
[13] Reconciliation (triggered by processor response file)
        +-- Parse processor settlement confirmation
        +-- Match against internal batch items
        +-- Flag discrepancies as ReconciliationException
        +-- SettlementBatch status -> confirmed or exception
        |
        v
[14] Ledger Finalization
        +-- For confirmed items: DEBIT bank_settlement / CREDIT processor_receivable
        +-- Transaction status -> settled
        +-- Publish TransactionSettled events
```

### 3.4 Consistency Model

- **Write path:** Strong consistency via Aurora MySQL single-writer with `SERIALIZABLE` isolation for ledger entries and `READ COMMITTED` for transaction status updates. Ledger balance integrity is enforced at the database level through journal-entry constraints.
- **Read path (OLAP):** Eventual consistency. Events flow to ClickHouse with typical lag of 1-5 seconds. Reports use data as of a cutoff timestamp (e.g., 05:55 ET for the 06:00 run), so lag is irrelevant for batch reporting.
- **Read path (API status queries):** Read from Aurora read replica with a `max_replica_lag` check. If replica lag exceeds 1 second, queries fall back to the primary. This achieves p95 < 200ms for status queries without burdening the writer.

---

## 4. Data Model

### 4.1 transactions

The core transaction record. Denormalized for query performance on the OLTP side; the full normalized model lives in the ledger.

```sql
CREATE TABLE transactions (
    id CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUIDv7 for time-ordered inserts',
    merchant_id CHAR(36) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    type ENUM('authorization', 'capture', 'void', 'refund') NOT NULL,
    status ENUM('pending', 'authorized', 'captured', 'settled', 'failed', 'voided', 'expired', 'refund_pending', 'refunded') NOT NULL DEFAULT 'pending',
    amount DECIMAL(18,4) NOT NULL COMMENT 'Original amount in source currency',
    currency CHAR(3) NOT NULL COMMENT 'ISO 4217 source currency',
    settlement_amount DECIMAL(18,4) NULL COMMENT 'Amount in settlement currency after FX',
    settlement_currency CHAR(3) NULL COMMENT 'ISO 4217 settlement currency',
    payment_token VARCHAR(255) NOT NULL COMMENT 'Tokenized card/payment reference, never raw PAN',
    processor_id VARCHAR(50) NULL COMMENT 'Selected processor/acquirer identifier',
    processor_reference VARCHAR(255) NULL COMMENT 'Processor-assigned transaction ID',
    fraud_score DECIMAL(5,2) NULL,
    rate_lock_id CHAR(36) NULL COMMENT 'FK to fx_rate_locks if cross-border',
    parent_transaction_id CHAR(36) NULL COMMENT 'For refunds: references the original transaction',
    version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Optimistic locking',
    error_code VARCHAR(50) NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uk_idempotency (idempotency_key),
    INDEX idx_merchant_status (merchant_id, status),
    INDEX idx_merchant_created (merchant_id, created_at),
    INDEX idx_status_created (status, created_at),
    INDEX idx_processor_ref (processor_id, processor_reference),
    INDEX idx_parent (parent_transaction_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=COMPRESSED;
```

**Key decisions:**
- `CHAR(36)` for UUIDs: UUIDv7 is used for time-ordered primary keys, which dramatically improves InnoDB insert performance vs random UUIDv4 by maintaining B-tree locality.
- `DECIMAL(18,4)`: 4 decimal places supports sub-cent precision required for FX conversions and fee calculations. 18 total digits supports amounts up to 99,999,999,999,999.9999 -- sufficient for any single transaction.
- `DATETIME(6)`: Microsecond precision for accurate ordering and audit trail fidelity.
- `version` column: Optimistic concurrency control for state transitions, avoiding row-level lock contention under concurrent processor callbacks.

### 4.2 ledger_entries

Append-only double-entry ledger. No UPDATE or DELETE operations are ever performed on this table.

```sql
CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id CHAR(36) NOT NULL COMMENT 'Groups the debit+credit pair',
    account_id CHAR(36) NOT NULL,
    entry_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(18,4) NOT NULL COMMENT 'Always positive; direction indicated by entry_type',
    currency CHAR(3) NOT NULL,
    transaction_id CHAR(36) NULL COMMENT 'Source transaction, if applicable',
    settlement_batch_id CHAR(36) NULL COMMENT 'Source batch, if applicable',
    description VARCHAR(500) NOT NULL,
    effective_date DATE NOT NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_journal (journal_entry_id),
    INDEX idx_account_date (account_id, effective_date),
    INDEX idx_transaction (transaction_id),
    INDEX idx_settlement_batch (settlement_batch_id),
    INDEX idx_effective_date (effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key decisions:**
- `BIGINT AUTO_INCREMENT` primary key: Ledger entries are high-volume (at least 2x transaction count). Auto-increment provides optimal insert performance and natural ordering.
- `journal_entry_id`: Links the debit and credit sides. Application logic enforces that every journal_entry_id has exactly one debit and one credit of equal amounts.
- No `updated_at`: This table is append-only. Corrections are made by posting reversing entries, never by modifying existing rows.

### 4.3 settlement_batches

```sql
CREATE TABLE settlement_batches (
    id CHAR(36) NOT NULL PRIMARY KEY,
    processor_id VARCHAR(50) NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('open', 'submitted', 'confirmed', 'exception', 'reconciled') NOT NULL DEFAULT 'open',
    batch_date DATE NOT NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    settlement_file_s3_key VARCHAR(500) NULL,
    processor_confirmation_ref VARCHAR(255) NULL,
    exception_count INT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at DATETIME(6) NULL,
    confirmed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    INDEX idx_processor_date (processor_id, batch_date),
    INDEX idx_status (status),
    INDEX idx_batch_date (batch_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.4 fx_rate_locks

```sql
CREATE TABLE fx_rate_locks (
    id CHAR(36) NOT NULL PRIMARY KEY,
    source_currency CHAR(3) NOT NULL,
    target_currency CHAR(3) NOT NULL,
    rate DECIMAL(18,8) NOT NULL COMMENT '8 decimal places for FX precision',
    markup_bps DECIMAL(8,2) NOT NULL COMMENT 'Markup in basis points',
    effective_rate DECIMAL(18,8) NOT NULL COMMENT 'Rate after markup applied',
    provider VARCHAR(50) NOT NULL COMMENT 'Rate source (e.g., CurrencyLayer)',
    locked_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    status ENUM('active', 'used', 'expired') NOT NULL DEFAULT 'active',
    transaction_id CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_currencies (source_currency, target_currency),
    INDEX idx_expires (expires_at),
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key decisions:**
- `DECIMAL(18,8)` for FX rates: 8 decimal places is standard for interbank FX rate precision.
- `markup_bps` stored separately from rate: Ensures transparency and auditability of markup vs. market rate.
- `expires_at` index: Enables efficient cleanup of expired locks and validation during capture.

### 4.5 audit_log

Immutable audit trail for compliance. Separate from the application log -- this is a structured, queryable record of all security-relevant and financial operations.

```sql
CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL COMMENT 'e.g., transaction.created, user.login, config.changed',
    actor_type ENUM('user', 'system', 'api_key', 'scheduler') NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    resource_type VARCHAR(100) NOT NULL,
    resource_id VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    context JSON NOT NULL COMMENT 'Request metadata: IP, user agent, correlation ID',
    changes JSON NULL COMMENT 'Before/after for mutations; NULL for reads',
    ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6',
    correlation_id CHAR(36) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    INDEX idx_event_type (event_type, created_at),
    INDEX idx_actor (actor_type, actor_id, created_at),
    INDEX idx_resource (resource_type, resource_id, created_at),
    INDEX idx_correlation (correlation_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ROW_FORMAT=COMPRESSED;
```

**Key decisions:**
- Append-only, no UPDATE/DELETE (enforced at application layer and database user permissions).
- `JSON` columns for flexible context/changes capture without schema migration per new event type.
- `ROW_FORMAT=COMPRESSED`: Audit log grows indefinitely; compression reduces storage cost. Rows older than 7 years are archived to S3 (FINTRAC requirement: 5-year retention + 2-year buffer).
- Separate database user for the audit_log table with INSERT-only privileges; application cannot modify history.

---

## 5. Technology Stack

| Component | Technology | Rationale | Alternatives Considered |
|-----------|-----------|-----------|------------------------|
| **API Runtime** | PHP 8.4 + Laravel 11 + Octane (RoadRunner) | Team expertise; Octane eliminates per-request bootstrap overhead, achieving ~2-5x throughput improvement over php-fpm. RoadRunner chosen over Swoole for simpler debugging and broader PHP ecosystem compatibility. | Swoole (higher raw throughput but harder to debug, extension conflicts), Go sidecar (team skill gap) |
| **OLTP Database** | Aurora MySQL 3.x (MySQL 8.0-compatible) | Multi-AZ writer with up to 15 read replicas. Storage auto-scales. Handles 60 TPS write workload comfortably. | Aurora PostgreSQL (team has less experience), RDS MySQL (lacks Aurora storage engine benefits) |
| **OLAP Database** | ClickHouse (self-hosted on EC2 or ClickHouse Cloud) | Column-oriented, compression ratios of 10-20x, sub-second aggregation queries over billions of rows. Ideal for the 2x-daily report generation and dashboard queries. | Amazon Redshift (higher cost, less control), Apache Druid (more operational complexity), Athena (latency too high for dashboards) |
| **Message Broker** | Apache Kafka (Amazon MSK) | Durable, ordered, replayable event log. Critical for: (a) guaranteed delivery of financial events, (b) OLTP-to-OLAP pipeline, (c) event replay for reconciliation/debugging. MSK is fully managed, reducing ops burden. | SQS (no ordering guarantees without FIFO, no replay), RabbitMQ (not durable log, harder to replay, no native AWS managed option at scale) |
| **Cache / Locks** | Redis 7.x (ElastiCache) | Sub-ms latency for idempotency checks, rate lock TTLs, config caching, distributed locks (Redlock). | Memcached (no data structures, no persistence), DynamoDB (higher latency for lock operations) |
| **Object Storage** | S3 (versioned, encrypted) | Settlement files, report exports, audit log archives. Lifecycle policies for tiered storage. | EFS (unnecessary for file-based workloads) |
| **Queue Workers** | Laravel Queue (Kafka driver via `mateusjunges/laravel-kafka`) | Familiar API for team. Workers run as separate RoadRunner processes, scaled independently from API tier. | Custom Kafka consumers (more control but more code to maintain) |
| **Frontend** | Vue.js 3 + Nuxt 3 | Internal dashboards for ops, merchant management, reconciliation workflow. SSR for initial load, SPA for interactive reporting. | React/Next (team preference is Vue) |
| **Infrastructure** | Terraform + AWS | EC2 (API + workers), Aurora, MSK, ElastiCache, S3, CloudWatch, ALB, WAF. IaC for reproducible environments. | CloudFormation (less ecosystem, more verbose), Pulumi (team unfamiliar) |
| **Observability** | CloudWatch Metrics + Logs, AWS X-Ray, Grafana | CloudWatch for native AWS integration; X-Ray for distributed tracing; Grafana dashboards for ClickHouse query visualization and operational monitoring. | Datadog (cost at this scale), ELK (operational overhead) |
| **CI/CD** | GitHub Actions | Build, test, deploy pipelines. Environment promotion: dev -> staging -> production. Blue-green deploys via ALB target group switching. | Jenkins (overhead), CircleCI (cost) |

### CQRS Evaluation

The system uses **partial CQRS**: the write model (Aurora) and read model (ClickHouse) are separate stores with different schemas, but the application does not implement full command/query segregation at the code level. Instead:

- **Writes** always go to Aurora via Laravel Eloquent models within the bounded contexts.
- **Transactional reads** (status queries, merchant config lookups) go to Aurora read replicas.
- **Analytical reads** (reports, dashboards, aggregate queries) go to ClickHouse.

This gives the benefits of CQRS (independent scaling, optimized read models) without the code complexity of separate command/query stacks -- appropriate for the team size and PHP/Laravel stack.

### ClickHouse Schema Design

Reports query denormalized materialized views in ClickHouse:

```sql
-- ClickHouse: Main fact table
CREATE TABLE transaction_facts (
    event_date Date,
    event_time DateTime64(6),
    transaction_id String,
    merchant_id String,
    type LowCardinality(String),
    status LowCardinality(String),
    amount Decimal(18, 4),
    currency LowCardinality(String),
    settlement_amount Decimal(18, 4),
    settlement_currency LowCardinality(String),
    processor_id LowCardinality(String),
    fraud_score Decimal(5, 2),
    fx_rate Decimal(18, 8),
    fx_markup_bps Decimal(8, 2),
    processing_fee Decimal(18, 4),
    created_at DateTime64(6)
) ENGINE = MergeTree()
PARTITION BY toYYYYMM(event_date)
ORDER BY (merchant_id, event_date, transaction_id)
TTL event_date + INTERVAL 2 YEAR;

-- Pre-aggregated daily summary for fast dashboard queries
CREATE MATERIALIZED VIEW daily_merchant_summary
ENGINE = SummingMergeTree()
PARTITION BY toYYYYMM(event_date)
ORDER BY (merchant_id, event_date, currency, status)
AS SELECT
    event_date,
    merchant_id,
    currency,
    status,
    count() AS transaction_count,
    sum(amount) AS total_amount,
    sum(processing_fee) AS total_fees
FROM transaction_facts
GROUP BY event_date, merchant_id, currency, status;
```

**Report generation strategy:** Scheduled Laravel commands at 06:00 and 18:00 ET trigger report generation jobs that query ClickHouse materialized views, render results to CSV/PDF, upload to S3, and notify via webhook/email. The 2-hour window is generous: typical report generation for 500K transactions completes in 10-15 minutes against pre-aggregated ClickHouse views.

---

## 6. Compliance & Security

### 6.1 PCI-DSS Scope Minimization

The platform **never handles raw card numbers (PANs)**. All card data is tokenized by an external PCI-compliant vault before reaching the platform.

| PCI-DSS Requirement | Platform Approach |
|---------------------|-------------------|
| Req 3: Protect stored cardholder data | No cardholder data stored. Only opaque tokens. Platform is SAQ-D eligible for reduced scope. |
| Req 4: Encrypt transmission | TLS 1.2+ enforced on all endpoints. Internal service communication uses TLS mutual auth. |
| Req 6: Secure development | SAST in CI/CD pipeline, dependency scanning, code review requirements. |
| Req 7: Restrict access | RBAC with principle of least privilege. Database credentials rotated via AWS Secrets Manager. |
| Req 8: Authenticate access | MFA for all internal system access. API keys with HMAC signature for merchant access. |
| Req 10: Track and monitor | Immutable audit_log table. CloudWatch log streaming. Tamper-evident log chain (hash chaining). |
| Req 11: Regular testing | Quarterly vulnerability scans, annual penetration test. |

**Network segmentation:** The VPC is segmented into tiers:
- Public subnet: ALB, WAF
- Application subnet: API servers, workers (no direct internet access; NAT gateway for outbound)
- Data subnet: Aurora, ElastiCache, ClickHouse (no internet access; accessible only from application subnet)
- CDE subnet (if self-hosted tokenization is ever needed): Isolated segment with dedicated security group rules

### 6.2 GDPR & PIPEDA

| Obligation | Implementation |
|-----------|---------------|
| Data subject access (GDPR Art. 15, PIPEDA Principle 9) | API endpoint exports all PII for a given data subject. PII is identifiable via `data_subject_id` FK on relevant tables. |
| Right to erasure (GDPR Art. 17) | PII fields are encrypted with per-subject keys. Erasure = deletion of the encryption key (crypto-shredding), rendering PII unrecoverable while preserving anonymized financial records for regulatory retention. |
| Data minimization | Only PII strictly necessary for processing is collected. Structured data classification (PII, financial, operational) in schema annotations. |
| Breach notification | Automated detection via CloudWatch anomaly detection. Incident runbook targets notification within 72 hours (GDPR) / "as soon as feasible" (PIPEDA). |
| Data processing agreements | Processor contracts required for all third-party integrations handling PII (payment processors, FX providers). Tracked in merchant config. |

**Crypto-shredding implementation:** PII fields (merchant contact info, data subject identifiers on cross-border transactions) are encrypted at the application level using AES-256-GCM with per-subject keys stored in AWS KMS. To exercise right-to-erasure, the subject's KMS key is scheduled for deletion. Financial records (amounts, dates, anonymized transaction IDs) are retained for the full regulatory retention period.

### 6.3 FINTRAC / AML

| Requirement | Implementation |
|------------|---------------|
| Large transaction reporting (CAD $10,000+) | Automated detection rule in the processing pipeline. Transactions meeting the threshold are flagged and a Suspicious Transaction Report (STR) or Large Cash Transaction Report (LCTR) is queued for compliance officer review. |
| Record keeping (5 years) | All transaction records, ledger entries, and audit logs retained for minimum 5 years (7 years in practice, with archival to S3 Glacier after 2 years active). |
| Sanctions screening | Integration point in the fraud screening step. Cross-reference against OFAC, Canadian sanctions list before authorization. |
| Know Your Customer (KYC) | Merchant onboarding includes KYC verification. Stored in merchant config with verification status and supporting document references (S3). |

### 6.4 Encryption & Key Management

| Data State | Method | Details |
|-----------|--------|---------|
| At rest (Aurora) | AES-256 | Aurora storage encryption enabled (AWS-managed key or CMK). |
| At rest (S3) | AES-256 | SSE-S3 or SSE-KMS for settlement files and reports. |
| At rest (PII fields) | AES-256-GCM | Application-level encryption via per-subject KMS keys (enables crypto-shredding). |
| In transit | TLS 1.2+ | Enforced on ALB, internal service calls, database connections. |
| Secrets | AWS Secrets Manager | Database credentials, API keys, processor credentials. Auto-rotation enabled. |

### 6.5 Audit Trail Design

The `audit_log` table (defined in Section 4.5) captures:
- All financial state transitions (transaction lifecycle events)
- All authentication events (login, logout, failed attempts)
- All configuration changes (merchant config, fee schedules, routing rules)
- All data access events for PII (read access logging for GDPR accountability)
- All administrative actions (user management, role changes)

Audit log integrity is ensured by:
1. INSERT-only database user for the application.
2. Hash chaining: Each audit_log entry includes a SHA-256 hash of the previous entry, enabling tamper detection.
3. Periodic audit log snapshots exported to S3 with Object Lock (WORM) for immutability.

---

## 7. Failure Modes & Operational Concerns

### Failure Mode 1: Processor Gateway Timeout / Failure

**Scenario:** The payment processor does not respond within the timeout window (e.g., 10 seconds), or returns a 5xx error during authorization.

**Impact:** Transaction stuck in `pending` state. Merchant does not know if the authorization succeeded at the processor.

**Mitigation:**
- **Circuit breaker** per processor (Symfony/PHP circuit breaker library, or custom implementation). States: closed -> open (after 5 failures in 60s) -> half-open (test single request after 30s cooldown).
- **Timeout with idempotent retry:** Processor calls use the same `idempotency_key` so retries are safe. 3 retries with exponential backoff (1s, 3s, 9s).
- **Status inquiry fallback:** If retries exhaust, fire a status inquiry to the processor to determine if the auth was actually processed. Update internal state accordingly.
- **Dead letter + alert:** If status inquiry also fails, move to dead letter topic, transition transaction to `failed` with `error_code: processor_timeout`, alert on-call.
- **Merchant notification:** Webhook fires with `status: failed` and `error_code: processor_timeout`. Merchant can retry with a new idempotency key.

**Runbook:** On-call receives alert "Processor X circuit breaker OPEN". Steps: (1) Check processor status page. (2) If processor confirmed down, pause routing to that processor and activate fallback processor if configured. (3) Monitor circuit breaker half-open recovery. (4) When restored, replay dead-lettered messages.

### Failure Mode 2: OLTP-to-OLAP Pipeline Lag / Failure

**Scenario:** Kafka consumer writing to ClickHouse falls behind or crashes. ClickHouse data becomes stale.

**Impact:** Reports generated at 06:00/18:00 contain stale data. Dashboard shows outdated metrics.

**Mitigation:**
- **Consumer lag monitoring:** CloudWatch alarm on Kafka consumer group lag. Alert if lag exceeds 10,000 messages (approximately 15 minutes of data at average throughput).
- **Graceful degradation:** Transaction processing is completely unaffected -- the pipeline is asynchronous and the OLTP path has zero dependency on ClickHouse.
- **Report generation pre-check:** Before generating reports, the job queries the latest event timestamp in ClickHouse and compares to Aurora. If the gap exceeds 30 minutes, the report is delayed and an alert fires.
- **Replay from Kafka:** Kafka retains events for 7 days. If the ClickHouse consumer crashes, it resumes from its last committed offset. If ClickHouse data is corrupted, the consumer group offset can be reset and the full 7-day window replayed (ClickHouse `ReplacingMergeTree` handles deduplication).
- **Separate ClickHouse consumer group per workload:** Dashboard queries and report generation use the same ClickHouse tables but are served by independent consumers, preventing one from blocking the other.

**Runbook:** Alert "ClickHouse consumer lag > threshold". Steps: (1) Check consumer process health (`systemctl status clickhouse-consumer`). (2) Check ClickHouse health (`SELECT 1` via clickhouse-client). (3) If consumer crashed, restart. If ClickHouse is down, investigate and restore. (4) Consumer auto-resumes from last offset.

### Failure Mode 3: Settlement Reconciliation Discrepancy

**Scenario:** The processor's settlement confirmation file does not match the internal ledger. Common causes: timing differences (transaction captured after batch cutoff), amount discrepancies (FX rate drift between authorization and settlement), or duplicate/missing entries.

**Impact:** Financial records are inconsistent. Potential revenue leakage or overpayment to merchants.

**Mitigation:**
- **Automated matching engine:** The reconciliation job performs three-way matching: (a) internal ledger entry, (b) settlement batch item, (c) processor confirmation line item. Match on processor_reference + amount + currency.
- **Exception classification:** Discrepancies are auto-classified: `timing` (appeared in next batch -- auto-resolve), `amount_mismatch` (flag for review, threshold: > $0.01), `missing_internal` (in processor file but not in our batch -- critical alert), `missing_external` (in our batch but not in processor file -- resubmit or investigate).
- **Automated resolution for timing differences:** If a `missing_external` item appears in the next day's processor file, auto-link and resolve.
- **Manual resolution workflow:** Dashboard for finance team to review, annotate, and resolve exceptions. All resolutions are audit-logged.
- **Daily reconciliation report:** Part of the 06:00 report run. Includes: total matched, total exceptions, exceptions by category, aging of unresolved exceptions.
- **Financial controls:** If unresolved exceptions exceed a threshold (e.g., $10,000 or 50 items), settlement payouts to affected merchants are held pending review.

**Runbook:** Alert "Reconciliation exceptions > threshold for batch X". Steps: (1) Review the reconciliation report in the dashboard. (2) For `amount_mismatch`: compare internal FX rate vs. processor settlement rate. (3) For `missing_internal`: check if transaction exists in dead letter queue or was voided. (4) Escalate to finance team if monetary impact exceeds threshold. (5) Document resolution in audit trail.

### Operational Observability Summary

| Metric | Source | Alert Threshold |
|--------|--------|----------------|
| Transaction TPS | CloudWatch custom metric | < 2 TPS during business hours (anomaly) or > 100 TPS (attack/spike) |
| API p95 latency | ALB + X-Ray | > 500ms for ingestion, > 200ms for queries |
| Error rate | CloudWatch | > 1% of requests returning 5xx |
| Kafka consumer lag | MSK metrics | > 10,000 messages per consumer group |
| Circuit breaker state | Application metric | Any processor circuit OPEN |
| Settlement batch age | Custom metric | Any batch in `submitted` state > 48 hours |
| Reconciliation exceptions | Custom metric | > 50 unresolved exceptions or > $10,000 |
| Aurora replica lag | CloudWatch | > 1 second |
| Disk / memory | CloudWatch | > 80% utilization |

---

## 8. MVP Path & Phased Delivery

### Phase 1: Core Transaction Loop (Weeks 1-6)

**Goal:** Process a single-currency card payment end-to-end: ingest, authorize, capture, and record in ledger.

**Build:**
- Laravel Octane API scaffolding with RoadRunner
- `transactions` table, `ledger_entries` table, `audit_log` table
- Transaction state machine (pending -> authorized -> captured -> failed)
- Idempotency layer (Redis + MySQL unique constraint)
- One processor gateway integration (the primary acquirer)
- Double-entry ledger service (debit/credit on every state transition)
- Kafka producer for domain events (fire-and-forget consumer initially)
- Basic merchant config (hardcoded for one test merchant)
- Health check endpoints, structured logging with correlation IDs

**Does not include:** Settlement, FX, reporting, reconciliation, dashboards.

**Validates:** Core transaction throughput at target TPS. Ledger integrity. Idempotency. Processor integration pattern.

### Phase 2: Settlement & Reconciliation (Weeks 7-10)

**Goal:** Batch settlement with automated reconciliation against processor files.

**Build:**
- `settlement_batches` table and settlement state machine
- Settlement batch job (daily, per processor)
- Settlement file generation and S3 upload
- Processor file ingestion (SFTP pull or webhook)
- Reconciliation matching engine
- Reconciliation exception model and basic resolution workflow
- Ledger entries for settlement (bank_settlement accounts)

**Validates:** End-to-end financial flow from authorization to merchant payout. Reconciliation catches discrepancies.

### Phase 3: FX & Cross-Border (Weeks 11-13)

**Goal:** Support multi-currency transactions with rate locking and transparent markup.

**Build:**
- `fx_rate_locks` table
- FX rate provider integration
- Rate lock acquisition at authorization time
- Currency conversion with markup tracking
- Settlement in multiple currencies
- FINTRAC large transaction detection (> CAD $10,000)

**Validates:** FX rate lock lifecycle. Cross-border settlement. AML threshold detection.

### Phase 4: OLAP Pipeline & Reporting (Weeks 14-17)

**Goal:** ClickHouse analytics with 2x daily report generation.

**Build:**
- Kafka consumer writing to ClickHouse `transaction_facts`
- ClickHouse materialized views for aggregation
- Report generation jobs (06:00, 18:00 ET)
- Report types: transaction summary, settlement summary, reconciliation exceptions
- S3 storage for generated reports
- Basic Vue.js dashboard for report viewing

**Validates:** OLTP/OLAP separation under load. Report generation within 2-hour window.

### Phase 5: Operational Hardening & Compliance (Weeks 18-22)

**Goal:** Production readiness -- security hardening, full observability, compliance controls.

**Build:**
- Circuit breakers on all external integrations
- Consumer lag monitoring and alerting
- Full CloudWatch + X-Ray + Grafana observability stack
- Crypto-shredding implementation for GDPR right-to-erasure
- PII field-level encryption
- RBAC for internal dashboard
- WAF rules on ALB
- Audit log hash chaining and S3 archival
- PCI-DSS self-assessment checklist validation
- Load testing at 2x target volume (2M transactions/day)
- Runbook documentation for all alert scenarios
- Disaster recovery testing (Aurora failover, consumer replay)

**Validates:** Non-functional requirements. Compliance posture. Operational readiness.

### Phase 6: Merchant Portal & Multi-Processor (Weeks 23-26)

**Goal:** Self-service merchant management and processor routing flexibility.

**Build:**
- Merchant onboarding workflow with KYC
- Merchant dashboard (Vue.js): transaction search, settlement reports, webhook config
- API key management with HMAC signing
- Multi-processor routing engine (rules-based: by BIN, currency, merchant config)
- Fee schedule management
- Webhook delivery service with retry logic

**Validates:** Multi-tenant operation. Processor failover. Merchant self-service.

---

*Document generated: 2026-03-27. Requires human review before proceeding to implementation planning.*
