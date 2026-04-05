# Discussion: fintech transaction platform application draft

**Ticket:** Draft the application from the docs folder
**Date:** 2026-04-05
**Status:** Proposed for review
**Inputs:**
- [requirements.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/requirements.md)
- [design.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/design/fintech/transaction-platform/design.md)
- [transaction-platform.md](/Users/vcozmulici/workspace/ai/payflow-engine/docs/research/fintech/transaction-platform.md)

---

## 1. Objective

Translate the existing requirements and high-level architecture into an implementation-ready application draft for a Laravel-based modular monolith, while staying within the development-pipeline gate. This document freezes the intended shape of the application before detailed design artifacts or code generation.

The draft assumes:

- The repository will become the home of the full application rather than a docs-only planning repo.
- Phase B should establish module boundaries, runtime responsibilities, and operating assumptions.
- No code will be written until this document is reviewed and approved.

---

## 2. Current State

The workspace currently contains requirements and high-level architecture documents only.

- No Laravel application exists yet.
- No Nuxt dashboard exists yet.
- No migrations, seeders, tests, or infrastructure manifests exist yet.
- No implementation choices are encoded beyond what the source documents state.

This means the application draft must define the initial repository shape and the baseline contracts between major modules, not retrofit an existing codebase.

---

## 3. Desired End State

The target application is a single deployable Laravel 12 / PHP 8.4 modular monolith with independently scalable runtime roles.

- API runtime: Laravel Octane for merchant and internal HTTP traffic.
- Worker runtime: queue consumers for transaction commands, webhooks, analytics export, audit handling, settlement, and reconciliation.
- Scheduler runtime: cron-driven jobs for settlement windows, report generation, housekeeping, and exception escalation.
- Internal dashboard runtime: Nuxt application for operators, compliance analysts, and finance teams.

The application should support the complete operational path described in the source docs:

- ingest transactions
- enforce idempotency
- perform fraud and FX checks
- authorize and capture through processor gateways
- write immutable financial movements to the ledger
- batch and reconcile settlements
- publish domain events
- replicate analytics data into ClickHouse
- generate twice-daily reports

---

## 4. Proposed Top-Level Repository Shape

The application draft assumes this repo will be organized into four major areas:

- `apps/api`
  Laravel application shell, HTTP entrypoints, queue workers, scheduler, config, and runtime bootstrap.
- `apps/dashboard`
  Nuxt operator dashboard and report access UI.
- `modules/*`
  Business modules implementing bounded contexts inside the modular monolith.
- `platform/*`
  Infrastructure adapters, shared tooling, deployment templates, and local development assets.

This shape keeps the domain modules separate from framework bootstrap code and leaves room for future extraction if one context outgrows the monolith boundary.

---

## 5. Bounded Contexts To Implement

The application draft keeps the six bounded contexts named in the source design, but turns them into concrete module targets:

### 5.1 Payment Processing

Owns:

- transaction intake
- transaction state machine
- processor routing
- fraud decision orchestration
- authorization, capture, void, and refund flows
- payment method token references

Produces:

- transaction domain events
- transaction status history
- commands to downstream settlement and analytics processes

Must not own:

- ledger balancing rules
- settlement file formatting
- merchant onboarding lifecycle
- analytics queries

### 5.2 Ledger

Owns:

- account catalog
- journal entry creation
- balancing validation
- append-only ledger storage
- financial posting policies tied to transaction and settlement events

Produces:

- immutable ledger entries
- auditable journal metadata

Must not own:

- processor API calls
- merchant fee setup UI
- reporting projections

### 5.3 Settlement

Owns:

- settlement window selection
- batch construction
- file generation
- processor submission tracking
- reconciliation record creation
- manual exception workflow coordination

Produces:

- settlement batches and items
- reconciliation exceptions
- settlement completion events

Must not own:

- transaction authorization logic
- chart-of-accounts definitions

### 5.4 FX & Cross-Border

Owns:

- rate lookup
- rate lock lifecycle
- conversion record generation
- markup tracking
- correspondent routing metadata

Produces:

- rate locks
- converted settlement amounts
- FX audit data

Must not own:

- reporting aggregates
- merchant API credential management

### 5.5 Merchant Management

Owns:

- merchant onboarding
- merchant status
- supported currencies
- fee schedules
- API credentials
- webhook endpoints
- payout and settlement preferences

Produces:

- merchant configuration read models for Payment Processing and Settlement

Must not own:

- transaction state transitions
- ledger postings

### 5.6 Reporting & Analytics

Owns:

- event projection to ClickHouse
- report definitions
- report runs
- export generation
- dashboard query models

Produces:

- twice-daily reports
- operator dashboard views
- analytical exports

Must not own:

- transactional writes in Aurora
- payment authorization behavior

---

## 6. Runtime Boundary Draft

Although the system is a modular monolith, runtime responsibilities should be split operationally:

- `api` process group
  Handles synchronous HTTP ingress and status queries.
- `worker-payments` process group
  Handles transaction commands, processor callbacks, webhook delivery, and audit events.
- `worker-settlement` process group
  Handles settlement file generation, submissions, confirmations, and reconciliation.
- `worker-analytics` process group
  Handles event projection to ClickHouse and report materialization jobs.
- `scheduler` process group
  Triggers recurring jobs and deadlines.

This keeps deployment simple while letting the platform scale HTTP, payment processing, settlement, and analytics independently.

---

## 7. Application Layering Draft

Each business module should be internally layered the same way:

- `Domain`
  Entities, value objects, aggregates, policies, domain services, events.
- `Application`
  Command handlers, query handlers, use cases, orchestration services.
- `Infrastructure`
  Eloquent models, repositories, outbound clients, queue adapters, storage adapters.
- `Interfaces`
  HTTP controllers, CLI commands, queue consumers, webhook handlers, mappers.

Shared rules:

- Domain code does not depend on Laravel framework classes.
- Cross-module collaboration happens through explicit interfaces or published domain events.
- Direct table access across modules is not allowed except through approved read models.
- Ledger writes must remain the authoritative financial source of truth.

---

## 8. Data Ownership Draft

Aurora MySQL remains the transactional source of truth.

Primary ownership by module:

- Payment Processing
  `transactions`, `transaction_status_history`, processor routing data, fraud result records
- Ledger
  `accounts`, `journal_entries`, `ledger_entries`
- Settlement
  `settlement_batches`, `settlement_items`, `reconciliation_records`, exception workflow records
- FX & Cross-Border
  `fx_rates`, `fx_rate_locks`, `conversion_records`, correspondent route records
- Merchant Management
  `merchants`, `merchant_configs`, `api_credentials`, `webhook_endpoints`, `fee_schedules`
- Reporting & Analytics
  control-plane metadata in Aurora, analytical fact tables in ClickHouse

ClickHouse owns denormalized analytical projections only. No transactional system writes should be sourced from ClickHouse.

Redis owns ephemeral coordination only:

- idempotency response cache
- distributed locks
- short-lived rate lock cache if needed
- rate limiting counters
- configuration cache

S3 owns generated artifacts:

- settlement files
- exported reports
- reconciliation imports

---

## 9. Initial Integration Baseline

This draft fixes the baseline integration assumptions so later design artifacts can be specific:

- Messaging baseline: Kafka
  Reason: the source design already treats Kafka as the event backbone and the reporting pipeline depends on durable event streaming.
- Payment tokenization: external PCI-compliant vault
  Raw PAN never enters the application domain.
- Primary OLTP: Aurora MySQL
- Primary OLAP: ClickHouse
- Cache/locks: Redis
- Cloud platform: AWS

Still unresolved at this stage:

- exact payment processor set
- exact fraud provider
- exact FX provider
- exact deployment substrate inside AWS

Those remain open questions for later design artifacts and human review.

---

## 10. Security And Compliance Draft

The application must encode the following boundaries from day one:

- No raw card data storage or logging.
- Sensitive PII must be isolated and encrypted at rest.
- Every financial mutation must emit an audit record.
- Internal operator access must use RBAC with least privilege.
- Idempotency must be enforced for every externally retried command.
- Ledger data must be append-only.
- Reconciliation and reporting access must be permissioned and traceable.
- Cross-border and AML-relevant workflows must preserve the fields needed for regulatory reporting.

Operational implication:

- security, audit, and data-retention concerns cannot be deferred to a later phase because they affect schema boundaries and event contracts.

---

## 11. Delivery Draft

The application should be delivered in vertical slices, not horizontal infrastructure-first layers.

Likely first slices:

- merchant onboarding plus API credential issuance
- payment ingestion plus pending transaction creation
- authorization flow with idempotency and processor abstraction
- ledger posting for core payment transitions
- settlement batch generation and submission
- reconciliation exception handling
- analytics projection plus twice-daily reports
- operator dashboard for finance and operations

This is not the formal implementation plan yet. It is the intended sequencing shape that later `structure-outline.md` and `docs/plan/...` artifacts should formalize.

---

## 12. Explicit Non-Goals For The First Application Draft

- multi-region active-active deployment
- merchant self-serve portal beyond API access
- mobile applications or SDKs
- real-time streaming analytics dashboards
- cryptocurrency rails
- direct card-network connectivity

---

## 13. Open Questions Requiring Human Review

- Should the repository be bootstrapped as a monorepo with both `apps/api` and `apps/dashboard`, or should the dashboard live in a separate repository?
- Is Kafka a confirmed production dependency, or should the application stay broker-agnostic for the first implementation slices?
- Which AWS runtime is preferred for the Laravel monolith: Elastic Beanstalk, ECS on Fargate, or EC2-managed instances?
- Which external providers are in scope for the first release: processor, fraud, FX, and tokenization?
- Does the initial operator dashboard need manual reconciliation tooling, or only read access and report export?
- Should cross-border workflows be included in the first implementation milestone, or deferred until domestic transaction flows are stable?

---

## 14. Approval Gate

If this discussion document is approved, the next design step should produce:

- `architecture.md`
- `dataflow.md`
- `sequence.md`
- `contracts.md`
- `testing.md`
- `adr.md`
- `structure-outline.md`

No implementation work should start before those artifacts are written and reviewed.
