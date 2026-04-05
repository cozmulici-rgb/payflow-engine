# Phase 01: Platform Skeleton And Merchant Access

## Objective

Bootstrap the repository into the approved monorepo shape and deliver the first working operator-visible slice: merchant creation with authentication, authorization, correlation IDs, health endpoints, and audit logging.

## Dependencies

- Depends on: None
- Enables: Phase 02

## Exact File Changes

### Files to Create
| File Path | Purpose |
|-----------|---------|
| `apps/api/composer.json` | Laravel API dependency manifest |
| `apps/api/artisan` | Laravel CLI entrypoint |
| `apps/api/bootstrap/app.php` | App bootstrap |
| `apps/api/routes/api.php` | API route registration |
| `apps/api/routes/console.php` | Scheduler command registration |
| `apps/api/config/auth.php` | Auth baseline |
| `apps/api/config/octane.php` | Octane runtime configuration |
| `apps/api/config/payflow.php` | App-specific module and runtime configuration |
| `apps/api/database/migrations/0001_01_01_000001_create_merchants_table.php` | Merchant table |
| `apps/api/database/migrations/0001_01_01_000002_create_api_credentials_table.php` | Merchant credential table |
| `apps/api/database/migrations/0001_01_01_000003_create_users_roles_tables.php` | Operator auth/RBAC baseline |
| `apps/api/database/migrations/0001_01_01_000004_create_audit_log_table.php` | Immutable audit log baseline |
| `modules/MerchantManagement/Domain/Merchant.php` | Merchant aggregate |
| `modules/MerchantManagement/Application/CreateMerchant/CreateMerchantCommand.php` | Merchant creation command |
| `modules/MerchantManagement/Application/CreateMerchant/CreateMerchantHandler.php` | Merchant creation use case |
| `modules/MerchantManagement/Infrastructure/Persistence/EloquentMerchant.php` | Merchant persistence model |
| `modules/MerchantManagement/Infrastructure/Persistence/MerchantRepository.php` | Merchant repository implementation |
| `modules/MerchantManagement/Interfaces/Http/CreateMerchantController.php` | Internal merchant creation endpoint |
| `modules/MerchantManagement/Interfaces/Http/IssueApiCredentialController.php` | Merchant credential issuance endpoint |
| `modules/Shared/Interfaces/Http/HealthController.php` | Health/readiness endpoints |
| `modules/Shared/Infrastructure/Http/CorrelationIdMiddleware.php` | Correlation ID propagation |
| `modules/Audit/Application/WriteAuditRecord.php` | Audit write use case |
| `modules/Audit/Infrastructure/Persistence/AuditLogWriter.php` | Audit persistence adapter |
| `apps/api/tests/Feature/Internal/CreateMerchantTest.php` | Merchant creation integration test |
| `apps/api/tests/Feature/Internal/IssueApiCredentialTest.php` | Credential issuance integration test |
| `apps/api/tests/Feature/System/HealthCheckTest.php` | Health endpoint test |
| `apps/api/tests/Unit/Audit/WriteAuditRecordTest.php` | Audit writer unit test |
| `apps/dashboard/package.json` | Nuxt dashboard package manifest |
| `apps/dashboard/nuxt.config.ts` | Dashboard bootstrap |
| `apps/dashboard/app.vue` | Dashboard shell |
| `platform/docker-compose.yml` | Local dependency baseline for MySQL, Redis, Kafka |
| `platform/README.md` | Local dev instructions |

### Files to Modify
| File Path | What Changes |
|-----------|-------------|
| `README.md` | Add workspace bootstrap instructions if a root README is created in this phase |
| `.gitignore` | Add framework and build ignores if missing |

### Files to Delete
| File Path | Reason |
|-----------|--------|
| None | No deletions planned |

## Interface & Contract Changes

```php
interface AuditLogWriter
{
    public function append(AuditRecord $record): void;
}
```

## Tests to Add / Modify

| Test Case | Type | File to Create/Modify |
|-----------|------|----------------------|
| Create pending merchant access baseline | Integration | `apps/api/tests/Feature/Internal/CreateMerchantTest.php` |
| Emit audit record for merchant configuration change | Integration | `apps/api/tests/Feature/Internal/IssueApiCredentialTest.php` |
| Health and readiness endpoints respond | Integration | `apps/api/tests/Feature/System/HealthCheckTest.php` |
| Audit writer persists immutable records | Unit | `apps/api/tests/Unit/Audit/WriteAuditRecordTest.php` |

## Acceptance Criteria for This Phase

- [ ] API and dashboard applications can boot from the repo layout approved in design
- [ ] Internal operator can create a merchant through an authenticated endpoint
- [ ] Merchant API credentials can be issued and stored securely
- [ ] Correlation IDs are attached to requests and persisted into audit records
- [ ] Health and readiness endpoints return success for local runtime checks
- [ ] Relevant tests pass: `cd apps/api && php artisan test tests/Feature/Internal tests/Feature/System tests/Unit/Audit`
- [ ] Lint/static checks pass: `cd apps/api && composer phpstan && composer pint -- --test`

## Implementation Notes

- Keep operator auth intentionally minimal in this phase: enough for internal endpoints and RBAC enforcement, not full production SSO.
- Do not add transaction tables or payment logic here; Phase 02 owns the first payment-facing slice.
- If framework scaffolding generates extra boilerplate files, keep them isolated under `apps/api` and `apps/dashboard`.
