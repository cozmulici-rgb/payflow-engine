## Behat Scenario Coverage

This directory captures Gherkin scenarios for the logic implemented across phases 1 through 6.

The repo does not currently include a Behat runtime or `FeatureContext`, so these feature files are scenario specifications mapped to the existing lightweight PHP tests.

### Phase mapping

- `features/phase-01-platform-and-merchant-access.feature`
  Maps to:
  `tests/Feature/System/HealthCheckTest.php`
  `tests/Feature/Internal/CreateMerchantTest.php`
  `tests/Feature/Internal/IssueApiCredentialTest.php`
- `features/phase-02-transaction-ingestion.feature`
  Maps to:
  `tests/Feature/Merchant/CreateTransactionTest.php`
  `tests/Feature/Merchant/GetTransactionStatusTest.php`
  `tests/Unit/Payment/CreateTransactionHandlerTest.php`
  `tests/Unit/Payment/IdempotencyRepositoryTest.php`
- `features/phase-03-authorization-processing.feature`
  Maps to:
  `tests/Integration/Workers/AuthorizeTransactionWorkerTest.php`
  `tests/Unit/Payment/TransactionStateMachineTest.php`
  `tests/Unit/Payment/ProcessorRouterTest.php`
- `features/phase-04-ledger-posting.feature`
  Maps to:
  `tests/Integration/Ledger/AuthorizationPostingTest.php`
  `tests/Unit/Ledger/PostAuthorizationLedgerEntriesTest.php`
  `tests/Unit/Ledger/ChartOfAccountsSeederTest.php`
- `features/phase-05-capture-refund-and-webhooks.feature`
  Maps to:
  `tests/Feature/Merchant/CaptureTransactionTest.php`
  `tests/Feature/Merchant/RefundTransactionTest.php`
  `tests/Integration/Webhooks/WebhookDispatchTest.php`
- `features/phase-06-settlement-batch-generation.feature`
  Maps to:
  `tests/Integration/Settlement/CreateSettlementBatchTest.php`
  `tests/Integration/Settlement/SubmitSettlementBatchTest.php`
