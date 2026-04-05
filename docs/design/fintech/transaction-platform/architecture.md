# Architecture: fintech transaction platform

**Status:** Proposed for review
**Scope:** Initial application draft derived from requirements and source design

---

## 1. Context Diagram

```mermaid
graph TD
    Merchant["Merchant Systems\n(Created external actor)"]
    Ops["Operations / Finance / Compliance Users\n(Created external actor)"]
    Vault["PCI Tokenization Vault\n(Unchanged external system)"]
    Fraud["Fraud / Sanctions Service\n(Created integration)"]
    FX["FX Rate Provider\n(Created integration)"]
    Proc["Payment Processors / Banks\n(Created integration)"]
    Platform["Payflow Engine Platform\n(Created system)"]
    Reports["S3 Report and Settlement Artifacts\n(Created storage)"]

    Merchant -->|HTTPS REST + webhooks| Platform
    Ops -->|Dashboard + internal APIs| Platform
    Platform -->|token references only| Vault
    Platform -->|risk and sanctions checks| Fraud
    Platform -->|rate lock / FX quotes| FX
    Platform -->|auth/capture/settlement| Proc
    Platform -->|CSV/PDF/settlement files| Reports
```

### Notes

- `Payflow Engine Platform` is a new application to be created in this repository.
- The external tokenization vault is unchanged and remains outside platform ownership.
- Payment processors, FX providers, and fraud providers are integration boundaries, not internal modules.

---

## 2. Container Diagram

```mermaid
graph TD
    Merchant["Merchant Systems"]
    Ops["Ops / Finance Users"]

    Dashboard["Nuxt Dashboard\n(Created container)"]
    Api["Laravel API Runtime\n(Created container)"]
    Workers["Laravel Worker Runtimes\n(Created container)"]
    Scheduler["Laravel Scheduler\n(Created container)"]
    Aurora[("Aurora MySQL\n(Created container)")]
    Redis[("Redis\n(Created container)")]
    Kafka[("Kafka / MSK\n(Created container)")]
    ClickHouse[("ClickHouse\n(Created container)")]
    S3[("S3\n(Created container)")]

    Fraud["Fraud Service"]
    FX["FX Provider"]
    Proc["Processors / Banks"]
    Vault["Tokenization Vault"]

    Merchant -->|HTTPS| Api
    Ops -->|HTTPS| Dashboard
    Dashboard -->|HTTPS| Api
    Api -->|SQL read/write| Aurora
    Api -->|cache / locks| Redis
    Api -->|produce commands/events| Kafka
    Api -->|vault token validation| Vault

    Workers -->|consume / produce| Kafka
    Workers -->|SQL read/write| Aurora
    Workers -->|cache / locks| Redis
    Workers -->|query / load facts| ClickHouse
    Workers -->|store files| S3
    Workers -->|fraud checks| Fraud
    Workers -->|rate locks| FX
    Workers -->|payment and settlement calls| Proc

    Scheduler -->|dispatch jobs| Kafka
    Scheduler -->|trigger report runs| ClickHouse
    Scheduler -->|persist run metadata| Aurora
```

### Container Responsibilities

| Container | Responsibility | Change Status |
|-----------|----------------|---------------|
| Nuxt Dashboard | Internal operations UI, reconciliation review, reporting access | Created |
| Laravel API Runtime | Merchant APIs, internal APIs, status queries, authn/authz, idempotent ingress | Created |
| Laravel Worker Runtimes | Payment commands, callbacks, settlement, reconciliation, analytics projection, webhooks | Created |
| Laravel Scheduler | Recurring windows, deadline handling, report schedules, housekeeping | Created |
| Aurora MySQL | OLTP source of truth | Created |
| Redis | Idempotency cache, locks, rate limiting, config cache | Created |
| Kafka | Durable command/event backbone | Created |
| ClickHouse | Analytical read store and materialized views | Created |
| S3 | Settlement files, exports, archived audit artifacts | Created |

---

## 3. Component Diagram: Laravel API + Workers

```mermaid
graph TD
    subgraph App["Payflow Engine Application (Created container)"]
        Gateway["HTTP/API Layer\n(Created)"]
        Auth["AuthN/AuthZ + RBAC\n(Created)"]
        Merchant["Merchant Management Module\n(Created)"]
        Payments["Payment Processing Module\n(Created)"]
        FXM["FX & Cross-Border Module\n(Created)"]
        Ledger["Ledger Module\n(Created)"]
        Settlement["Settlement Module\n(Created)"]
        Analytics["Reporting & Analytics Module\n(Created)"]
        Audit["Audit Module\n(Created)"]
        Events["Event Bus Adapter\n(Created)"]
        Infra["Infrastructure Adapters\n(Created)"]
    end

    Gateway --> Auth
    Gateway --> Merchant
    Gateway --> Payments
    Gateway --> Analytics

    Payments --> Merchant
    Payments --> FXM
    Payments --> Ledger
    Payments --> Events

    Settlement --> Ledger
    Settlement --> Merchant
    Settlement --> Events

    Analytics --> Events
    Analytics --> Audit
    Merchant --> Audit
    Payments --> Audit
    Settlement --> Audit

    Infra --> Merchant
    Infra --> Payments
    Infra --> FXM
    Infra --> Ledger
    Infra --> Settlement
    Infra --> Analytics
```

### Component Boundaries

| Component | Owns | Must Not Own | Change Status |
|-----------|------|--------------|---------------|
| HTTP/API Layer | Controllers, request validation, response mapping, correlation IDs | Business rules, direct table joins across modules | Created |
| AuthN/AuthZ + RBAC | API key auth, operator auth, role checks | Domain business workflows | Created |
| Merchant Management | Merchants, credentials, fee schedules, webhook endpoints | Payment state transitions | Created |
| Payment Processing | Ingestion, state machine, processor routing, callbacks | Ledger balancing rules, reporting projections | Created |
| FX & Cross-Border | Rate locks, conversions, markup tracking | Merchant access control, reporting UI | Created |
| Ledger | Journal rules, ledger entries, account definitions | Processor integration logic | Created |
| Settlement | Batches, file generation, reconciliation records | Initial authorization/capture logic | Created |
| Reporting & Analytics | Projections, report runs, exports, dashboard query models | Transactional writes in OLTP | Created |
| Audit | Immutable audit records, access logging | Domain state transitions themselves | Created |
| Event Bus Adapter | Kafka topics, producer/consumer mapping, retry envelopes | Domain decisions | Created |
| Infrastructure Adapters | Eloquent, repository impls, HTTP clients, S3, Redis, ClickHouse | Cross-module orchestration decisions | Created |

---

## 4. Deployment Notes

- Single codebase, multiple process groups.
- Monolith boundary is logical, not networked microservices.
- Scale API, payment workers, settlement workers, and analytics workers independently.
- Aurora remains the only transactional write source.
- ClickHouse is read-only from the application perspective except analytics projection writers.

---

## 5. Out of Scope

- Multi-region topology design
- Merchant self-serve portal design beyond internal operator UI
- Detailed AWS network diagrams
- Exact third-party provider selection
