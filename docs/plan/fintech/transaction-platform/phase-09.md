# Phase 09: Operator Dashboard

## Objective

Expose the already-implemented operator workflows through the Nuxt dashboard without moving business logic out of the API application.

## Dependencies

- Depends on: Phase 08
- Enables: Phase 10

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/dashboard/pages/index.vue` | Dashboard landing page |
| `apps/dashboard/pages/merchants/index.vue` | Merchant list/create view |
| `apps/dashboard/pages/reconciliation/index.vue` | Exception review view |
| `apps/dashboard/pages/reports/index.vue` | Report runs and downloads view |
| `apps/dashboard/components/merchants/MerchantCreateForm.vue` | Merchant form |
| `apps/dashboard/components/reconciliation/ExceptionTable.vue` | Exception table |
| `apps/dashboard/components/reports/ReportRunTable.vue` | Report run table |
| `apps/dashboard/composables/useApiClient.ts` | Shared API client |
| `apps/dashboard/composables/useSession.ts` | Operator session state |
| `apps/dashboard/middleware/auth.ts` | Route auth guard |
| `apps/dashboard/tests/merchants.spec.ts` | Merchant UI tests |
| `apps/dashboard/tests/reconciliation.spec.ts` | Reconciliation UI tests |
| `apps/dashboard/tests/reports.spec.ts` | Reporting UI tests |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `apps/dashboard/nuxt.config.ts` | Add runtime config and test config |
| `apps/dashboard/app.vue` | Add shell navigation and session bootstrap |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

- No new backend contracts should be introduced in this phase unless the API design artifacts already require them.
- UI work must consume the internal endpoints defined in `contracts.md` and implemented in earlier phases.

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Operator can create merchants from the UI | E2E/UI | `apps/dashboard/tests/merchants.spec.ts` |
| Operator can review unresolved exceptions | E2E/UI | `apps/dashboard/tests/reconciliation.spec.ts` |
| Operator can inspect report runs and artifacts | E2E/UI | `apps/dashboard/tests/reports.spec.ts` |

## Acceptance Criteria for This Phase

- [ ] Operator can authenticate into the dashboard and reach merchant, reconciliation, and reporting views
- [ ] Merchant creation UI calls the existing internal merchant APIs successfully
- [ ] Reconciliation UI lists unresolved exceptions and resolution actions
- [ ] Reporting UI lists report runs and artifact links
- [ ] Relevant tests pass: `cd apps/dashboard && pnpm test`
- [ ] Lint/static checks pass: `cd apps/dashboard && pnpm lint && pnpm typecheck`

## Implementation Notes

- Keep business rules in the API; the dashboard should be a consumer, not a duplicate implementation.
- Avoid introducing additional dashboard routes beyond the approved scope.
