# Data Flow: fintech transaction platform

**Status:** Proposed for review

---

## 1. Primary Transaction Ingestion And Authorization

```mermaid
flowchart TD
    A[Merchant POST /transactions] --> B[API Gateway / Controller]
    B --> C[Validate request + authenticate merchant]
    C --> D{Idempotency key seen?}
    D -->|Yes| E[Return cached or persisted prior response]
    D -->|No| F[Persist pending transaction in Aurora]
    F --> G[Write status history]
    G --> H[Publish ProcessTransaction command to Kafka]
    H --> I[Return 202 Accepted]

    H --> J[Payment Worker consumes command]
    J --> K{Already processed?}
    K -->|Yes| L[Drop duplicate and record audit]
    K -->|No| M{Cross-border?}
    M -->|Yes| N[Request rate lock from FX provider]
    M -->|No| O[Fraud screening]
    N --> O
    O --> P{Fraud passes?}
    P -->|No| Q[Mark failed in Aurora]
    Q --> R[Publish TransactionFailed]
    P -->|Yes| S[Call processor authorization API]
    S --> T{Processor approved?}
    T -->|Yes| U[Update transaction authorized]
    U --> V[Create ledger journal and entries]
    V --> W[Publish TransactionAuthorized]
    T -->|No| X[Update transaction failed]
    X --> Y[Publish TransactionFailed]
```

### Error Paths

- Validation failure returns `422` before any write.
- Authentication failure returns `401` or `403` before any write.
- Duplicate idempotency returns prior accepted result without re-running authorization.
- FX provider failure causes transaction failure or retry based on lock acquisition policy.
- Fraud rejection stops processor calls entirely.
- Processor timeout triggers retry or status inquiry flow before terminal failure.

---

## 2. Settlement And Reconciliation

```mermaid
flowchart TD
    A[Scheduler triggers settlement window] --> B[Settlement worker queries eligible transactions]
    B --> C[Group by processor + currency + window]
    C --> D[Create SettlementBatch in Aurora]
    D --> E[Generate settlement file]
    E --> F[Store encrypted file in S3]
    F --> G[Transmit file to processor or bank]
    G --> H{Submission accepted?}
    H -->|No| I[Mark batch exception + alert]
    H -->|Yes| J[Mark batch submitted]
    J --> K[Await confirmation / report]
    K --> L[Import processor response]
    L --> M[Match against batch items]
    M --> N{Discrepancies found?}
    N -->|Yes| O[Create reconciliation exceptions]
    O --> P[Expose exception workflow to dashboard]
    N -->|No| Q[Finalize settlement ledger entries]
    Q --> R[Mark batch confirmed or reconciled]
    R --> S[Publish TransactionSettled events]
```

### Error Paths

- Missing or malformed processor report moves the batch to `exception`.
- S3 write failure blocks submission and keeps batch non-submitted.
- Partial confirmation creates reconciliation exceptions instead of silent completion.
- Manual resolution writes explicit audit records and follow-up state transitions.

---

## 3. OLTP To OLAP Reporting Pipeline

```mermaid
flowchart TD
    A[Domain events from API / workers] --> B[Kafka topics]
    B --> C[Analytics projection workers]
    C --> D[Transform event to analytical fact]
    D --> E[Insert fact into ClickHouse]
    E --> F[Refresh materialized aggregates]
    F --> G[Scheduled report job]
    G --> H[Query ClickHouse views]
    H --> I[Render CSV / PDF]
    I --> J[Store artifact in S3]
    J --> K[Dashboard and internal users access reports]
```

### Error Paths

- Consumer lag beyond threshold delays report generation and triggers alerting.
- Projection failure dead-letters the event and keeps OLTP processing unaffected.
- Stale ClickHouse watermark causes report jobs to delay rather than generate incomplete output.

---

## 4. Cross-Cutting Data Handling Rules

| Data Type | Source of Truth | Transit | Derived Storage | Notes |
|-----------|-----------------|---------|-----------------|-------|
| Transactions | Aurora | HTTP, Kafka | ClickHouse facts | Writes only in OLTP |
| Ledger entries | Aurora | Kafka events | Reporting aggregates | Append-only |
| FX locks | Aurora | Worker/provider API | ClickHouse facts | Time-bounded |
| Reports | ClickHouse query output | Scheduler/worker | S3 | Export-only artifacts |
| Audit records | Aurora audit tables | Kafka optional | S3 archive | Immutable |
| Idempotency cache | Redis | API runtime | None | Ephemeral |

---

## 5. Security-Sensitive Flows

- Raw PAN never enters the platform.
- PII-bearing requests must be encrypted in transit and stored with field-level protection where required.
- Every operator-triggered manual action emits an audit event.
- Reconciliation imports are treated as untrusted input and must be validated before persistence.
