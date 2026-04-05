# Structure Outline: fintech transaction platform

**Status:** Proposed for review
**Purpose:** Phase map for the planning step. This file is the authoritative slice order once approved.

---

## 1. Planning Principles

- Every phase must be vertically sliceable and independently testable.
- No phase should introduce shared infrastructure without also exercising it through at least one user-visible or operator-visible flow.
- Financial correctness outranks breadth; core money movement paths come before secondary UI and reporting breadth.
- Reporting depends on event quality, so event contracts must be exercised in earlier phases.

---

## 2. Proposed Slice Order

### Phase 01: Platform Skeleton And Merchant Access

Goal:
- Create the application shell, baseline auth model, merchant entity, API credential issuance, and operator authentication scaffolding.

Must include:
- repo/application structure
- merchant management module skeleton
- authn/authz baseline
- correlation ID and audit plumbing
- first health/readiness endpoints

Acceptance checkpoint:
- operator can create a merchant
- merchant credentials can be issued
- audit records exist for merchant creation

### Phase 02: Transaction Ingestion And Idempotent Pending State

Goal:
- Accept merchant transaction requests and persist `pending` transactions safely.

Must include:
- `POST /v1/transactions`
- request validation
- idempotency key persistence and replay
- pending transaction storage
- status history
- command publication to broker

Acceptance checkpoint:
- valid request returns `202`
- duplicate request does not create a second transaction
- `GET /v1/transactions/{id}` returns pending status

### Phase 03: Authorization Flow With Fraud, FX, And Processor Abstraction

Goal:
- Move pending transactions through fraud checks, optional FX rate lock, and processor authorization.

Must include:
- payment worker
- fraud integration abstraction
- FX lock abstraction
- processor gateway abstraction
- success and failure state transitions
- timeout and inquiry handling baseline

Acceptance checkpoint:
- transaction can become `authorized`
- transaction can become `failed`
- no duplicate processing on retries

### Phase 04: Ledger Posting For Core Payment Events

Goal:
- Make authorization and refund-adjacent financial transitions write balanced ledger entries.

Must include:
- chart-of-accounts baseline
- journal and ledger tables
- posting policies for authorization and failure-safe corrections
- audit linkages for financial postings

Acceptance checkpoint:
- authorization produces balanced entries
- ledger is append-only
- posting failures cannot silently complete transaction transitions

### Phase 05: Capture, Refund, And Merchant Webhook Notifications

Goal:
- Complete the merchant-facing payment lifecycle beyond authorization.

Must include:
- capture endpoint and command flow
- refund endpoint and command flow
- webhook endpoint registration
- outbound webhook delivery and retry behavior

Acceptance checkpoint:
- authorized transaction can be captured
- captured or settled transaction can be partially refunded where allowed
- merchant receives payment lifecycle webhooks

### Phase 06: Settlement Batch Generation And File Submission

Goal:
- Group eligible transactions into processor settlement batches and submit artifacts.

Must include:
- settlement selectors
- batch creation and persistence
- settlement file generation
- S3 artifact storage
- processor submission tracking

Acceptance checkpoint:
- daily settlement window creates a batch
- file is stored and submission is tracked
- batch status advances to `submitted`

### Phase 07: Reconciliation And Exception Operations

Goal:
- Compare processor confirmations against internal batches and surface discrepancies.

Must include:
- confirmation import
- matching rules
- exception creation
- internal exception query endpoints
- operator resolution workflow baseline

Acceptance checkpoint:
- clean file reconciles a batch
- mismatch creates an exception
- operator can view unresolved exceptions

### Phase 08: Analytics Projection And Twice-Daily Reporting

Goal:
- Project domain events into ClickHouse and generate the required reports.

Must include:
- projection consumers
- analytical fact schema
- report run metadata
- scheduled report generation
- export artifact storage

Acceptance checkpoint:
- authorized and settled events appear in analytical facts
- scheduled report run produces CSV/PDF output
- stale watermark blocks incomplete reporting

### Phase 09: Operator Dashboard

Goal:
- Expose the internal workflows through a Nuxt dashboard.

Must include:
- operator authentication integration
- merchant management views
- reconciliation exception views
- report run and download views

Acceptance checkpoint:
- operator can create merchants, inspect payment status, review exceptions, and access reports from the UI

### Phase 10: Compliance Hardening And Operational Readiness

Goal:
- Close compliance-sensitive gaps before production readiness.

Must include:
- RBAC hardening
- audit integrity controls
- retention and archival jobs
- incident and watermark alerting
- load/performance validation hooks

Acceptance checkpoint:
- critical audit, authz, and retention controls are enforced
- key SLO and lag alerts are observable

---

## 3. Cross-Phase Constraints

- No phase may bypass the ledger for financial truth.
- No analytical feature may query Aurora for report-grade aggregations.
- No cardholder data may be introduced into platform storage or logs.
- Every new write path must define idempotency and audit behavior.

---

## 4. Risks The Plan Must Account For

- Provider selection uncertainty may require adapter-first design in early phases.
- Kafka and ClickHouse can add setup complexity; plan should avoid making them blockers for the very first productive flow where possible while still preserving approved ADRs.
- Reconciliation tooling can expand quickly; early slices should keep operator actions minimal and explicit.
- Cross-border support increases data and compliance scope; plan should avoid mixing every country-specific rule into the earliest payment slices.

---

## 5. Exit Condition For Plan Phase

The plan phase should turn each phase above into:

- exact target files/directories
- exact tests to be added
- acceptance criteria
- dependencies between phases
- any deferred items called out explicitly
