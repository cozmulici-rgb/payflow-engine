# ADR: fintech transaction platform

---

## ADR-001: Use A Modular Monolith Instead Of Microservices

**Status:** Accepted

**Context:**  
The platform must support financial transaction flows, settlement, reconciliation, and analytics with a team of roughly 8-10 engineers whose primary backend strength is Laravel and AWS.

**Decision:**  
Implement the platform as a Laravel-based modular monolith with explicit bounded contexts inside one codebase and one primary deployable application.

**Rationale:**  
This preserves strong domain boundaries without introducing service-to-service operational overhead that would be disproportionate for the team size.

**Alternatives Considered:**
- Microservices: Rejected because the team would absorb deployment, tracing, and contract-management overhead too early.
- Fully layered monolith without module boundaries: Rejected because financial and operational domains would blur quickly.

**Consequences:**
- Positive: Faster delivery, simpler ops, easier local development, fewer distributed failure modes.
- Negative: Requires strong internal discipline to preserve boundaries; some future extractions may be non-trivial.

---

## ADR-002: Keep Aurora MySQL As The OLTP System Of Record And ClickHouse As OLAP

**Status:** Accepted

**Context:**  
The workload combines financial transactional consistency with twice-daily heavy reporting and dashboard aggregation needs.

**Decision:**  
Use Aurora MySQL for transactional writes and ClickHouse for analytical reads.

**Rationale:**  
Financial writes require strong consistency and familiar relational semantics. Reporting requires an isolated read model that can absorb large aggregates without impacting OLTP.

**Alternatives Considered:**
- Aurora only: Rejected because analytical queries would contend with transactional traffic and violate stated constraints.
- Redshift or Athena for reporting: Rejected because they add cost or latency without clear advantage for the workload shape described.

**Consequences:**
- Positive: Clear workload separation and predictable query performance.
- Negative: Requires projection pipeline and watermark management.

---

## ADR-003: Use Kafka As The Command And Event Backbone

**Status:** Accepted

**Context:**  
The platform needs durable event transport, replay capability, analytics projection, and reliable downstream processing for webhooks and audit-related consumers.

**Decision:**  
Adopt Kafka as the primary broker and event log.

**Rationale:**  
Kafka aligns with durable ordered streams and replayable event-driven processing better than queue-only alternatives.

**Alternatives Considered:**
- SQS: Rejected because replay and ordering flexibility are weaker for this use case.
- RabbitMQ: Rejected because it is better suited for queue semantics than long-lived replayable event streams.

**Consequences:**
- Positive: Replay support, decoupled consumers, strong fit for OLTP-to-OLAP projection.
- Negative: Additional operational complexity and schema governance needs.

---

## ADR-004: Use Append-Only Double-Entry Ledger As The Financial Truth

**Status:** Accepted

**Context:**  
The platform needs auditable and reversible financial movements across authorizations, settlements, and refunds.

**Decision:**  
Represent financial movements through append-only double-entry ledger postings and never mutate prior ledger records.

**Rationale:**  
This is the clearest model for financial correctness, reconciliation, and auditability.

**Alternatives Considered:**
- Updating transaction balances in place: Rejected because auditability and correction semantics become weaker.
- Single-entry operational ledger: Rejected because balancing guarantees are insufficient for settlement-grade accounting.

**Consequences:**
- Positive: Strong audit trail, reversible corrections, clear accounting model.
- Negative: More careful posting design and reporting projection work required.

---

## ADR-005: Keep Raw Card Data Out Of Platform Scope

**Status:** Accepted

**Context:**  
PCI-DSS obligations materially affect architecture, storage, logging, and network boundaries.

**Decision:**  
Use an external PCI-compliant tokenization vault and store opaque payment references only.

**Rationale:**  
This reduces platform PCI scope and avoids designing a card data environment inside the application.

**Alternatives Considered:**
- Self-hosted tokenization/CDE: Rejected because it significantly increases compliance and operational burden.
- Storing encrypted PAN directly: Rejected because the platform would still assume unacceptable PCI risk and scope.

**Consequences:**
- Positive: Reduced compliance exposure and simpler application data model.
- Negative: Hard dependency on tokenization provider availability and contract quality.

---

## ADR-006: Split Runtime Into Dedicated Process Groups Inside One Application

**Status:** Accepted

**Context:**  
HTTP ingress, payment processing, settlement, and analytics have different scaling and latency characteristics.

**Decision:**  
Run separate API, payment worker, settlement worker, analytics worker, and scheduler process groups from the same codebase.

**Rationale:**  
This preserves the simplicity of a monolith while allowing operational isolation where the workload requires it.

**Alternatives Considered:**
- Single generic worker pool: Rejected because payment and reporting workloads would contend unnecessarily.
- Separate deployable services: Rejected because it would erode the monolith decision too early.

**Consequences:**
- Positive: Better scaling, clearer operational ownership, reduced noisy-neighbor effects.
- Negative: More deployment roles and queue routing conventions to manage.
