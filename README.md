# AI Personal Finance Tracker

> **One Money, Multiple Accounts, Complete Financial Visibility.**

A personal-finance ledger for Indonesian users (IDR, Bahasa Indonesia by default) built on **double-entry accounting**. Income, expenses, internal transfers and liabilities are always distinguished correctly, so moving money from BCA to BNI never inflates your income or expenses.

**Stack:** PHP 8.5 · Laravel 13 · Sanctum · MySQL 8.4 (InnoDB) · modular monolith · no Docker.

## Status

| Phase | Scope | Status |
|---|---|---|
| 1 Foundation | Auth, migrations, accounts, chart of accounts, double-entry ledger, income/expense/transfer, reversal, idempotency, summary report, tests | ✅ Implemented and tested (SQLite); MySQL run pending, see below |
| 2 Dashboard | Blade/Livewire UI, categories CRUD, budgets, reports | ⬜ |
| 3 AI & Telegram | NL parser, confirmation flow, Telegram bot | ⬜ |
| 4 Extension & Imports | Chrome extension, CSV/XLSX/PDF import, reconciliation | ⬜ |
| 5 Advanced finance | Card statements, installments, recurring, forecasting | ⬜ |

## Quick start (local)

```bash
composer install
cp .env.example .env && php artisan key:generate     # set DB_* (or DB_CONNECTION=sqlite)
cp .env.testing.example .env.testing                 # set APP_KEY; SQLite in-memory by default
php artisan migrate
php artisan serve
php artisan test
```

### Running the MySQL/MariaDB integration tests

The concurrency tests (`tests/Integration/ConcurrentPostingTest.php`) need real row locks and are **skipped on SQLite**. Before a release:

1. Create a dedicated database user and an empty `pft_test` database. Never use `root`.
2. In `.env.testing`, switch to the commented `DB_CONNECTION=mysql|mariadb` block.
3. `php artisan test`. Tests run `migrate:fresh` on `pft_test`.

## Accounting model (summary)

| Operation | Debit | Credit | Type |
|---|---|---|---|
| Salary into BCA | BCA (asset) | Income: Salary | `INCOME` |
| Lunch paid in cash | Expense: Food | Cash (asset) | `EXPENSE` |
| BCA → BNI / BCA → Cash | BNI / Cash | BCA | `INTERNAL_TRANSFER` |
| Credit-card purchase | Expense | Card (liability) | `EXPENSE` |
| Card payment / loan principal | Card / Loan (liability) | Bank | `LIABILITY_PAYMENT` |
| Loan disbursement | Bank | Loan (liability) | `LIABILITY_DRAWDOWN` |
| Correction | mirror of the original | | original → `REVERSED` |

Full design, ERD and posting algorithm: [`docs/architecture/01-phase1-ledger-design.md`](docs/architecture/01-phase1-ledger-design.md).

Guarantees:

- Σdebit = Σcredit for every entry, checked in a locked DB transaction (plus a DB `CHECK` constraint on MySQL).
- Money is `DECIMAL(20,2)` and `bcmath`. JSON floats are rejected.
- Posted journals and transactions are immutable. Corrections post a reversal entry.
- Every posting endpoint requires an `Idempotency-Key`.
- Balances are a ledger projection, verified daily by `php artisan ledger:verify`.

## API

Base URL `/api/v1`, Bearer token (Sanctum). Contract: [`docs/api/openapi.yaml`](docs/api/openapi.yaml).

```bash
curl -X POST /api/v1/transactions/transfer \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: trf-2026-11-17-001" \
  -H "Content-Type: application/json" \
  -d '{"amount": "2000000", "source_account_id": "<BCA id>", "destination_account_id": "<BNI id>"}'
```

## Artisan commands

| Command | Purpose | Schedule |
|---|---|---|
| `ledger:verify [--user=<public id>]` | Balanced journals, projection = Σlines, tenant isolation, transaction↔journal links | daily 02:00 WIB |
| `finance:prune` | Delete expired idempotency keys and audit logs past retention | daily 03:00 WIB |

## Environment variables

See [`.env.example`](.env.example). App-specific variables:

| Variable | Default | Meaning |
|---|---|---|
| `FINANCE_DISPLAY_TIMEZONE` | `Asia/Jakarta` | "Today" and display timezone (storage is UTC) |
| `FINANCE_DEFAULT_CURRENCY` | `IDR` | Default currency |
| `IDEMPOTENCY_TTL_HOURS` | `72` | How long idempotency keys are honoured |
| `AUDIT_RETENTION_DAYS` | `2555` | Audit-log retention (0 = forever) |
| `SANCTUM_TOKEN_EXPIRATION` | `43200` | API token lifetime in minutes |

## Project layout

```
app/Modules/
  Shared/        Money, DomainException, MoneyAmount rule, DateOnly cast, finance:prune
  Identity/      Registration/login, user provisioning
  Ledger/        Chart of accounts, LedgerPostingService (the only writer), integrity verifier
  Accounts/      Financial accounts & opening balances
  Categories/    Income/expense categories, merchants
  Transactions/  Income, expense, transfer, reversal, idempotency
  Reporting/     Financial summary
  Audit/         Audit trail
docs/            Architecture, OpenAPI, security checklist, Ubuntu deployment
tests/           Unit, Feature (ledger, accounting rules, API), Integration (salary simulation, concurrency)
```

## Further docs

- Security & ISO/IEC 27001 checklist: [`docs/security-checklist.md`](docs/security-checklist.md)
- Deployment with **Dokploy** (Docker Compose: web, worker, scheduler, MySQL 8.4): [`docs/deployment/dokploy.md`](docs/deployment/dokploy.md)
- Bare-metal Ubuntu deployment (no Docker): [`docs/deployment/ubuntu.md`](docs/deployment/ubuntu.md)
