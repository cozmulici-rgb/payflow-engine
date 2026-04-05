# Phase 07: Reconciliation And Exception Operations

## Objective

Implement processor confirmation import, internal matching, exception generation, and the first operator-facing reconciliation review APIs.

## Dependencies

- Depends on: Phase 06
- Enables: Phase 08

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/database/migrations/0001_01_01_000060_create_reconciliation_records_table.php` | Reconciliation storage |
| `apps/api/database/migrations/0001_01_01_000061_create_reconciliation_exceptions_table.php` | Exception storage |
| `modules/Settlement/Application/ReconcileBatch/ReconcileBatchHandler.php` | Matching workflow |
| `modules/Settlement/Application/ResolveException/ResolveExceptionHandler.php` | Manual operator resolution |
| `modules/Settlement/Infrastructure/Imports/SettlementConfirmationParser.php` | Confirmation file parser |
| `modules/Settlement/Infrastructure/Matching/ReconciliationMatcher.php` | Matching logic |
| `modules/Settlement/Interfaces/Http/ListReconciliationExceptionsController.php` | Exception query endpoint |
| `modules/Settlement/Interfaces/Http/ResolveReconciliationExceptionController.php` | Resolution endpoint |
| `apps/api/tests/Integration/Reconciliation/ReconcileBatchTest.php` | Reconciliation tests |
| `apps/api/tests/Feature/Internal/ReconciliationExceptionsTest.php` | Operator API tests |
| `apps/api/tests/Unit/Settlement/SettlementConfirmationParserTest.php` | Parser validation tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/routes/api.php` | Register internal reconciliation routes |
| `modules/Ledger/Application/LedgerPostingService.php` | Add settlement-finalization posting contract if reconciliation success triggers it |
| `modules/Audit/Application/WriteAuditRecord.php` | Add exception creation and resolution audit events |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface LedgerPostingService
{
    public function postSettlement(SettlementBatch $batch, iterable $transactions): JournalEntryId;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Mark settlement batch exception on malformed confirmation file | Integration | `apps/api/tests/Integration/Reconciliation/ReconcileBatchTest.php` |
| Create reconciliation exception on amount mismatch | Integration | `apps/api/tests/Integration/Reconciliation/ReconcileBatchTest.php` |
| Restrict reconciliation endpoint to authorized operators | Integration | `apps/api/tests/Feature/Internal/ReconciliationExceptionsTest.php` |
| Reject settlement import with duplicate processor rows | Unit | `apps/api/tests/Unit/Settlement/SettlementConfirmationParserTest.php` |

## Acceptance Criteria for This Phase

- [ ] Settlement confirmation files can be parsed and validated before persistence
- [ ] Matching can reconcile clean confirmations and create exceptions for mismatches
- [ ] Authorized operators can list and resolve reconciliation exceptions
- [ ] Successful reconciliation can trigger final settlement postings when applicable
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Integration/Reconciliation tests/Feature/Internal/ReconciliationExceptionsTest.php tests/Unit/Settlement`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep operator actions explicit and minimal; avoid building a broad workflow engine.
- Resolution actions must be audited with actor and before/after context.
