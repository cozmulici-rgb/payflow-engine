# Contracts: fintech transaction platform

**Status:** Proposed for review

---

## 1. External API Contracts

### 1.1 Create Transaction

`POST /v1/transactions`

Authentication:
- Merchant API key with HMAC signature or bearer token

Headers:
- `Idempotency-Key: <string>` required
- `Content-Type: application/json`
- `X-Correlation-Id: <uuid>` optional, generated if absent

Request:

```json
{
  "merchant_id": "uuid",
  "type": "authorization",
  "amount": "125.50",
  "currency": "USD",
  "payment_method": {
    "type": "card_token",
    "token": "tok_abc123"
  },
  "capture_mode": "manual",
  "reference": "order-10001",
  "settlement_currency": "CAD",
  "customer": {
    "data_subject_id": "cust_001"
  },
  "metadata": {
    "channel": "web"
  }
}
```

Response `202 Accepted`:

```json
{
  "data": {
    "transaction_id": "trx_demo_alpha_001",
    "status": "pending",
    "idempotency_key": "idem_123",
    "received_at": "2026-04-05T13:30:00Z"
  }
}
```

Response `401 Unauthorized`:

```json
{
  "message": "Authentication failed"
}
```

Response `403 Forbidden`:

```json
{
  "message": "Merchant is not allowed to process this transaction"
}
```

Response `422 Unprocessable Entity`:

```json
{
  "message": "Validation failed",
  "errors": {
    "amount": [
      "The amount field must be a positive decimal value."
    ],
    "currency": [
      "The selected currency is invalid."
    ]
  }
}
```

### 1.2 Get Transaction Status

`GET /v1/transactions/{transaction_id}`

Response `200 OK`:

```json
{
  "data": {
    "transaction_id": "trx_demo_alpha_001",
    "status": "authorized",
    "amount": "125.50",
    "currency": "USD",
    "settlement_amount": "171.12",
    "settlement_currency": "CAD",
    "processor_reference": "proc_9981",
    "updated_at": "2026-04-05T13:31:08Z"
  }
}
```

Response `404 Not Found`:

```json
{
  "message": "Transaction not found"
}
```

### 1.3 Capture Authorized Transaction

`POST /v1/transactions/{transaction_id}/capture`

Request:

```json
{
  "amount": "125.50",
  "reference": "shipment-1"
}
```

Response `202 Accepted`:

```json
{
  "data": {
    "transaction_id": "trx_demo_alpha_001",
    "status": "capture_pending"
  }
}
```

### 1.4 Refund Settled Or Captured Transaction

`POST /v1/transactions/{transaction_id}/refund`

Request:

```json
{
  "amount": "25.00",
  "reason": "customer_request"
}
```

Response `202 Accepted`:

```json
{
  "data": {
    "transaction_id": "trx_demo_refund_099",
    "parent_transaction_id": "trx_demo_alpha_001",
    "status": "refund_pending"
  }
}
```

### 1.5 Merchant Management

`POST /internal/v1/merchants`

Authorization:
- Operator session with `merchant.write`

Request:

```json
{
  "legal_name": "Acme Payments Canada Inc.",
  "display_name": "Acme Payments",
  "country": "CA",
  "default_currency": "CAD",
  "supported_currencies": ["CAD", "USD"],
  "settlement_schedule": "daily",
  "fee_schedule": {
    "processing_bps": 250,
    "fixed_fee": "0.30"
  }
}
```

Response `201 Created`:

```json
{
  "data": {
    "merchant_id": "mrc_demo_beta_001",
    "status": "active"
  }
}
```

### 1.6 Reconciliation Exception Query

`GET /internal/v1/reconciliation/exceptions?status=open&batch_date=2026-04-05`

Authorization:
- Operator session with `reconciliation.read`

Response `200 OK`:

```json
{
  "data": [
    {
      "exception_id": "exc_demo_gamma_001",
      "batch_id": "bat_demo_delta_001",
      "type": "amount_mismatch",
      "transaction_id": "trx_demo_alpha_001",
      "status": "open"
    }
  ]
}
```

### 1.7 Report Download Metadata

`GET /internal/v1/reports/runs/{report_run_id}`

Authorization:
- Operator session with `reporting.read`

Response `200 OK`:

```json
{
  "data": {
    "report_run_id": "rpt_demo_epsilon_001",
    "report_type": "settlement_summary",
    "status": "completed",
    "cutoff_at": "2026-04-05T10:55:00Z",
    "artifact_url": "s3://reports/settlement_summary_20260405_1800.csv"
  }
}
```

---

## 2. Event Contracts

### 2.1 `transaction.created`

```json
{
  "event_id": "uuid",
  "event_type": "transaction.created",
  "occurred_at": "2026-04-05T13:30:00Z",
  "correlation_id": "uuid",
  "transaction_id": "uuid",
  "merchant_id": "uuid",
  "status": "pending",
  "amount": "125.50",
  "currency": "USD"
}
```

### 2.2 `transaction.authorized`

```json
{
  "event_id": "uuid",
  "event_type": "transaction.authorized",
  "occurred_at": "2026-04-05T13:31:08Z",
  "correlation_id": "uuid",
  "transaction_id": "uuid",
  "merchant_id": "uuid",
  "processor_id": "stripe_ca",
  "processor_reference": "proc_9981",
  "amount": "125.50",
  "currency": "USD",
  "settlement_amount": "171.12",
  "settlement_currency": "CAD"
}
```

### 2.3 `transaction.failed`

```json
{
  "event_id": "uuid",
  "event_type": "transaction.failed",
  "occurred_at": "2026-04-05T13:31:05Z",
  "correlation_id": "uuid",
  "transaction_id": "uuid",
  "merchant_id": "uuid",
  "error_code": "fraud_rejected",
  "error_message": "Risk score above merchant threshold"
}
```

### 2.4 `settlement.batch.submitted`

```json
{
  "event_id": "uuid",
  "event_type": "settlement.batch.submitted",
  "batch_id": "uuid",
  "processor_id": "processor_x",
  "currency": "CAD",
  "item_count": 8500,
  "total_amount": "1255000.22",
  "submitted_at": "2026-04-05T22:10:00Z"
}
```

---

## 3. Internal Interface Contracts

```php
interface IdempotencyRepository
{
    public function findResponseByKey(string $key): ?IdempotentResponse;
    public function storeAcceptedResponse(string $key, IdempotentResponse $response, \DateTimeImmutable $expiresAt): void;
}
```

```php
interface TransactionRepository
{
    public function createPending(CreateTransactionData $data): Transaction;
    public function findById(string $transactionId): ?Transaction;
    public function findByIdempotencyKey(string $idempotencyKey): ?Transaction;
    public function updateStatus(string $transactionId, TransactionStatus $expected, TransactionStatus $next): Transaction;
}
```

```php
interface TransactionProcessor
{
    public function authorize(Transaction $transaction, ?RateLock $rateLock): ProcessorAuthorizationResult;
    public function capture(Transaction $transaction, Money $amount): ProcessorCaptureResult;
    public function refund(Transaction $transaction, Money $amount): ProcessorRefundResult;
    public function inquire(string $processorReference, string $idempotencyKey): ProcessorInquiryResult;
}
```

```php
interface FraudScreeningService
{
    public function screen(TransactionRiskContext $context): FraudDecision;
}
```

```php
interface FxRateLockService
{
    public function lock(FxQuoteRequest $request): RateLock;
    public function markUsed(string $rateLockId): void;
    public function expireOverdueLocks(\DateTimeImmutable $asOf): int;
}
```

```php
interface LedgerPostingService
{
    public function postAuthorization(Transaction $transaction): JournalEntryId;
    public function postSettlement(SettlementBatch $batch, iterable $transactions): JournalEntryId;
    public function postRefund(Transaction $refundTransaction): JournalEntryId;
}
```

```php
interface SettlementBatchRepository
{
    public function createOpen(SettlementBatchDraft $draft): SettlementBatch;
    public function markSubmitted(string $batchId, string $artifactKey, \DateTimeImmutable $submittedAt): SettlementBatch;
    public function markException(string $batchId, string $reason): SettlementBatch;
}
```

```php
interface AnalyticsProjectionWriter
{
    public function writeTransactionFact(TransactionEventProjection $projection): void;
    public function latestWatermark(string $streamName): ?\DateTimeImmutable;
}
```

```php
interface AuditLogWriter
{
    public function append(AuditRecord $record): void;
}
```

---

## 4. Authorization Rules

| Surface | AuthN | AuthZ |
|---------|-------|-------|
| Merchant transaction APIs | Merchant API credentials | Merchant can access only own transactions and configuration |
| Internal merchant management | Operator SSO/session | `merchant.read` / `merchant.write` |
| Reconciliation endpoints | Operator SSO/session | `reconciliation.read` / `reconciliation.resolve` |
| Reporting endpoints | Operator SSO/session | `reporting.read` |
| Admin configuration | Operator SSO/session | `platform.admin` |

---

## 5. Validation Rules

- Amounts must be positive decimal strings with currency-aware precision validation.
- Currency codes must be valid ISO 4217 values enabled for the merchant.
- `Idempotency-Key` is required for all mutating merchant endpoints.
- Refund amount cannot exceed remaining refundable amount.
- Capture amount cannot exceed authorized amount.
- Settlement report import format must pass schema and duplicate checks before persistence.
