# Phase 04: Ledger Posting For Core Payment Events

## Objective

Introduce the append-only ledger so payment authorizations produce balanced journal entries and financial truth is no longer represented only by transaction state.

## Dependencies

- Depends on: Phase 03
- Enables: Phase 05

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/database/migrations/0001_01_01_000030_create_accounts_table.php` | Chart of accounts storage |
| `apps/api/database/migrations/0001_01_01_000031_create_journal_entries_table.php` | Journal grouping storage |
| `apps/api/database/migrations/0001_01_01_000032_create_ledger_entries_table.php` | Append-only ledger storage |
| `modules/Ledger/Domain/Account.php` | Account entity |
| `modules/Ledger/Domain/JournalEntry.php` | Journal aggregate |
| `modules/Ledger/Application/PostAuthorizationLedgerEntries.php` | Authorization posting use case |
| `modules/Ledger/Application/LedgerPostingService.php` | Ledger contract |
| `modules/Ledger/Infrastructure/Persistence/EloquentAccount.php` | Account model |
| `modules/Ledger/Infrastructure/Persistence/EloquentLedgerEntry.php` | Ledger entry model |
| `modules/Ledger/Infrastructure/Persistence/LedgerRepository.php` | Ledger persistence adapter |
| `apps/api/database/seeders/ChartOfAccountsSeeder.php` | Baseline accounts |
| `apps/api/tests/Unit/Ledger/PostAuthorizationLedgerEntriesTest.php` | Ledger balancing unit tests |
| `apps/api/tests/Integration/Ledger/AuthorizationPostingTest.php` | Financial posting integration tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `modules/PaymentProcessing/Application/AuthorizeTransaction/AuthorizeTransactionHandler.php` | Invoke ledger posting within successful authorization transaction boundary |
| `modules/Audit/Application/WriteAuditRecord.php` | Add ledger posting audit support |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface LedgerPostingService
{
    public function postAuthorization(Transaction $transaction): JournalEntryId;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Produce balanced ledger entries for authorization | Unit | `apps/api/tests/Unit/Ledger/PostAuthorizationLedgerEntriesTest.php` |
| Authorization writes ledger rows atomically | Integration | `apps/api/tests/Integration/Ledger/AuthorizationPostingTest.php` |

## Acceptance Criteria for This Phase

- [ ] Successful authorization posts exactly one balanced journal entry pair
- [ ] Ledger entries are append-only and never updated in normal flow
- [ ] Failed ledger posting prevents successful transaction authorization commit
- [ ] Baseline chart of accounts exists for processor receivable and merchant payable flows
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Unit/Ledger tests/Integration/Ledger`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep ledger schema and posting rules minimal but correct; settlement and refunds will extend them later.
- Do not collapse ledger data into the transaction table for convenience.
