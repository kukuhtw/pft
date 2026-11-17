# Security & Data-Protection Checklist

Mapped to ISO/IEC 27001:2022 Annex A. Status: ✅ implemented (Phase 1) · 🟡 partial · ⬜ planned / ops task.

## Application controls

| Control | Requirement | Status | Where |
|---|---|---|---|
| A.8.5 | Passwords hashed (bcrypt, `BCRYPT_ROUNDS=12`) | ✅ | `User` `hashed` cast |
| A.8.5 | Password policy: ≥12 chars, mixed case, numbers | ✅ | `AppServiceProvider` `Password::defaults` |
| A.8.5 | API tokens expire (`SANCTUM_TOKEN_EXPIRATION`, default 30 days); logout revokes | ✅ | `config/sanctum.php`, `AuthController` |
| A.8.5 | Login rate limit 5/min per email+IP, 30/min per IP; no account enumeration | ✅ | `throttle:auth`, `AuthController::login` |
| A.8.3 | Tenant isolation: owner-scoped look-ups, policies returning 404, posting service re-checks ledger ownership | ✅ | Policies, `LedgerPostingService::lockAccounts`, tests `test_12_*` |
| A.8.11 | Data masking: only the last 4 digits of account/card numbers are accepted and stored | ✅ | `StoreFinancialAccountRequest` |
| A.8.15 | Audit log for registration, login (incl. failures), account opening, posting, reversal | ✅ | `AuditLogger` (secrets redacted) |
| A.8.10 | Retention: idempotency keys TTL, audit-log retention (`AUDIT_RETENTION_DAYS`) | ✅ | `finance:prune` (scheduled daily) |
| A.8.10 | Data export (UU PDP right of access/portability): full JSON download + transaction CSV, audit-logged, rate-limited | ✅ | `ExportController` |
| A.8.10 | User account deletion (UU PDP right to erasure) | ⬜ | Needs a decision: ledger rows are immutable and use `RESTRICT`, so erasure means anonymising the user and counterparties while keeping balanced books |
| A.8.28 | CSV exports neutralise spreadsheet formula injection (`=`, `+`, `-`, `@` prefixed with `'`) | ✅ | `ExportController::cell` |
| A.5.17 / A.8.5 | Password change requires the current password, revokes all API tokens and other DB sessions, and regenerates the session | ✅ | `Livewire\Profile` |
| A.8.5 | Web login: 5/min per email+IP, 30/min per IP, generic error, session regeneration, audit log | ✅ | `Livewire\Auth\Login` |
| A.8.24 | Production must set `SESSION_SECURE_COOKIE=true` behind HTTPS | ⬜ | ops |
| A.8.28 | Input validation on all endpoints; no float money; no raw SQL interpolation | ✅ | FormRequests, `MoneyAmount`, query builder bindings |
| A.8.28 | CSRF (web routes) | ✅ | Laravel default (web middleware) |
| A.8.28 | XSS: JSON API only in Phase 1; Blade auto-escaping for Phase 2 UI | 🟡 | — |
| A.8.12 | No financial data sent to third parties in Phase 1 | ✅ | AI is Phase 3 and must minimise/pseudonymise payloads |
| A.8.11 | Counterparties (third-party personal data): only a display name + aliases are stored, tenant-private; no phone, NIK or account numbers | ✅ | `counterparties` table, `CounterpartyService` |
| A.8.11 | Names guessed from descriptions are never auto-created as counterparties (kept only as `counterparty_guess` on the entry) | ✅ | `TransactionService::resolveCounterparty` |
| A.8.12 / A.5.34 | Entry interpretation runs locally (`HeuristicTextInterpreter`). `/transactions/interpret` text is not stored or logged | ✅ | `EntryInterpretationService` |
| A.5.34 / A.5.19 | Before binding an LLM `TextInterpreter`: user consent, DPA with a vetted processor, data-residency review (UU PDP), pseudonymise counterparty names, no-retention setting, and never call it inside the posting transaction | ⬜ | Phase 3 |
| A.8.5 / A.8.20 | Telegram webhook authenticated with `X-Telegram-Bot-Api-Secret-Token` (constant-time compare, ≥32 chars); endpoint off (404) while unconfigured | ✅ | `TelegramWebhookController` |
| A.5.16 / A.8.5 | Telegram linking: 8-char single-use code, 10-min TTL, only its hash cached, new code revokes the old one; 5 tries/chat/10 min; private chats only; password change unlinks | ✅ | `TelegramLinkService`, `Livewire\Profile` |
| A.8.3 | Telegram callbacks may only pick accounts recomputed for the linked user; nothing posts without ✅ Simpan; per-draft idempotency key | ✅ | `TelegramBotService` |
| A.8.11 / A.8.15 | Telegram: only `chat_id` stored (no username/phone); drafts in cache 15 min; message text never logged; bot token never logged; link/unlink audited | ✅ | `telegram_links`, `TelegramClient` |
| A.5.19 / A.5.34 | Telegram is a third-party processor outside Indonesia: entries, names and `/saldo` replies transit its servers. Needs privacy-notice disclosure, user consent and a cross-border transfer record (UU PDP) before go-live | ⬜ | ops / legal |
| A.8.24 | Secrets only in `.env` (git-ignored); `.env.example` has no values | ✅ | `.gitignore` |
| A.8.24 | Encrypted sessions (`SESSION_ENCRYPT=true` in `.env.example`) | ✅ | |
| — | Integrity: balanced journals, immutable postings, idempotent posting, daily `ledger:verify` | ✅ | Ledger module |
| — | Error responses never expose stack traces/model names (`APP_DEBUG=false` in prod) | 🟡 | Error envelope ✅; prod `.env` is an ops task |

## Operational controls (deployment)

| Control | Requirement | Status |
|---|---|---|
| A.8.20/8.21 | HTTPS only (TLS 1.2+), HSTS, Nginx security headers | ⬜ see `docs/deployment/ubuntu.md` |
| A.8.2 | Dedicated least-privilege DB user; **never `root` with an empty or shared password** | ⬜ |
| A.8.13 | Encrypted daily DB backups, off-site copy, quarterly restore test | ⬜ |
| A.8.9 | `APP_DEBUG=false`, `APP_ENV=production`, file permissions 640/750 | ⬜ |
| A.8.16 | Monitoring: alert on `ledger:verify` failure (CRITICAL log) | ⬜ |
| A.8.8 | `composer audit` in CI; OS patching | ⬜ |

## Known non-compliance findings (local development environment)

1. **XAMPP MariaDB 10.4.32** is end-of-life and is not the target MySQL 8.4 LTS. Use it for local development only. Run the release test suite against MySQL 8.4.
2. **XAMPP stores its root password in `C:\xampp\passwords.txt`** (plain text). Rotate it, delete the file, and use the dedicated `pft` DB user.
3. `.env` / `.env.testing` contain a locally generated DB password. They are git-ignored; never copy them to shared locations.
