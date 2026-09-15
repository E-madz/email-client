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

**Decided — `mail_servers` seed columns:** `name`, `slug` (unique, e.g. `iranserver`), `imap_host`, `imap_port` (993), `imap_encryption` (`ssl`), `smtp_host`, `smtp_port` (465), `smtp_encryption` (`ssl`), `is_active`, `notes`.

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
- Standard Laravel app-level auth: a `users` table (Laravel's default scaffolding — session or Sanctum token auth for the SPA, decide the concrete mechanism at Phase 1 implementation time).
- A logged-in `User` can own **multiple `email_accounts`** (one-to-many, `email_accounts.user_id` FK, `cascadeOnDelete`). This is the "multi-account" the Pinia store switches between.
- All mail endpoints (Phase 1+) are scoped to `auth()->user()` — an `email_account` is only ever accessed/mutated through its owning user; never trust an account ID from the client without an ownership check.
- `email_accounts` does **not** double as the login identity — app login (`users`) and mailbox credentials (`email_accounts`) are fully separate concerns.

### 4.2 Credential handling
- Account passwords are encrypted at rest with `Crypt::encryptString()` / `Crypt::decryptString()` (Laravel's `APP_KEY`-based reversible encryption) — required because the app must present the plaintext password to IMAP/SMTP on the user's behalf. This is **not** password hashing (bcrypt) — do not confuse the two. (`users.password` still uses standard bcrypt hashing for app login, as normal.)
- Never log decrypted passwords, and never include them in exception messages, API responses, or Resources.
- Never return the `password` (encrypted or not) column from any API endpoint — exclude via `$hidden` on the model and/or explicit Resource field lists.
- `APP_KEY` must never be committed; confirm `.env` is gitignored (already default in Laravel).
- Connections to IranServer use IMAP port 993 (SSL) and SMTP port 465 (SSL) — no plaintext/STARTTLS fallback in MVP.
- Rate-limit both the app login endpoint and the account-verification endpoint (Laravel's built-in throttle middleware) to reduce brute-force risk.

---

## 5. Roadmap & Phase Checkpoints

Work phase-by-phase. Do not start a phase's frontend/backend work until the previous phase's checkpoint is met. Each phase ends with a short manual verification before moving on.

### Phase 1 — Mail server config + IMAP handshake + account storage (current)
- [ ] App-level auth scaffolding: `users` table (Laravel default) + login/register/logout, session or Sanctum token auth for the SPA
- [ ] `mail_servers` migration + model + seeder (IranServer profile)
- [ ] `email_accounts` migration + model (`user_id` FK → `users`, encrypted password via `Crypt`, FK to `mail_servers`)
- [ ] `EmailAuthService::testImapConnection()` — live IMAP handshake against IranServer using `webklex/laravel-imap`, returns success/failure without persisting on failure
- [ ] `EmailAccountController@store` (thin) — validates via Form Request, calls service to test handshake, persists only on success, scoped to `auth()->user()`
- [ ] Exception classes for connection vs. auth failures (§3.5), mapped to clear JSON error shapes
- [ ] Feature test: valid IranServer test account → 201 + stored (password not exposed in response); invalid credentials → 4xx with clear error; unreachable host → 4xx/5xx distinguishable from auth failure; unauthenticated request → 401
- **Checkpoint:** can register/log in as an app user, POST real (test) IranServer credentials to the API, get a live IMAP handshake result, and see the account row persisted (owned by that user) with an encrypted password.

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
# Edit .env:
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

Decisions can be revisited per phase — if something changes, update the relevant section above **and** add a row/note here so the log stays the source of truth.

---

*Last updated: Phase 1 kickoff — all initial open questions resolved except branching strategy (assumed default, §8). Update the checkboxes and this line as phases complete.*
