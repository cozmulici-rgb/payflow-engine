# Testing Strategy: fintech transaction platform

**Status:** Proposed for review

---

## 1. Test Strategy

### Unit Tests

Focus:

- transaction state machine legality
- ledger posting policies and balancing logic
- FX conversion and markup calculations
- settlement batch grouping rules
- report query parameter building
- authz policy decisions
- request validation rules

Mock:

- payment processor clients
- fraud provider client
- FX provider client
- Kafka producer
- S3 client
- ClickHouse adapter

### Integration Tests

Focus:

- merchant API request to persisted pending transaction
- worker command handling to authorized or failed outcome
- idempotency behavior across duplicate requests
- ledger entries created for authorization, settlement, and refund
- settlement batch generation and reconciliation record creation
- analytical projection from domain event to ClickHouse writer call
- audit log persistence for security-sensitive actions

Use:

- Aurora-compatible MySQL test database
- Redis test instance
- fake Kafka bus or in-memory topic harness where possible
- fake S3 adapter for artifact generation

### End-To-End / System Tests

Focus:

- merchant creates transaction and later fetches status
- operator creates merchant, views exceptions, and downloads report metadata
- scheduled report run against seeded analytics data
- settlement submission path with mocked processor callback

### Non-Functional Validation

Focus:

- p95 API ingestion latency under representative load
- duplicate-request replay safety
- consumer retry behavior
- report-run completion inside 2-hour window
- audit immutability enforcement

---

## 2. Test Data Requirements

- merchant fixtures with different currencies and fee schedules
- transactions covering domestic, cross-border, fraud-rejected, timed-out, refunded, and settled scenarios
- processor response fixtures for approval, decline, timeout, malformed response, and delayed confirmation
- settlement report fixtures with clean matches, duplicates, missing records, and amount mismatches
- ClickHouse fact fixtures for twice-daily report generation windows
- operator roles: finance, compliance, admin, read-only analyst

---

## 3. Explicit Test Cases

Test: Create pending transaction from valid merchant request  
Type: Integration  
Scenario: Merchant submits a valid authorization request  
Given: Active merchant with API credentials and supported currency  
When: `POST /v1/transactions` is called with a new idempotency key  
Then: Response is `202`, transaction is persisted as `pending`, status history is created, and a processing command is published  
Covers: transaction creation controller, application service, repository, command publisher

Test: Reject duplicate transaction replay without duplicate write  
Type: Integration  
Scenario: Merchant retries same request with same idempotency key  
Given: Existing accepted transaction for that key  
When: `POST /v1/transactions` is called again  
Then: Prior response is returned and no second transaction row is created  
Covers: idempotency middleware, repository lookup, response cache behavior

Test: Fail transaction on invalid currency  
Type: Unit  
Scenario: Merchant submits unsupported currency  
Given: Merchant configuration with limited supported currencies  
When: validation runs  
Then: Request is rejected with validation error  
Covers: request validator

Test: Transition pending transaction to authorized  
Type: Integration  
Scenario: Worker processes approved authorization  
Given: Pending transaction, successful fraud result, successful processor response  
When: payment worker handles the command  
Then: Transaction becomes `authorized`, ledger entries are written, status history is appended, and `transaction.authorized` is published  
Covers: payment command handler, ledger posting service, event publisher

Test: Stop processing on fraud rejection  
Type: Integration  
Scenario: Fraud service returns reject decision  
Given: Pending transaction  
When: payment worker handles the command  
Then: Processor client is not called, transaction becomes `failed`, and failure event is published  
Covers: fraud orchestration path

Test: Recover from processor timeout via status inquiry  
Type: Integration  
Scenario: Processor authorization times out but later confirms approved  
Given: Pending transaction and processor client configured to timeout then confirm  
When: payment worker retries and performs status inquiry  
Then: Transaction becomes `authorized` without duplicate journal postings  
Covers: processor retry logic and inquiry fallback

Test: Prevent illegal state transition  
Type: Unit  
Scenario: Capture requested on a failed transaction  
Given: Transaction in `failed` state  
When: state machine evaluates `failed -> captured`  
Then: Transition is rejected with domain exception  
Covers: transaction state machine

Test: Produce balanced ledger entries for authorization  
Type: Unit  
Scenario: Authorization posting  
Given: Approved transaction and configured ledger accounts  
When: authorization posting policy executes  
Then: Exactly one debit and one credit entry of equal amount are produced  
Covers: ledger posting policy

Test: Generate settlement batch for eligible transactions  
Type: Integration  
Scenario: Daily settlement window opens  
Given: Captured transactions grouped by processor and currency  
When: settlement job runs  
Then: Batch and items are created with expected totals  
Covers: settlement selector and batch builder

Test: Mark settlement batch exception on malformed confirmation file  
Type: Integration  
Scenario: Processor sends invalid settlement confirmation  
Given: Submitted settlement batch  
When: reconciliation import runs  
Then: Batch status becomes `exception` and audit record is written  
Covers: reconciliation import service

Test: Create reconciliation exception on amount mismatch  
Type: Integration  
Scenario: Processor-reported amount differs from internal record  
Given: Submitted settlement batch and confirmation report with mismatch  
When: reconciliation runs  
Then: Exception record is created and batch remains unresolved  
Covers: reconciliation matcher

Test: Project authorized transaction into analytics fact store  
Type: Integration  
Scenario: Authorized event reaches analytics projector  
Given: `transaction.authorized` event on topic  
When: projection worker handles event  
Then: ClickHouse writer receives normalized fact payload and watermark advances  
Covers: analytics projection worker

Test: Delay report generation when projection watermark is stale  
Type: Unit  
Scenario: Scheduled report starts before analytical pipeline catches up  
Given: Cutoff time newer than current watermark threshold  
When: report-run coordinator evaluates readiness  
Then: Run is marked delayed and no report query is executed  
Covers: report readiness policy

Test: Restrict reconciliation endpoint to authorized operators  
Type: Integration  
Scenario: Read-only user attempts resolution action  
Given: Authenticated operator lacking `reconciliation.resolve`  
When: resolution endpoint is invoked  
Then: Response is `403` and no mutation occurs  
Covers: policy middleware / authorization policy

Test: Emit audit record for merchant configuration change  
Type: Integration  
Scenario: Admin updates fee schedule  
Given: Authenticated admin operator  
When: merchant config update succeeds  
Then: Audit record contains actor, resource, action, correlation ID, and before/after snapshot  
Covers: merchant config service and audit writer

Test: Reject settlement import with duplicate processor rows  
Type: Unit  
Scenario: Duplicate lines in imported reconciliation file  
Given: Parsed file rows containing duplicate external references  
When: import validator runs  
Then: Import is rejected before persistence  
Covers: settlement import validator

---

## 4. Coverage Expectations

- Every command handler has at least one success-path integration test and one failure-path test.
- Every externally callable endpoint has authentication, authorization, validation, and not-found coverage where applicable.
- Every provider adapter has contract tests against provider fixtures.
- Every financial posting rule has unit tests proving balance integrity.

---

## 5. Tooling Draft

- PHPUnit or Pest for unit and integration tests
- Laravel test helpers for HTTP and queue assertions
- Fixture builders for transactions, merchants, and settlement reports
- Load testing via k6 or Artillery for ingestion latency verification
