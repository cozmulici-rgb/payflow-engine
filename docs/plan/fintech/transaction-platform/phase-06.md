# Phase 06: Settlement Batch Generation And File Submission

## Objective

Build the operational slice that groups eligible transactions into settlement batches, writes encrypted artifacts, and tracks downstream processor submission state.

## Dependencies

- Depends on: Phase 05
- Enables: Phase 07

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/database/migrations/0001_01_01_000050_create_settlement_batches_table.php` | Settlement batch storage |
| `apps/api/database/migrations/0001_01_01_000051_create_settlement_items_table.php` | Settlement item storage |
| `modules/Settlement/Domain/SettlementBatch.php` | Batch aggregate |
| `modules/Settlement/Application/CreateSettlementBatch/CreateSettlementBatchHandler.php` | Batch creation use case |
| `modules/Settlement/Application/SubmitSettlementBatch/SubmitSettlementBatchHandler.php` | Submission use case |
| `modules/Settlement/Infrastructure/Persistence/SettlementBatchRepository.php` | Batch persistence adapter |
| `modules/Settlement/Infrastructure/Files/SettlementFileGenerator.php` | Processor file builder |
| `modules/Settlement/Infrastructure/Storage/SettlementArtifactStore.php` | S3 artifact adapter |
| `modules/Settlement/Infrastructure/Providers/SettlementSubmissionGateway.php` | Processor submission adapter |
| `modules/Settlement/Infrastructure/Console/RunSettlementWindowCommand.php` | Scheduler entrypoint |
| `apps/api/tests/Integration/Settlement/CreateSettlementBatchTest.php` | Batch creation tests |
| `apps/api/tests/Integration/Settlement/SubmitSettlementBatchTest.php` | Submission tracking tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/routes/console.php` | Register settlement scheduler command |
| `modules/Ledger/Application/LedgerPostingService.php` | Reserve settlement posting contract for next phase if required |
| `modules/Audit/Application/WriteAuditRecord.php` | Add batch creation and submission audit events |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface SettlementBatchRepository
{
    public function createOpen(SettlementBatchDraft $draft): SettlementBatch;
    public function markSubmitted(string $batchId, string $artifactKey, \DateTimeImmutable $submittedAt): SettlementBatch;
    public function markException(string $batchId, string $reason): SettlementBatch;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Generate settlement batch for eligible transactions | Integration | `apps/api/tests/Integration/Settlement/CreateSettlementBatchTest.php` |
| Mark settlement batch submitted with stored artifact | Integration | `apps/api/tests/Integration/Settlement/SubmitSettlementBatchTest.php` |

## Acceptance Criteria for This Phase

- [ ] Scheduler command can identify eligible captured transactions and create a batch
- [ ] Settlement files are generated and stored in S3-compatible storage
- [ ] Processor submission state is persisted on the batch
- [ ] Batch failures move to explicit exception state rather than silent retry loops
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Integration/Settlement`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep file formats adapter-based because processor selection is still unresolved.
- Do not implement reconciliation parsing here; that is Phase 07.
