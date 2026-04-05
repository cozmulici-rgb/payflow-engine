# Phase 05: Capture, Refund, And Merchant Webhook Notifications

## Objective

Complete the merchant-facing core payment lifecycle by adding capture and refund flows plus webhook delivery for lifecycle events.

## Dependencies

- Depends on: Phase 04
- Enables: Phase 06

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `modules/PaymentProcessing/Application/CaptureTransaction/CaptureTransactionHandler.php` | Capture orchestration |
| `modules/PaymentProcessing/Application/RefundTransaction/RefundTransactionHandler.php` | Refund orchestration |
| `modules/PaymentProcessing/Interfaces/Http/CaptureTransactionController.php` | Capture endpoint |
| `modules/PaymentProcessing/Interfaces/Http/RefundTransactionController.php` | Refund endpoint |
| `modules/MerchantManagement/Domain/WebhookEndpoint.php` | Webhook endpoint entity |
| `modules/MerchantManagement/Application/RegisterWebhook/RegisterWebhookHandler.php` | Endpoint registration |
| `modules/Shared/Infrastructure/Workers/WebhookDispatchWorker.php` | Outbound webhook worker |
| `modules/Shared/Infrastructure/Http/WebhookSigner.php` | Outbound signature logic |
| `apps/api/database/migrations/0001_01_01_000040_create_webhook_endpoints_table.php` | Webhook endpoint storage |
| `apps/api/database/migrations/0001_01_01_000041_create_webhook_deliveries_table.php` | Delivery attempt tracking |
| `apps/api/tests/Feature/Merchant/CaptureTransactionTest.php` | Capture API tests |
| `apps/api/tests/Feature/Merchant/RefundTransactionTest.php` | Refund API tests |
| `apps/api/tests/Integration/Webhooks/WebhookDispatchTest.php` | Webhook dispatch tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/routes/api.php` | Register capture, refund, and webhook endpoint routes |
| `modules/PaymentProcessing/Infrastructure/Providers/Processor/TransactionProcessor.php` | Add capture and refund methods |
| `modules/Ledger/Application/LedgerPostingService.php` | Add refund posting contract |
| `modules/Audit/Application/WriteAuditRecord.php` | Add capture/refund/webhook audit events |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface TransactionProcessor
{
    public function capture(Transaction $transaction, Money $amount): ProcessorCaptureResult;
    public function refund(Transaction $transaction, Money $amount): ProcessorRefundResult;
}
```

```php
interface LedgerPostingService
{
    public function postRefund(Transaction $refundTransaction): JournalEntryId;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Capture authorized transaction | Integration | `apps/api/tests/Feature/Merchant/CaptureTransactionTest.php` |
| Refund captured or settled transaction | Integration | `apps/api/tests/Feature/Merchant/RefundTransactionTest.php` |
| Merchant receives payment lifecycle webhooks | Integration | `apps/api/tests/Integration/Webhooks/WebhookDispatchTest.php` |

## Acceptance Criteria for This Phase

- [ ] Authorized transactions can enter the capture flow
- [ ] Eligible transactions can enter partial or full refund flow with validation
- [ ] Merchant webhook endpoints can be registered and signed outbound notifications are retried on failure
- [ ] Refund-related financial postings are recorded through the ledger service
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Feature/Merchant/CaptureTransactionTest.php tests/Feature/Merchant/RefundTransactionTest.php tests/Integration/Webhooks`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Avoid broad webhook framework work here; only payment lifecycle notifications are in scope.
- Capture and refund status transitions must still use the central state machine.
