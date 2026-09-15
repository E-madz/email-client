# CLAUDE.md — Lightweight Web-based Email Client (MVP)

This file guides Claude (and any other agent/developer) working in this repository. Read it before making changes. It is a living document — update it at the end of each phase.

> All open questions from the initial draft have been resolved (see §8, Decision Log). Decisions here can still change per phase — update this file whenever a decision changes and log it in §8.

---

## 1. Project Overview

- **Name:** Lightweight Web-based Email Client (MVP)
- **Goal:** Fast, responsive, functional webmail client, built in a strict **2-day** timeline.
- **Strategy:** MVP-first. No scope creep beyond what each phase requires.
- **Auth model:** two distinct layers — see §4.1:
  1. **App-level auth:** standard Laravel session/token auth against a `users` table. A user must be logged in to the app.
  2. **Mail-level auth:** Basic Auth only — direct IMAP/SMTP login with username + password for each connected mailbox. **No OAuth 2.0, no autodiscovery** in this version.
- **Primary/reference mail provider:** IranServer (`mail.iranserver.com`), used for building and testing Phase 1–2. The system must stay provider-agnostic in design (see §3.2) even though only IranServer is wired up and tested in this MVP.
- **Connecting an email account is zero-config for the user:** they enter only their email address and mailbox password. The backend does **not** try to detect a provider from the email's domain (IranServer is shared hosting — arbitrary customer domains are hosted there, so domain-name matching would be meaningless without a live MX/DNS lookup, which is exactly the Autodiscovery this MVP explicitly excludes). Instead, every email address is routed to the single **default** mail server profile (`mail_servers.is_default = true`, seeded as IranServer). See §3.2.

### Non-goals (explicitly out of scope for MVP)
- OAuth 2.0 / Google & Microsoft login
- Autodiscovery (Thunderbird-style DNS/SRV lookup)
- Real-time push (IMAP IDLE) — polling only, if even needed by Phase 2
- Multi-language / i18n
- Attachments beyond basic download/upload (unless a phase explicitly says otherwise)
- Search, labels/rules, threading — unless added to the roadmap later

---

## 2. Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.3 |
| Framework | Laravel 13 |
| Database | MySQL (dev default in this repo's `backend/.env.example` is currently SQLite — **switch to MySQL locally**, see §7) |
| IMAP | [`webklex/laravel-imap`](https://github.com/Webklex/laravel-imap) |
| SMTP | Laravel Mailer (Symfony Mailer under the hood), port 465 / SSL |
| Credential encryption | `Illuminate\Support\Facades\Crypt` (reversible — required so the app can re-authenticate to IMAP/SMTP on the user's behalf) |
| Frontend | Vue 3 (Composition API `<script setup>`) |
| State management | Pinia (multi-account state) |
| CSS | Tailwind CSS |

Repo layout:
```
email-client/
├── backend/          # Laravel 13 API application
└── frontend/         # Vue 3 SPA (created in Phase 3), separate Vite app calling the Laravel API
```

**Decided:** `frontend/` is a separate Vite + Vue 3 SPA that talks to the Laravel backend purely over a JSON API (no Blade/Inertia glue).

---

## 3. Architecture & Standards

### 3.1 Layered architecture
- **Controllers are thin.** They validate the request (via Form Requests), call a service, and return a Resource/JSON response. No business logic, no direct IMAP/SMTP/Eloquent query building in controllers.
- **Service layer** owns business logic and all protocol interaction, e.g.:
  - `App\Services\Mail\EmailAuthService` — validates credentials against a mail server profile by performing a live IMAP handshake.
  - `App\Services\Mail\ImapClientService` (Phase 2) — fetch folders/messages via `webklex/laravel-imap`.
  - `App\Services\Mail\SmtpMailService` (Phase 4) — compose/send via Laravel Mailer.
- **Repositories:** not required for MVP — Eloquent models are the persistence layer. Only introduce a repository if a service needs to swap storage strategies (unlikely in this timeframe).
- **Form Requests** for all input validation (`StoreEmailAccountRequest`, etc.) — never validate inline in controllers.
- **API Resources** (`JsonResource`) for all responses that cross the API boundary — never return raw Eloquent models or arrays with secrets.

### 3.2 Extensible mail-server architecture
Even though only IranServer is implemented and tested now, the schema and code must not hardcode IranServer assumptions.

- A `mail_servers` table holds **provider profiles** (host/port/encryption per protocol), seeded with IranServer. Adding Gmail (App Password) or another host later = a new seed row + no code changes to auth/fetch/send flow.
- `email_accounts` reference a `mail_server_id` (nullable FK for "custom/manual" servers where a user types their own host/port) rather than embedding host/port directly per account, unless a phase explicitly asks for per-account overrides — in which case store overrides nullable and fall back to the linked `mail_servers` profile.
- Protocol-specific logic lives behind interfaces (`ImapClientInterface`, future `SmtpTransportInterface` if needed) so provider quirks (e.g. Gmail requiring App Passwords, different folder naming like `[Gmail]/Sent Mail` vs `Sent`) are isolated, not `if ($provider === 'iranserver')` branches sprinkled through services.

**Decided — `mail_servers` columns (implemented):**

| Column | Type | Notes |
|---|---|---|
| `name` | `VARCHAR(100)` | Display name, e.g. `IranServer` |
| `slug` | `VARCHAR(50)`, unique | e.g. `iranserver` |
| `imap_host` / `smtp_host` | `VARCHAR(255)` | |
| `imap_port` / `smtp_port` | `SMALLINT UNSIGNED`, default `993` / `465` | |
| `imap_encryption` / `smtp_encryption` | `ENUM('ssl','tls')`, default `ssl` | Closed set enforced at the DB level — only two values IMAP/SMTP libraries actually support |
| `is_active` | `BOOLEAN`, default `true` | |
| `is_default` | `BOOLEAN`, default `false` | **Exactly one row should be `true` in the MVP** — enforced at the application/seeder level, not via a DB constraint (MySQL has no clean partial-unique-index for this without a generated-column trick, which would be over-engineering for a single-provider MVP) |
| `notes` | `TEXT`, nullable | |

No `domains` column — see the zero-config note in §1; domain-to-provider matching isn't implemented in this MVP.

### 3.3 Incremental database design
- Create migrations **only for what the current phase needs.** Do not pre-build tables/columns for future phases "just in case."
- Every migration is additive and reversible (`down()` must work).
- Naming: snake_case tables, plural (`users`, `mail_servers`, `email_accounts`, `email_folders`, `emails`).
- Foreign keys use `foreignId()->constrained()->cascadeOnDelete()` unless a phase says otherwise.

### 3.4 Code quality standards
- `declare(strict_types=1);` at the top of **every** PHP file (app code — not required in stock Laravel config/bootstrap files unless already present).
- **Explicit return types** on every method, including `void`.
- **Explicit param types** — no untyped `mixed` unless genuinely polymorphic.
- Constructor property promotion + `readonly` where applicable for DTOs/value objects.
- No suppressed errors (`@`), no empty `catch {}` blocks.
- Laravel Pint for formatting (`composer.json` already includes it) — run before committing.
- Frontend: ESLint + Prettier conventions consistent with Vue 3 `<script setup>` + Tailwind (add configs in Phase 3 if not already present).

### 3.5 Exception handling (critical — this is an MVP talking to real, flaky mail servers)
- Wrap every IMAP/SMTP call in service-layer try/catch. Never let a raw `ErrorException`/`Webklex\PHPIMAP\Exceptions\*` bubble to the HTTP response.
- Define app-specific exceptions, e.g.:
  - `App\Exceptions\Mail\ImapConnectionException` (network/timeout)
  - `App\Exceptions\Mail\ImapAuthenticationException` (bad credentials)
  - `App\Exceptions\Mail\SmtpSendException`
- Map these to clear, user-safe JSON error responses (no raw protocol stack traces leaked to the frontend); log full details server-side (`Log::error` with context, **never log raw passwords**).
- Set explicit connection/read timeouts on the IMAP client (don't rely on PHP defaults) so a hung mail server can't hang a request indefinitely.
- Distinguish, at minimum: connection/network failure vs. authentication failure vs. unexpected/server error — the frontend needs to show different messages for each.

---

## 4. Security

### 4.1 App-user / account ownership model (decided)
- App-level auth uses **Laravel Sanctum personal access tokens** (Bearer tokens) — chosen over cookie-based SPA auth since the frontend is a separately-hosted SPA (§2); simpler CORS/CSRF story for a 2-day MVP.
- `users` table: `name` (`VARCHAR(50)`), **`username`** (`VARCHAR(50)`, unique — login identifier, not email), `email` (unique, kept for Laravel's built-in notification/reset-password plumbing though unused by login), `password` (bcrypt hash). **No soft deletes** — no FR requires deleting an app user in this MVP, and soft-delete would hit the same unique-constraint re-registration problem discussed for `email_accounts` below; add a delete mechanism only when a real requirement appears.
- A logged-in `User` can own **multiple `email_accounts`** (one-to-many, `email_accounts.user_id` FK, `cascadeOnDelete`). This is the "multi-account" the Pinia store switches between.
- All mail endpoints (Phase 1+) are scoped to `auth()->user()` — an `email_account` is only ever accessed/mutated through its owning user; never trust an account ID from the client without an ownership check.
- `email_accounts` does **not** double as the login identity — app login (`users`) and mailbox credentials (`email_accounts`) are fully separate concerns.
- **Deleting a connected email account is a hard delete** (`DELETE /api/email-accounts/{id}`), not soft delete: soft-deleting would collide with the `unique(user_id, email)` constraint the moment a user re-adds the same address, real mail lives on the provider's server regardless (our DB row is just a cached credential + pointer), and keeping an encrypted password around for a "deleted" account is an unnecessary privacy liability. `mail_server_id` uses `restrictOnDelete()` precisely so a provider row can never be deleted out from under live accounts.

### 4.2 `email_accounts` schema (implemented) & re-auth flow
| Column | Type | Notes |
|---|---|---|
| `user_id` | `BIGINT UNSIGNED`, FK → `users`, `cascadeOnDelete` | |
| `mail_server_id` | `BIGINT UNSIGNED`, FK → `mail_servers`, **NOT NULL**, `restrictOnDelete` | Always the default provider in this MVP (§3.2) |
| `email` | `VARCHAR(255)` | |
| `password` | `TEXT` | `Crypt`-encrypted ciphertext, via Eloquent's built-in `'encrypted'` cast — routinely longer than 255 chars, hence `TEXT` not `VARCHAR` |
| `status` | `ENUM('active', 'needs_reauth')`, default `'active'` | Only `active`/`needs_reauth` for now — deliberately **not** adding `error`/`disabled` yet (see below) |
| `last_verified_at` | `TIMESTAMP`, nullable | Timestamp of the last *successful* handshake; kept as history, never nulled out by a failed attempt |
| unique constraint | `unique(user_id, email)` | Per-user duplicate prevention (FR-2.4); no separate index needed on `user_id` alone since it's the composite's leftmost column |

**Re-auth flow (e.g. the user changed their mailbox password in the hosting control panel):**
- A live handshake distinguishes `ImapAuthenticationException` (bad credentials) from `ImapConnectionException` (network/timeout, transient).
- Only an **authentication** failure sets `status = 'needs_reauth'`. A connection failure never touches `status` or `last_verified_at` — it's transient and unrelated to the stored password, so flagging it would incorrectly tell the user to re-enter a password that's actually still correct.
- `PUT /api/email-accounts/{id}` (added to Phase 1 for this reason) re-runs the live handshake with a new password; only on success does it update `password`, reset `status` to `active`, and bump `last_verified_at`. On failure it sets `status = 'needs_reauth'` again and changes nothing else.
- `status` was deliberately kept to two values instead of a broader enum (`error`, `disabled`) — no current FR needs them, and persisting a transient `error` state risks it going stale with no code path to clear it. Extend the enum via a new migration if/when a real need appears (consistent with incremental DB design, §3.3).

### 4.3 Credential handling
- Account passwords are encrypted at rest with `Crypt::encryptString()` / `Crypt::decryptString()` (Laravel's `APP_KEY`-based reversible encryption) — required because the app must present the plaintext password to IMAP/SMTP on the user's behalf. This is **not** password hashing (bcrypt) — do not confuse the two. (`users.password` still uses standard bcrypt hashing for app login, as normal.)
- Never log decrypted passwords, and never include them in exception messages, API responses, or Resources.
- Never return the `password` (encrypted or not) column from any API endpoint — exclude via `$hidden` on the model and/or explicit Resource field lists.
- `APP_KEY` must never be committed; confirm `.env` is gitignored (already default in Laravel).
- Connections to IranServer use IMAP port 993 (SSL) and SMTP port 465 (SSL) — no plaintext/STARTTLS fallback in MVP.
- Rate-limit both the app login endpoint and the account-verification endpoint (Laravel's built-in throttle middleware) to reduce brute-force risk.

---

## 5. Roadmap & Phase Checkpoints

Work phase-by-phase. Do not start a phase's frontend/backend work until the previous phase's checkpoint is met. Each phase ends with a short manual verification before moving on.

### Phase 1 — Mail server config + IMAP handshake + account storage (implemented)
- [x] App-level auth: `users` table (`name`, `username` unique, `email` unique, `password`) + `POST /api/register`, `POST /api/login`, `POST /api/logout` via Sanctum personal access tokens; `throttle:6,1` on register/login
- [x] `mail_servers` migration + model + `MailServerSeeder` (IranServer profile, `is_default = true`)
- [x] `email_accounts` migration + model (`user_id` FK, `mail_server_id` FK NOT NULL, `password` cast `encrypted`, `status` enum, `last_verified_at`)
- [x] `App\Services\Mail\EmailAuthService::testImapConnection()` — live IMAP handshake via `webklex/laravel-imap`'s `ClientManager`, 15s timeout, maps `AuthFailedException` → `ImapAuthenticationException` and `ConnectionFailedException`/other → `ImapConnectionException`
- [x] `EmailAccountController` (thin): `index`, `store` (handshake → persist only on success), `update` (re-handshake with a new password, resets `needs_reauth`), `destroy` (hard delete) — all scoped to `auth()->user()`, `throttle:10,1` on store/update
- [x] Exception classes `ImapConnectionException` / `ImapAuthenticationException` mapped to `422` (auth) / `503` (connection) JSON responses
- [x] Feature tests (19 passing): register/login/logout, unauthenticated → 401, successful handshake → 201 + encrypted password stored, auth failure → 422 + nothing stored, connection failure → 503 + nothing stored, duplicate email → 422 validation, per-user account isolation, re-auth flow (`needs_reauth` set/cleared), ownership checks on update/destroy (403 for other users' accounts), `restrictOnDelete` on `mail_servers`
- **Checkpoint met:** can register/log in, POST an email+password to `/api/email-accounts`, get a live IMAP handshake result, and see the account row persisted (owned by that user) with an encrypted password. Verified against the automated test suite (mocked IMAP client) and a manual smoke test of register/login/list over HTTP; **not yet verified against a real IranServer mailbox** — do that before considering Phase 1 fully done.

### Phase 2 — Fetching & handling emails via IMAP
- [ ] `ImapClientService` (or interface + IranServer/webklex implementation) — list folders, list messages (paginated), fetch single message (headers + body)
- [ ] **Decided:** no `email_folders` persistence table — fetch folders/messages live from IMAP on each request (simplest for the 2-day budget; revisit only if performance in Phase 5 demands caching)
- [ ] Endpoints: list folders, list messages in folder (paginated), get single message — all scoped to the authenticated user's own `email_accounts`
- [ ] Timeout + error handling reused from Phase 1's exception classes
- **Checkpoint:** can list INBOX folders and messages for a stored account through the API.

### Phase 3 — Vue 3 frontend
- [ ] `frontend/` Vite + Vue 3 + Pinia + Tailwind scaffold
- [ ] Pinia store for accounts (multi-account switcher) and current folder/message state
- [ ] Account switcher UI
- [ ] Folder navigation UI
- [ ] Email list + reader UI
- [ ] API client layer (axios/fetch wrapper) with typed error handling matching backend's exception shapes
- **Checkpoint:** can log in (add account), switch accounts, browse folders, read an email — end to end in the browser.

### Phase 4 — Composition & SMTP sending
- [ ] `SmtpMailService` — send via Laravel Mailer/Symfony Mailer, port 465 SSL, using the account's decrypted credentials
- [ ] Compose endpoint + Form Request (to/cc/bcc/subject/body validation)
- [ ] Compose UI (Vue)
- [ ] Reply/forward (if time allows — confirm before starting, may be a stretch goal)
- **Checkpoint:** can compose and send a real email through IranServer SMTP from the UI.

### Phase 5 — Polish, error handling, performance
- [ ] Audit all IMAP/SMTP call sites for consistent exception handling and user-facing messages
- [ ] Loading/empty/error states in UI for every async view
- [ ] Basic performance pass: pagination limits, avoid N+1 IMAP calls, cache folder lists where safe
- [ ] Final security pass: confirm no credentials ever logged or returned in responses
- **Checkpoint:** demo-ready end to end; no unhandled exceptions surface raw errors to the UI.

---

## 6. Local Development Setup

```bash
cd backend
cp .env.example .env
# Edit .env for MySQL (the target production/dev database, per §2):
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1
#   DB_PORT=3306
#   DB_DATABASE=email_client
#   DB_USERNAME=root
#   DB_PASSWORD=
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

> **Note on this sandbox:** the container this was built in has no MySQL server, so `.env` here is left on the Laravel skeleton's default (`DB_CONNECTION=sqlite`, `database/database.sqlite`) purely for local iteration and manual smoke-testing. All migrations (including the `ENUM` columns) run cleanly on both engines. Automated tests always run against an in-memory SQLite DB regardless (`phpunit.xml`), so this doesn't affect test validity — but a real run against **MySQL** should happen before Phase 1 is considered fully verified, per §2.

Packages added in Phase 1: `laravel/sanctum` (API token auth) and `webklex/laravel-imap` (IMAP handshake/fetch). `php artisan install:api` published `routes/api.php` and the `personal_access_tokens` migration.

Frontend (from Phase 3 onward):
```bash
cd frontend
npm install
npm run dev
```

Run backend tests:
```bash
cd backend
php artisan test
```

Run Pint before committing:
```bash
cd backend
./vendor/bin/pint
```

---

## 7. Conventions Quick Reference

- **Commits:** small, phase-scoped, descriptive (`feat(phase1): add mail_servers seeder for IranServer`).
- **Branches:** default assumption — commit straight to `main`, small phase-scoped commits, given the 2-day timeline (not yet explicitly confirmed; flag if you'd rather use per-phase branches like `phase-1-imap-handshake`).
- **PHP files:** `strict_types=1`, typed everything, thin controllers, fat(ter) services.
- **Vue files:** Composition API + `<script setup>` only, no Options API.
- **State:** Pinia stores per domain (`useAccountsStore`, `useFoldersStore`, `useMessagesStore`), not one giant store.
- **Never hardcode IranServer host/port in application code** — always read from the `mail_servers` seed/config, per §3.2.

---

## 8. Decision Log

| # | Question | Decision |
|---|---|---|
| 1 | Frontend location | Separate `frontend/` Vite + Vue 3 SPA, calls Laravel API over JSON (§2) |
| 2 | Account ownership model | Standard Laravel `users` table; a user owns multiple `email_accounts` via `user_id` FK (§4.1) |
| 3 | `mail_servers` seed columns | `name, slug, imap_host, imap_port, imap_encryption, smtp_host, smtp_port, smtp_encryption, is_active, notes` (§3.2) |
| 4 | Folder persistence (Phase 2) | Live-fetch from IMAP on every request, no `email_folders` table for MVP (§5, Phase 2) |
| 5 | Branching strategy | **Assumed default:** direct commits to `main`, not explicitly confirmed — flag if per-phase branches are preferred (§7) |
| 6 | Domain-to-provider auto-detection (FR-2.2) | **Rejected as originally scoped** — IranServer is shared hosting, so an email's domain can't identify its provider without a live MX/DNS lookup (i.e. Autodiscovery, an explicit non-goal). Every account is routed to the single `mail_servers.is_default = true` row instead; no domain matching in this MVP (§1, §3.2) |
| 7 | `users.username` | Added as its own unique, 50-char column, separate from `email` — app login uses username, not email (§4.1) |
| 8 | Soft delete on `users` | **Rejected** — no FR needs it, and it would hit the same unique-constraint-on-re-registration problem as soft-deleting `email_accounts` (§4.1) |
| 9 | `email_accounts` deletion | **Hard delete**, not soft — avoids the unique-constraint collision on re-adding the same address, matches the fact that real mail lives on the provider not in our DB, and avoids retaining a now-unwanted encrypted password (§4.1) |
| 10 | Account re-auth state | Added `email_accounts.status ENUM('active','needs_reauth')` (not a boolean, not a wider enum with `error`/`disabled` — those aren't needed yet and `error` risks going stale); only `ImapAuthenticationException` sets `needs_reauth`, never a transient connection failure (§4.2) |
| 11 | `PUT /api/email-accounts/{id}` | Added to Phase 1 — required to let a user re-enter a new password after `needs_reauth`, without a clumsy delete-then-recreate flow (§4.2) |
| 12 | `imap_encryption` / `smtp_encryption` | `ENUM('ssl','tls')` at the DB level, not free-form `VARCHAR` — a genuinely closed, stable set (§3.2) |
| 13 | App-level auth mechanism | Laravel Sanctum personal access tokens (Bearer), not cookie-based SPA auth — simpler for a separately-hosted SPA in a 2-day MVP (§4.1) |
| 14 | API JSON envelope | `JsonResource::withoutWrapping()` — responses are flat (no `data` envelope) for a simpler frontend contract |

Decisions can be revisited per phase — if something changes, update the relevant section above **and** add a row/note here so the log stays the source of truth.

---

*Last updated: Phase 1 implemented (migrations, models, seeder, `EmailAuthService`, auth + email-account controllers, 19 passing feature tests). Not yet verified against a real IranServer mailbox — do that, then move to Phase 2. Branching strategy (§8, item 5) still unconfirmed. Update the checkboxes and this line as phases complete.*
