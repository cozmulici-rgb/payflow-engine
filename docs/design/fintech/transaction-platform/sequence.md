# Sequence Diagrams: fintech transaction platform

**Status:** Proposed for review

---

## 1. Primary Success Scenario: Transaction Ingestion To Authorization

```mermaid
sequenceDiagram
    participant Merchant
    participant API as Laravel API
    participant Redis
    participant Aurora
    participant Kafka
    participant Worker as Payment Worker
    participant Fraud
    participant FX
    participant Processor
    participant Ledger

    Merchant->>API: POST /v1/transactions
    API->>Redis: GET idempotency_key
    Redis-->>API: miss
    API->>Aurora: INSERT transaction(status=pending)
    API->>Aurora: INSERT transaction_status_history
    API->>Kafka: Publish ProcessTransaction
    API->>Redis: Cache accepted response
    API-->>Merchant: 202 Accepted

    Kafka->>Worker: ProcessTransaction
    Worker->>Aurora: Check processed_events
    Worker->>FX: Lock rate if cross-border
    FX-->>Worker: Rate lock
    Worker->>Fraud: Screen transaction
    Fraud-->>Worker: Pass
    Worker->>Processor: Authorize payment
    Processor-->>Worker: Approved
    Worker->>Aurora: UPDATE transaction -> authorized
    Worker->>Ledger: Post journal entry
    Ledger->>Aurora: INSERT ledger_entries
    Worker->>Aurora: INSERT processed_events + status history
    Worker->>Kafka: Publish TransactionAuthorized
```

---

## 2. Duplicate Request Scenario

```mermaid
sequenceDiagram
    participant Merchant
    participant API as Laravel API
    participant Redis
    participant Aurora

    Merchant->>API: POST /v1/transactions with same idempotency key
    API->>Redis: GET idempotency_key
    alt Cached response exists
        Redis-->>API: prior response
        API-->>Merchant: 202 Accepted (same transaction reference)
    else Cache miss but prior record exists
        Redis-->>API: miss
        API->>Aurora: SELECT transaction by idempotency key
        Aurora-->>API: existing transaction
        API-->>Merchant: 202 Accepted (same transaction reference)
    end
```

---

## 3. Fraud Rejection Scenario

```mermaid
sequenceDiagram
    participant Worker as Payment Worker
    participant Aurora
    participant Fraud
    participant Kafka

    Worker->>Fraud: Screen transaction
    Fraud-->>Worker: Reject with score/reason
    Worker->>Aurora: UPDATE transaction -> failed
    Worker->>Aurora: INSERT transaction_status_history
    Worker->>Aurora: INSERT audit record
    Worker->>Kafka: Publish TransactionFailed(reason=fraud_rejected)
```

---

## 4. Processor Timeout With Status Inquiry

```mermaid
sequenceDiagram
    participant Worker as Payment Worker
    participant Processor
    participant Aurora
    participant Kafka

    Worker->>Processor: Authorize payment
    Processor--xWorker: timeout
    Worker->>Processor: Retry authorize with same idempotency key
    Processor--xWorker: timeout
    Worker->>Processor: Status inquiry
    alt Processor confirms approved
        Processor-->>Worker: approved
        Worker->>Aurora: UPDATE transaction -> authorized
        Worker->>Kafka: Publish TransactionAuthorized
    else Processor status unknown
        Processor-->>Worker: unknown / unavailable
        Worker->>Aurora: UPDATE transaction -> failed(error=processor_timeout)
        Worker->>Kafka: Publish TransactionFailed
    end
```

---

## 5. Settlement Success Scenario

```mermaid
sequenceDiagram
    participant Scheduler
    participant Settlement as Settlement Worker
    participant Aurora
    participant S3
    participant Processor
    participant Ledger
    participant Kafka

    Scheduler->>Settlement: Run settlement window
    Settlement->>Aurora: Query eligible captured transactions
    Settlement->>Aurora: INSERT settlement_batch + items
    Settlement->>S3: Upload settlement file
    Settlement->>Processor: Submit settlement file / request
    Processor-->>Settlement: Submission accepted
    Settlement->>Aurora: UPDATE batch -> submitted
    Processor-->>Settlement: Confirmation / report
    Settlement->>Aurora: Reconcile items
    Settlement->>Ledger: Post settlement journal entries
    Ledger->>Aurora: INSERT ledger_entries
    Settlement->>Aurora: UPDATE batch -> confirmed/reconciled
    Settlement->>Kafka: Publish TransactionSettled events
```

---

## 6. Reporting Scenario With Staleness Check

```mermaid
sequenceDiagram
    participant Scheduler
    participant Reporter as Reporting Worker
    participant Aurora
    participant ClickHouse
    participant S3
    participant Dashboard

    Scheduler->>Reporter: Generate scheduled reports
    Reporter->>Aurora: Read expected cutoff metadata
    Reporter->>ClickHouse: Read latest projected watermark
    alt Watermark fresh enough
        Reporter->>ClickHouse: Run report queries
        ClickHouse-->>Reporter: Aggregated results
        Reporter->>S3: Store CSV/PDF artifacts
        Reporter->>Aurora: Record report_run success
        Dashboard->>S3: Access generated artifacts
    else Watermark stale
        Reporter->>Aurora: Record delayed report_run
        Reporter-->>Scheduler: Raise alert / postpone run
    end
```
