# Phase 10: Compliance Hardening And Operational Readiness

## Objective

Close the production-readiness gaps around RBAC, audit integrity, retention, alerting, and non-functional validation without widening feature scope.

## Dependencies

- Depends on: Phase 09
- Enables: Production readiness review

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `modules/Audit/Infrastructure/Integrity/AuditHashChain.php` | Tamper-evident audit chaining |
| `modules/Audit/Infrastructure/Console/ArchiveAuditLogsCommand.php` | Retention/archive job |
| `platform/terraform/observability/main.tf` | Monitoring and alert baseline |
| `platform/terraform/security/main.tf` | IAM, secrets, and KMS baseline |
| `platform/k6/transaction-ingestion.js` | Ingestion load profile |
| `apps/api/tests/Feature/Security/RbacPoliciesTest.php` | RBAC regression tests |
| `apps/api/tests/Integration/Audit/AuditIntegrityTest.php` | Audit chain tests |
| `apps/api/tests/Performance/README.md` | Performance validation instructions |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/api/config/auth.php` | Harden RBAC policy mapping |
| `apps/api/config/payflow.php` | Add retention, alert, and secrets settings |
| `apps/api/routes/console.php` | Register archive/retention jobs |
| `platform/README.md` | Add operational runbook pointers |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

- No new product-facing contracts are expected in this phase.
- Operational contracts are limited to alert thresholds, retention jobs, and audit integrity behavior already implied by design.

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Restrict reconciliation endpoint to authorized operators | Integration | `apps/api/tests/Feature/Security/RbacPoliciesTest.php` |
| Emit audit record for merchant configuration change | Integration | `apps/api/tests/Integration/Audit/AuditIntegrityTest.php` |
| Validate ingestion latency profile and retry safety | Performance | `platform/k6/transaction-ingestion.js` |

## Acceptance Criteria for This Phase

- [ ] RBAC denies unauthorized operator actions across internal APIs
- [ ] Audit records are chained or otherwise made tamper-evident per design
- [ ] Retention/archive jobs exist for long-lived audit and artifact data
- [ ] Baseline alerting exists for lag, settlement failures, and error-rate spikes
- [ ] Performance validation assets exist for ingestion SLO testing
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Feature/Security tests/Integration/Audit`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep this phase focused on hardening and readiness; do not add net-new product flows.
- If infrastructure ownership is external, Terraform files can be replaced with equivalent IaC paths during implementation, but the alerting and security scope must stay intact.
