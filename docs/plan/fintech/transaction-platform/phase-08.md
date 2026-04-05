# Phase 08: Analytics Projection And Twice-Daily Reporting

## Objective

Project transaction and settlement events into ClickHouse, materialize reporting data, and generate scheduled CSV/PDF reports with freshness checks.

## Dependencies

- Depends on: Phase 07
- Enables: Phase 09

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `modules/ReportingAnalytics/Infrastructure/Projection/TransactionFactProjector.php` | Event-to-fact projection |
| `modules/ReportingAnalytics/Infrastructure/Projection/AnalyticsProjectionWriter.php` | ClickHouse contract |
| `modules/ReportingAnalytics/Infrastructure/Projection/ClickHouseProjectionWriter.php` | ClickHouse adapter |
| `modules/ReportingAnalytics/Application/GenerateReport/GenerateReportHandler.php` | Report generation workflow |
| `modules/ReportingAnalytics/Application/GenerateReport/ReportReadinessPolicy.php` | Watermark freshness checks |
| `modules/ReportingAnalytics/Infrastructure/Exports/ReportRenderer.php` | CSV/PDF rendering |
| `modules/ReportingAnalytics/Infrastructure/Storage/ReportArtifactStore.php` | S3 export adapter |
| `modules/ReportingAnalytics/Infrastructure/Console/RunScheduledReportsCommand.php` | Scheduler entrypoint |
| `apps/api/database/migrations/0001_01_01_000070_create_report_runs_table.php` | Report run metadata |
| `platform/clickhouse/schema/transaction_facts.sql` | Analytical fact schema |
| `platform/clickhouse/schema/daily_merchant_summary.sql` | Aggregate view schema |
| `apps/api/tests/Integration/Analytics/TransactionProjectionTest.php` | Projection tests |
| `apps/api/tests/Integration/Reporting/GenerateScheduledReportTest.php` | Report generation tests |
| `apps/api/tests/Unit/Reporting/ReportReadinessPolicyTest.php` | Freshness guard tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/routes/console.php` | Register scheduled report command |
| `apps/api/config/payflow.php` | Add reporting windows and ClickHouse configuration |
| `modules/Audit/Application/WriteAuditRecord.php` | Add report run audit events |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface AnalyticsProjectionWriter
{
    public function writeTransactionFact(TransactionEventProjection $projection): void;
    public function latestWatermark(string $streamName): ?\DateTimeImmutable;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Project authorized transaction into analytics fact store | Integration | `apps/api/tests/Integration/Analytics/TransactionProjectionTest.php` |
| Delay report generation when projection watermark is stale | Unit | `apps/api/tests/Unit/Reporting/ReportReadinessPolicyTest.php` |
| Scheduled report run produces export artifact | Integration | `apps/api/tests/Integration/Reporting/GenerateScheduledReportTest.php` |

## Acceptance Criteria for This Phase

- [ ] Authorized and settled events are projected into ClickHouse-compatible fact storage
- [ ] Twice-daily report command can create report run metadata and artifacts
- [ ] Stale projection watermark delays reporting instead of producing incomplete output
- [ ] Report artifacts are stored in S3-compatible storage with traceable run metadata
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Integration/Analytics tests/Integration/Reporting tests/Unit/Reporting`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Do not query Aurora for report-grade aggregates.
- Start with the report types explicitly called out in requirements: transaction summary, settlement summary, reconciliation exceptions, merchant performance, revenue/fee breakdown.
