# Payflow Engine

Phase 01 bootstrap for the fintech transaction platform.

## Layout

- `apps/api`: lightweight PHP API shell with custom bootstrap and test runner
- `apps/dashboard`: dashboard scaffold
- `modules/*`: domain and application modules
- `platform/*`: local development assets

## Run tests

```bash
cd apps/api
php artisan test
```

## Notes

- This phase intentionally provides a minimal runtime because the repo started with design documents only.
- The directory boundaries follow the approved design and plan artifacts so later phases can deepen the implementation without reshaping the repo.
