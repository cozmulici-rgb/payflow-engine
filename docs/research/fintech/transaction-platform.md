# Research: fintech transaction platform

**Ticket:** Draft the application from the docs folder
**Date:** 2026-04-05
**Status:** Complete

---

## 1. Relevant Files & Modules

### By Role
| Role | File Path | Notes |
|------|-----------|-------|
| Requirements source | `docs/design/fintech/transaction-platform/requirements.md` | Defines business context, functional requirements, non-functional requirements, technology constraints, and compliance context. |
| High-level design source | `docs/design/fintech/transaction-platform/design.md` | Describes the target architecture, bounded contexts, state machine, write path, and core data model. |
| Repo root | `.git/` | Git metadata exists, but no application source folders are present in the repository root. |

---

## 2. Current Behavior

The current repository is documentation-only.

- The repository contains requirements and a high-level design for a financial transaction processing platform in [docs/design/fintech/transaction-platform/requirements.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/requirements.md) and [docs/design/fintech/transaction-platform/design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md).
- No application source directories such as `src/`, `app/`, `routes/`, `config/`, `database/`, `tests/`, `terraform/`, or `infra/` are present at the repository root.
- No executable runtime, API handlers, queue workers, database migrations, tests, or deployment manifests were found in the workspace.
- The existing design document states the intended platform shape as a Laravel-based modular monolith on AWS with Aurora MySQL, ClickHouse, Redis, and Kafka, but that behavior is described as target architecture rather than implemented behavior.

---

## 3. Relevant API Endpoints / Flows

No implemented API endpoints or route definitions were found in the repository.

The requirements describe intended external flows only:

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| Unknown | Unknown | Unknown | Merchant transaction ingestion via REST APIs is required by the requirements document, but no route definitions or handlers exist in the repo. |
| Unknown | Unknown | Unknown | Merchant configuration and onboarding APIs are required by the requirements document, but no route definitions or handlers exist in the repo. |
| Unknown | Unknown | Unknown | Reporting and export access are required by the requirements document, but no route definitions or handlers exist in the repo. |

---

## 4. Data Models

### Domain Models

The high-level design document names the following target domain entities:

| Entity / Value Object | Source | Notes |
|-----------------------|--------|-------|
| `Transaction` | `docs/design/fintech/transaction-platform/design.md` | Core payment lifecycle entity in the Payment Processing context. |
| `PaymentMethod` | `docs/design/fintech/transaction-platform/design.md` | Payment method abstraction owned by Payment Processing. |
| `ProcessorRoute` | `docs/design/fintech/transaction-platform/design.md` | Routing configuration for processor selection. |
| `FraudScreenResult` | `docs/design/fintech/transaction-platform/design.md` | Fraud evaluation result used before authorization. |
| `LedgerEntry` | `docs/design/fintech/transaction-platform/design.md` | Append-only ledger row in double-entry bookkeeping. |
| `Account` | `docs/design/fintech/transaction-platform/design.md` | Ledger account entity. |
| `JournalEntry` | `docs/design/fintech/transaction-platform/design.md` | Groups balancing debit and credit entries. |
| `SettlementBatch` | `docs/design/fintech/transaction-platform/design.md` | Settlement aggregation unit. |
| `SettlementItem` | `docs/design/fintech/transaction-platform/design.md` | Member transaction within a settlement batch. |
| `ReconciliationRecord` | `docs/design/fintech/transaction-platform/design.md` | Record of internal-vs-external settlement matching. |
| `Exception` | `docs/design/fintech/transaction-platform/design.md` | Reconciliation discrepancy placeholder in the design text. |
| `FxRate` | `docs/design/fintech/transaction-platform/design.md` | FX rate reference for cross-border flows. |
| `RateLock` | `docs/design/fintech/transaction-platform/design.md` | Time-bounded locked FX rate. |
| `ConversionRecord` | `docs/design/fintech/transaction-platform/design.md` | Currency conversion tracking record. |
| `CorrespondentRoute` | `docs/design/fintech/transaction-platform/design.md` | Cross-border route abstraction. |
| `Merchant` | `docs/design/fintech/transaction-platform/design.md` | Merchant identity and onboarding aggregate. |
| `MerchantConfig` | `docs/design/fintech/transaction-platform/design.md` | Merchant-specific processing and fee settings. |
| `ApiCredential` | `docs/design/fintech/transaction-platform/design.md` | Merchant API credential record. |
| `WebhookEndpoint` | `docs/design/fintech/transaction-platform/design.md` | Merchant outbound webhook destination. |
| `FeeSchedule` | `docs/design/fintech/transaction-platform/design.md` | Merchant fee and rate settings. |
| `ReportDefinition` | `docs/design/fintech/transaction-platform/design.md` | Report metadata in Reporting & Analytics. |
| `ReportRun` | `docs/design/fintech/transaction-platform/design.md` | Scheduled report execution record. |
| `AnalyticsEvent` | `docs/design/fintech/transaction-platform/design.md` | Analytical event emitted into OLAP. |

### Persistence Models

The only concrete persistence models present in the repository are SQL examples embedded in the design document:

| Storage Model | Source | Notes |
|--------------|--------|-------|
| `transactions` table | `docs/design/fintech/transaction-platform/design.md` | Includes UUIDv7 primary key, status enum, FX fields, processor fields, and optimistic locking version column. |
| `ledger_entries` table | `docs/design/fintech/transaction-platform/design.md` | Append-only double-entry ledger rows keyed by auto-increment `BIGINT`. |

No migrations, ORM models, schema files, or seed data were found in the repository.

---

## 5. Existing Patterns to Follow

The repository contains documentation-level patterns only:

- Modular monolith architecture: described in [design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md) as the intended application structure, with bounded contexts enforced inside a single Laravel deployment.
- Hard OLTP/OLAP split: described in [design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md) and [requirements.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/requirements.md) as Aurora MySQL for transactional writes and ClickHouse for analytics/reporting.
- Event-driven state transition publishing: described in both source documents as the mechanism for webhooks, analytics, audit logging, and notifications.
- Append-only double-entry ledger: described in [design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md) as the financial record-keeping pattern.
- Idempotent command handling: described in [requirements.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/requirements.md) and [design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md) for both API and worker layers.

No implemented code patterns, naming conventions, folder conventions, or testing conventions were found because no application source tree exists in the workspace.

---

## 6. Integration Points

| Integration | Type | Location | Notes |
|------------|------|----------|-------|
| Aurora MySQL / MySQL 8.x | Data store | `requirements.md`, `design.md` | Intended OLTP system of record. |
| ClickHouse | Data store | `requirements.md`, `design.md` | Intended OLAP/reporting store. |
| Redis | Cache / coordination | `requirements.md`, `design.md` | Intended for idempotency cache, locks, rate limiting, and config caching. |
| Kafka | Messaging | `design.md` | Intended event transport for command and domain-event topics. |
| RabbitMQ or SQS | Messaging option | `requirements.md` | Listed as alternatives to be finalized in design. |
| AWS | Cloud platform | `requirements.md` | Intended infrastructure platform using EC2, Elastic Beanstalk, RDS/Aurora, S3, IAM, and CloudWatch. |
| PCI-compliant tokenization vault | External compliance system | `requirements.md`, `design.md` | Intended source of payment tokens; the platform is not meant to store PANs. |
| Payment processors / acquirers | External financial integration | `requirements.md`, `design.md` | Intended authorization, capture, and settlement counterparties. |
| FX rate provider | External financial integration | `requirements.md`, `design.md` | Intended source of FX rates and rate locks. |
| Fraud service / rules engine | External or internal integration | `design.md` | Intended fraud-screening dependency before authorization. |
| Processor settlement file destinations | External file/API integration | `design.md` | Intended SFTP/API delivery target for settlement files. |

---

## 7. Test Locations & Conventions

No test directories, test files, fixtures, or test configuration were found in the repository.

| Test Type | Location | Coverage Notes |
|-----------|----------|----------------|
| Unit | Not found | No test tree exists. |
| Integration | Not found | No test tree exists. |
| End-to-end | Not found | No test tree exists. |
| Convention | Not found | No implemented test naming or fixture conventions are present in the workspace. |

---

## 8. FinTech Domain

### Financial Entities

The source documents describe a transaction-processing platform centered on:

- Payment transactions with lifecycle states from `pending` through `settled`, plus failure, void, and refund branches.
- Double-entry ledger records for all money movement.
- Settlement batches and reconciliation records.
- FX rate locks and conversion records for cross-border payments.
- Merchant fee schedules and payout-related configuration.

### Ledger Structure

- The design document describes an append-only double-entry ledger.
- Each financial movement creates exactly two `ledger_entries` rows, one debit and one credit.
- Account classes described in the design are asset, liability, revenue, and expense.
- The design text states that no `UPDATE` or `DELETE` operations are performed on the ledger table.

### Payment State Machine

The design document defines the following target states and transitions:

- Main path: `pending -> authorized -> captured -> settled`
- Failure branches: `pending -> failed`, `authorized -> failed`
- Void branch: `authorized -> voided`
- Refund branch: `captured -> refund_pending -> refunded`
- Expiry branch: `authorized -> expired`

### Currency Handling

- The requirements require multi-currency transactions and cross-border wire support.
- FX rates are locked at authorization for a configurable duration.
- The design document stores original and settlement amounts separately and uses `DECIMAL(18,4)` precision in sample schemas.
- FX markup tracking is called out as a reporting and accounting requirement.

### Compliance Integrations

- PCI-DSS scope reduction via external tokenization is stated in both source documents.
- GDPR and PIPEDA obligations are listed in the requirements document.
- FINTRAC/AML reporting thresholds and cross-border compliance requirements are listed in the requirements document.
- The design document describes an immutable audit trail and PII isolation layer as intended compliance mechanisms.

### Idempotency

- The requirements document states that operations must be idempotent and safe to retry.
- The design document describes API idempotency via a unique `idempotency_key` plus Redis response cache, and worker idempotency via deduplication of consumed events by `(event_id, consumer_group)`.

---

## 9. Boundaries — What Must Not Be Touched

Because the repository currently contains only design inputs and no application code, the current bounded scope is:

- Existing source documents in `docs/design/fintech/transaction-platform/` should be treated as source inputs rather than overwritten.
- No conclusions can be drawn about Laravel module structure, route files, migrations, queue configuration, IaC layout, or CI wiring because those assets do not exist in the workspace.
- IDE metadata under `docs/.idea/` and `.DS_Store` files are unrelated to the application design task.

---

## 10. Unknowns / Missing Information

- Unknown: The desired application bootstrap format. Needs: confirmation whether the next deliverable should remain documentation-only or include Laravel/Nuxt project scaffolding.
- Unknown: The exact repo structure expected for the modular monolith. Needs: approved design artifact defining package/module boundaries and folder layout.
- Unknown: Which message broker is selected for implementation. Needs: decision among Kafka, RabbitMQ, or SQS for the production design baseline.
- Unknown: Which payment processors, FX providers, and fraud providers are in scope for the initial release. Needs: integration shortlist or approved assumptions.
- Unknown: Whether Elastic Beanstalk remains the deployment target or if ECS/EKS is acceptable. Needs: infrastructure decision from the design phase.
- Unknown: Whether the current `design.md` is final source material or an earlier draft that should be superseded by pipeline artifacts. Needs: human review.
