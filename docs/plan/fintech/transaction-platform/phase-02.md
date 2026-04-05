# Phase 02: Transaction Ingestion And Idempotent Pending State

## Objective

Implement merchant-facing transaction ingestion so valid requests persist a `pending` transaction exactly once, publish a processing command, and support status retrieval without invoking downstream authorization logic yet.

## Dependencies

- Depends on: Phase 01
- Enables: Phase 03

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/database/migrations/0001_01_01_000010_create_transactions_table.php` | Core transactions table |
| `apps/api/database/migrations/0001_01_01_000011_create_transaction_status_history_table.php` | Status history audit trail |
| `apps/api/database/migrations/0001_01_01_000012_create_idempotency_records_table.php` | Durable idempotency storage |
| `modules/PaymentProcessing/Domain/Transaction.php` | Transaction aggregate |
| `modules/PaymentProcessing/Domain/TransactionStatus.php` | Status enum/value object |
| `modules/PaymentProcessing/Application/CreateTransaction/CreateTransactionCommand.php` | Transaction creation command |
| `modules/PaymentProcessing/Application/CreateTransaction/CreateTransactionHandler.php` | Pending transaction use case |
| `modules/PaymentProcessing/Application/GetTransaction/GetTransactionQuery.php` | Read-side transaction query |
| `modules/PaymentProcessing/Infrastructure/Persistence/EloquentTransaction.php` | Transaction persistence model |
| `modules/PaymentProcessing/Infrastructure/Persistence/TransactionRepository.php` | Transaction repository implementation |
| `modules/PaymentProcessing/Infrastructure/Persistence/IdempotencyRepository.php` | Idempotency store implementation |
| `modules/PaymentProcessing/Infrastructure/Messaging/KafkaCommandPublisher.php` | Command publication adapter |
| `modules/PaymentProcessing/Interfaces/Http/CreateTransactionController.php` | `POST /v1/transactions` |
| `modules/PaymentProcessing/Interfaces/Http/GetTransactionController.php` | `GET /v1/transactions/{id}` |
| `modules/PaymentProcessing/Interfaces/Http/Requests/CreateTransactionRequest.php` | Request validation |
| `apps/api/tests/Feature/Merchant/CreateTransactionTest.php` | Ingestion integration tests |
| `apps/api/tests/Feature/Merchant/GetTransactionStatusTest.php` | Status query tests |
| `apps/api/tests/Unit/Payment/IdempotencyRepositoryTest.php` | Idempotency persistence tests |
| `apps/api/tests/Unit/Payment/CreateTransactionHandlerTest.php` | Pending transaction unit tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/routes/api.php` | Register merchant transaction endpoints |
| `apps/api/config/payflow.php` | Add transaction topic names and idempotency config |
| `modules/Audit/Application/WriteAuditRecord.php` | Add transaction creation audit event support |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

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
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Create pending transaction from valid merchant request | Integration | `apps/api/tests/Feature/Merchant/CreateTransactionTest.php` |
| Reject duplicate transaction replay without duplicate write | Integration | `apps/api/tests/Feature/Merchant/CreateTransactionTest.php` |
| Fail transaction on invalid currency | Unit | `apps/api/tests/Feature/Merchant/CreateTransactionTest.php` |
| Merchant can fetch pending transaction status | Integration | `apps/api/tests/Feature/Merchant/GetTransactionStatusTest.php` |

## Acceptance Criteria for This Phase

- [ ] `POST /v1/transactions` validates payloads and requires `Idempotency-Key`
- [ ] New merchant transaction requests persist `pending` transactions and status history
- [ ] Duplicate requests return the original accepted response without duplicate transaction writes
- [ ] `GET /v1/transactions/{id}` returns merchant-scoped transaction status
- [ ] A processing command is published to Kafka for newly accepted transactions
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Feature/Merchant tests/Unit/Payment`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep this phase limited to pending-state persistence and retrieval. Authorization is Phase 03.
- Merchant scoping must be enforced on reads and writes.
- Audit events for merchant transaction creation are required in this phase.
