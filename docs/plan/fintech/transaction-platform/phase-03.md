# Phase 03: Authorization Flow With Fraud, FX, And Processor Abstraction

## Objective

Implement the first end-to-end payment worker path that consumes pending transactions, applies fraud and optional FX rate locking, calls a processor abstraction, and transitions transactions to `authorized` or `failed`.

## Dependencies

- Depends on: Phase 02
- Enables: Phase 04

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/database/migrations/0001_01_01_000020_create_processed_events_table.php` | Worker deduplication store |
| `apps/api/database/migrations/0001_01_01_000021_create_fx_rate_locks_table.php` | FX lock storage |
| `modules/PaymentProcessing/Application/AuthorizeTransaction/AuthorizeTransactionHandler.php` | Worker command handler |
| `modules/PaymentProcessing/Application/AuthorizeTransaction/ProcessorAuthorizationResult.php` | Processor result contract |
| `modules/PaymentProcessing/Infrastructure/Workers/ProcessTransactionWorker.php` | Kafka consumer entrypoint |
| `modules/PaymentProcessing/Infrastructure/Providers/Processor/TransactionProcessor.php` | Processor contract |
| `modules/PaymentProcessing/Infrastructure/Providers/Processor/ProcessorRouter.php` | Route processor selection |
| `modules/PaymentProcessing/Infrastructure/Providers/Fraud/FraudScreeningService.php` | Fraud contract |
| `modules/FXCrossBorder/Application/LockRate/FxQuoteRequest.php` | FX quote request DTO |
| `modules/FXCrossBorder/Application/LockRate/FxRateLockService.php` | FX lock contract |
| `modules/FXCrossBorder/Infrastructure/Persistence/RateLockRepository.php` | FX lock storage adapter |
| `modules/PaymentProcessing/Domain/TransactionStateMachine.php` | Legal transition logic |
| `modules/PaymentProcessing/Domain/Events/TransactionAuthorized.php` | Authorized event |
| `modules/PaymentProcessing/Domain/Events/TransactionFailed.php` | Failed event |
| `apps/api/tests/Integration/Workers/AuthorizeTransactionWorkerTest.php` | Worker integration tests |
| `apps/api/tests/Unit/Payment/TransactionStateMachineTest.php` | State machine tests |
| `apps/api/tests/Unit/Payment/ProcessorRouterTest.php` | Processor routing unit tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/config/payflow.php` | Add processor, fraud, FX, and retry configuration |
| `modules/PaymentProcessing/Infrastructure/Persistence/TransactionRepository.php` | Support status transitions and processor reference persistence |
| `modules/Audit/Application/WriteAuditRecord.php` | Add auth failure and authorization audit events |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface TransactionProcessor
{
    public function authorize(Transaction $transaction, ?RateLock $rateLock): ProcessorAuthorizationResult;
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
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Transition pending transaction to authorized | Integration | `apps/api/tests/Integration/Workers/AuthorizeTransactionWorkerTest.php` |
| Stop processing on fraud rejection | Integration | `apps/api/tests/Integration/Workers/AuthorizeTransactionWorkerTest.php` |
| Recover from processor timeout via status inquiry | Integration | `apps/api/tests/Integration/Workers/AuthorizeTransactionWorkerTest.php` |
| Prevent illegal state transition | Unit | `apps/api/tests/Unit/Payment/TransactionStateMachineTest.php` |

## Acceptance Criteria for This Phase

- [ ] Payment worker consumes pending transaction commands and deduplicates retries
- [ ] Cross-border transactions can obtain and persist FX rate locks
- [ ] Fraud decision can fail a transaction before any processor call
- [ ] Processor authorization can transition a transaction to `authorized` with processor metadata
- [ ] Timeout handling supports retry or status inquiry without duplicate processing
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Integration/Workers/AuthorizeTransactionWorkerTest.php tests/Unit/Payment`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Use adapter-first integrations because provider selection remains open.
- Do not write ledger entries yet; Phase 04 owns financial postings.
- Domain events emitted here must align with `contracts.md`.
