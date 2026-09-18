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
| Frontend | Vue 3 (Composition API `<script setup>`), plain JavaScript (no TypeScript) |
| Routing | `vue-router` — added in Phase 3, not originally listed here (§8 item 28) |
| State management | Pinia (multi-account state), one store per domain: `auth`, `accounts`, `folders`, `messages` |
| CSS | Tailwind CSS v4 (via the `@tailwindcss/vite` plugin — no `tailwind.config.js` needed in v4) |

Repo layout:
```
email-client/
├── backend/          # Laravel 13 API application
└── frontend/         # Vue 3 SPA (Phase 3), separate Vite app calling the Laravel API
```

**Decided:** `frontend/` is a separate Vite + Vue 3 SPA that talks to the Laravel backend purely over a JSON API (no Blade/Inertia glue).

---

## 3. Architecture & Standards

### 3.1 Layered architecture
- **Controllers are thin.** They validate the request (via Form Requests), call a service, and return a Resource/JSON response. No business logic, no direct IMAP/SMTP/Eloquent query building in controllers.
- **Service layer** owns business logic and all protocol interaction:
  - `App\Services\Mail\ImapConnectionFactory` — single source of truth for turning an email/password (or a stored `EmailAccount`) into a connected IMAP client, and for mapping whatever the server throws back onto our own exceptions. Every other IMAP-touching service goes through this so an exception-mapping fix (§3.6) only ever needs to be made once.
  - `App\Services\Mail\EmailAuthService` — thin wrapper: connect then disconnect, for the Phase 1 handshake test.
  - `App\Services\Mail\FolderTypeMapper` — maps a raw IMAP folder to our closed folder-type set + a Persian display label.
  - `App\Services\Mail\FolderSyncService` — upserts an account's folder list from the live server (Phase 2).
  - `App\Services\Mail\MessageSyncService` — incremental, UIDVALIDITY-aware message sync for one folder (Phase 2).
  - `App\Services\Mail\MessageContentService` — lazily fetches+caches a message's `body_html` on first view (Phase 2).
  - `App\Services\Mail\MessageFlagService` — sets `\Seen`/`\Flagged` remote-first, then local, on success only (Phase 2).
  - `App\Services\Mail\SmtpTransportManager` (Phase 4a, not `final`) — builds a raw Symfony Mailer transport per account; the mockable seam, mirrors `ClientManager`.
  - `App\Services\Mail\SmtpTransportFactory` (Phase 4a) — maps SMTP transport failures to `SmtpAuthenticationException`/`SmtpConnectionException`, the SMTP-side `ImapConnectionFactory`.
  - `App\Services\Mail\SmtpMailService` (Phase 4a) — composes and sends via the transport, then records a local Sent copy.
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

### 3.3 Folder & message sync schema (Phase 2, implemented)
Phase 2 replaced the original "live-fetch, no persistence" plan (Decision Log §8 item 4) with a local sync-and-cache engine, for UX/performance — see §8 item 17 for why and what changed.

**`folders`:**

| Column | Type | Notes |
|---|---|---|
| `email_account_id` | `BIGINT UNSIGNED`, FK → `email_accounts`, `cascadeOnDelete` | |
| `name` | `VARCHAR(255)` | Raw IMAP folder path (e.g. `INBOX`, `Sent Items`) — what's actually used to `SELECT`/`EXAMINE` it |
| `type` | `ENUM('inbox','sent','drafts','trash','spam','archive','custom')`, default `'custom'` | Mapped by `FolderTypeMapper` — SPECIAL-USE attribute first, folder-name heuristic fallback (see the real-world caveat in §3.6) |
| `display_name` | `VARCHAR(100)` | Persian label for the UI |
| `uid_validity` | `BIGINT UNSIGNED`, nullable | `NULL` until first synced; a changed value triggers a full resync (§3.6) |
| `last_uid` | `BIGINT UNSIGNED`, default `0` | High-water mark for incremental sync |
| `last_synced_at` | `TIMESTAMP`, nullable | |
| unique | `unique(email_account_id, name)` | |

Folders are stored **flat** — no `parent_id`/hierarchy column, and sync never persists one. A hierarchical name (e.g. `INBOX.spam`) is stored as one flat `name` string, exactly as the server reports it. **Display-only hierarchy (§8 item 39):** the frontend infers nesting purely from that raw `name` at render time (a folder nests under another if its name is literally `<parent name><delimiter>` + the rest, trying `.` and `/`) — this needed no schema change or backend work, only `FolderList.vue` building a tree client-side from the already-flat list `GET /api/email-accounts/{id}/folders` returns.

**`messages`:**

| Column | Type | Notes |
|---|---|---|
| `folder_id` | `BIGINT UNSIGNED`, FK → `folders`, `cascadeOnDelete` | |
| `uid` | `BIGINT UNSIGNED` | Valid only under the folder's current `uid_validity` |
| `rfc_message_id` | `VARCHAR(255)`, nullable | The `Message-ID` header — named to not collide with the row's own `id` |
| `in_reply_to` | `VARCHAR(255)`, nullable | Raw `In-Reply-To` header value; **stored for future threading, no threading/conversation-view logic built in Phase 2** |
| `subject` | `VARCHAR(500)`, nullable | |
| `from_name` / `from_email` | `VARCHAR(255)`, nullable | |
| `to` | `JSON` | List of `{name, email}` — a normalized recipients table would be over-engineering for the MVP |
| `cc` / `bcc` | `JSON`, nullable | |
| `date` | `TIMESTAMP` | The message's `Date` header |
| `body_text` | `MEDIUMTEXT`, nullable | Populated at sync time (preview + search) |
| `body_html` | `LONGTEXT`, nullable | **Lazy** — stays `NULL` until the message is opened (`GET /api/messages/{id}`), to keep folder sync fast (§8 item 22) |
| `is_read` / `is_flagged` | `BOOLEAN`, default `false` | Mirror of `\Seen` / `\Flagged`, always written remote-first (§8 item 20) |
| `has_attachments` | `BOOLEAN`, default `false` | Denormalized to avoid a join in the message list |
| unique | `unique(folder_id, uid)` | |
| index | `index(folder_id, date)` | Paginated list ordered by date (FR-3.2) |
| index | `index(folder_id, is_read)` | Fast local `unread_count` per folder |

**`message_attachments`:** `message_id` (FK, `cascadeOnDelete`), `filename` (`VARCHAR(255)`), `mime_type` (`VARCHAR(150)`), `size_bytes` (`INT UNSIGNED`), `part_number` (`VARCHAR(50)`) — metadata only, no file content stored; a real download fetches the part live from IMAP by UID + part number when actually requested (not built in Phase 2).

### 3.4 Incremental database design
- Create migrations **only for what the current phase needs.** Do not pre-build tables/columns for future phases "just in case."
- Every migration is additive and reversible (`down()` must work).
- Naming: snake_case tables, plural (`users`, `mail_servers`, `email_accounts`, `email_folders`, `emails`).
- Foreign keys use `foreignId()->constrained()->cascadeOnDelete()` unless a phase says otherwise.

### 3.5 Code quality standards
- `declare(strict_types=1);` at the top of **every** PHP file (app code — not required in stock Laravel config/bootstrap files unless already present).
- **Explicit return types** on every method, including `void`.
- **Explicit param types** — no untyped `mixed` unless genuinely polymorphic.
- Constructor property promotion + `readonly` where applicable for DTOs/value objects.
- No suppressed errors (`@`), no empty `catch {}` blocks.
- Laravel Pint for formatting (`composer.json` already includes it) — run before committing.
- Frontend: ESLint + Prettier conventions consistent with Vue 3 `<script setup>` + Tailwind (add configs in Phase 3 if not already present).

### 3.6 Exception handling (critical — this is an MVP talking to real, flaky mail servers)
- Wrap every IMAP/SMTP call in service-layer try/catch. Never let a raw `ErrorException`/`Webklex\PHPIMAP\Exceptions\*` bubble to the HTTP response.
- Define app-specific exceptions, e.g.:
  - `App\Exceptions\Mail\ImapConnectionException` (network/timeout)
  - `App\Exceptions\Mail\ImapAuthenticationException` (bad credentials)
  - `App\Exceptions\Mail\SmtpSendException`
- Map these to clear, user-safe JSON error responses (no raw protocol stack traces leaked to the frontend); log full details server-side (`Log::error` with context, **never log raw passwords**).
- Set explicit connection/read timeouts on the IMAP client (don't rely on PHP defaults) so a hung mail server can't hang a request indefinitely.
- Distinguish, at minimum: connection/network failure vs. authentication failure vs. unexpected/server error — the frontend needs to show different messages for each.
- **Real-world lesson (Phase 1, found testing against actual IranServer):** `webklex/laravel-imap`'s own `AuthFailedException` is only thrown when the library's internal response-validation check fails — it is **not** guaranteed for every server. IranServer instead rejects a bad `LOGIN` with a generic `ImapServerErrorException` carrying `NO [AUTHENTICATIONFAILED] ...` in its message. `ImapConnectionFactory` therefore also catches `ImapServerErrorException` and inspects the RFC 5530 response code inside the message (`AUTHENTICATIONFAILED`, `AUTHORIZATIONFAILED`, `EXPIRED`, `PRIVACYREQUIRED` → auth failure; anything else → connection/server error). **Apply the same skepticism in Phase 4** when adding SMTP-send error handling — don't assume a library's "expected" exception type covers every real server's actual wire behavior; verify against IranServer directly.
- **Real-world lesson (Phase 2, library limitation):** `webklex/laravel-imap`'s `Folder::parseAttributes()` reads only a handful of generic IMAP flags (`\NoInferiors`, `\NoSelect`, `\Marked`, `\Referral`, `\HasChildren`) and **discards everything else** — the SPECIAL-USE attributes (RFC 6154: `\Sent`, `\Drafts`, `\Trash`, `\Junk`, `\Archive`) that `FolderTypeMapper` needs are silently thrown away by the library's own `Folder` wrapper, with no public getter to recover them. `FolderSyncService` works around this by bypassing `Client::getFolders()` entirely and reading `$client->getConnection()->folders('', '*')->validatedData()` directly — the raw per-folder `flags` array — before the wrapper has a chance to drop anything. **Check this pattern before trusting any webklex convenience wrapper for data it doesn't obviously expose** — the library often parses more from the wire than it re-exposes.

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
- `status` was deliberately kept to two values instead of a broader enum (`error`, `disabled`) — no current FR needs them, and persisting a transient `error` state risks it going stale with no code path to clear it. Extend the enum via a new migration if/when a real need appears (consistent with incremental DB design, §3.4).

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
- **Checkpoint met — verified against a real IranServer mailbox (Postman):** wrong password → `422` (`"The email address or password was rejected by the mail server."`); correct real credentials → `201` with `status: "active"`, `last_verified_at` set, no `password` field in the response. Phase 1 is fully done.

### Phase 2 — Fetching & handling emails via IMAP (implemented)
- [x] **Superseded Decision Log §8 item 4:** built a local sync-and-cache engine instead of live-fetch-only, for UX/performance — see §8 item 17. `folders`/`messages`/`message_attachments` migrations + models (schema in §3.3)
- [x] `ImapConnectionFactory` extracted from Phase 1's `EmailAuthService` — single source of truth for connecting + exception mapping, reused by every Phase 2 service (§3.1, §3.6)
- [x] `FolderTypeMapper` — SPECIAL-USE attribute first (read via the raw connection, not the lossy `Folder` wrapper — §3.6), name-heuristic fallback, Persian `display_name`
- [x] `FolderSyncService::sync()` — upserts an account's folders; `GET /api/email-accounts/{emailAccount}/folders` refreshes and returns them with a locally-computed `unread_count` (FR-1.1–FR-1.4)
- [x] `MessageSyncService::sync()` — UIDVALIDITY-aware incremental sync (full resync on mismatch, §8 item 18), batch-capped at 30 on first sync, `leaveUnread()` throughout so syncing never marks a message `\Seen`; `POST /api/folders/{folder}/sync` (FR-2.1–FR-2.7)
- [x] `body_html` fetched lazily — `MessageContentService::ensureBodyHtmlLoaded()` only hits IMAP the first time `GET /api/messages/{message}` is called for that message, then caches it (§8 item 22, FR-3.5)
- [x] `MessageFlagService::setFlags()` — remote IMAP flag set/unset first, local DB only updated on success (§8 item 20); `PATCH /api/messages/{message}/flags` (FR-4.1–FR-4.3)
- [x] `GET /api/folders/{folder}/messages` — paginated (20/page), ordered by `date` DESC, summary shape only (no `body_html`) per FR-3.1–FR-3.4
- [x] All Phase 2 endpoints scoped to the authenticated user via the `email_account → folder → message` ownership chain (403 otherwise), consistent with Phase 1 (FR-5.3)
- [x] Feature tests (40 passing total, 21 new in Phase 2): folder sync + SPECIAL-USE/name-fallback mapping + upsert-not-duplicate, message sync (first batch, incremental, UIDVALIDITY-changed resync, attachment metadata), message list pagination/ordering/summary shape, lazy `body_html` fetch-once-then-cache, flag sync remote-then-local ordering (including "remote fails → local unchanged"), ownership checks on every new endpoint
- **Checkpoint:** verified against the automated test suite (mocked IMAP client/messages, including the real webklex `Message`/`Attachment` typed-collection quirks worked around in `tests/Concerns/InteractsWithImapMocks.php`) — **not yet verified against a real IranServer mailbox** (folders/messages/sync/flags); do that next, same as Phase 1's real-account check, before considering Phase 2 fully done.

### Phase 3 — Vue 3 frontend (implemented)
- [x] `frontend/` scaffolded with Vite + Vue 3 (Composition API, `<script setup>` only) + Pinia + Vue Router + Tailwind v4 (via `@tailwindcss/vite`, no `tailwind.config.js` needed in v4) — plain JS, no TypeScript (not in the original tech stack, would've been scope creep)
- [x] RTL, Persian-language UI (`<html lang="fa" dir="rtl">`) — matches the audience (IranServer users) and the backend's own Persian `display_name` folder labels; Vazirmatn font via Google Fonts with a system-font fallback
- [x] `src/lib/api.js` — zero-dependency `fetch` wrapper (no axios) with `Authorization: Bearer` injection from `localStorage`, JSON parsing, a typed `ApiError` (status + message + field `errors`), and a global 401 hook that forces logout
- [x] Pinia stores **per domain**, exactly as specced: `stores/auth.js`, `stores/accounts.js`, `stores/folders.js`, `stores/messages.js` — no single giant store
- [x] `vue-router` with a `beforeEach` guard (`requiresAuth` / `guestOnly`) — **not in the original tech stack list, added as an unavoidable implementation detail** of having a login page and a protected app shell
- [x] Views: `LoginView`, `RegisterView`, `MailboxView` (3-pane shell: accounts+folders sidebar, message list, reader)
- [x] Components: `AccountSwitcher` (+ `AddAccountModal`, `ReauthModal` for the `needs_reauth` flow), `FolderList` (SPECIAL-USE-aware icons, unread badges), `MessageList` (pagination, sync button, inline flag/star toggle), `MessageReader` (flag/read toggles, attachment chips), plus shared `LoadingSpinner`/`ErrorBanner`/`EmptyState`/`BaseModal`
- [x] **Security:** `body_html` is rendered in a sandboxed `<iframe sandbox="allow-scripts allow-popups ...">` (`SafeHtmlFrame.vue`) with no `allow-same-origin` — any script in an email body runs, if at all, in an isolated opaque origin with no access to our DOM, cookies, or the auth token in `localStorage`. Height auto-fits via `postMessage` since the parent can't reach across that origin boundary. **Never render `body_html` with `v-html` directly** — see §8 item 26
- [x] Loading/empty/error states built into every pane now (not deferred to Phase 5) — each has a retry action wired to its store
- [x] Opening a message auto-marks it read (`PATCH .../flags`) when not already read — the backend's `show` endpoint is deliberately read-only (§3.1), so this is a frontend-side integration decision, not a backend change
- [x] Verified end-to-end in a real browser (Playwright): unauthenticated guard redirect → register → mailbox shell renders → add-account modal → live 503 from a real (unreachable) IMAP attempt correctly surfaces as a Persian error message → logout → re-login restores the mailbox. Two real bugs were caught and fixed this way (§8 items 27–28) that the backend's own test suite couldn't have caught
- [ ] **Not yet done in Phase 3:** actually connecting a **real** IranServer account through the UI (only tested with fake/unreachable credentials in this sandbox — no real IMAP egress here); ESLint/Prettier config (mentioned in §3.5 but not set up yet, tracked as a gap, not a blocker)
- **Checkpoint:** can register/log in, see the mailbox shell, and get correct error feedback end-to-end in a real browser. **Do next:** open the app with a real IranServer account connected and click through folders → sync → open a message → toggle flags, to confirm the full real-world path Postman alone couldn't verify.

### Phase 4a — Compose & SMTP sending (implemented)
Split from the original single Phase 4 into 4a (core, done now) and 4b (deferred) per §8 item 41 — Drafts, Reply/Forward threading, and real IMAP APPEND to Sent all add real scope the original 2-day-MVP plan flagged as "confirm before starting."
- [x] `SmtpTransportManager` (not `final`, mirrors `ClientManager`) builds a Symfony Mailer `EsmtpTransport` per account from its `mail_servers` host/port/encryption + decrypted credentials — `smtp_encryption = 'ssl'` forces implicit TLS (port 465), `'tls'` leaves it to auto-negotiate STARTTLS (port 587), 15s socket timeout
- [x] `SmtpTransportFactory` (`final`, mirrors `ImapConnectionFactory`) — maps a `TransportExceptionInterface` to `SmtpAuthenticationException`/`SmtpConnectionException` by inspecting the SMTP reply code/text in the message (same real-world skepticism as §3.6 — Symfony Mailer has no typed auth-failure exception)
- [x] `SmtpMailService::send()` — builds a `Symfony\Component\Mime\Email`, attaches uploaded files, sends, then records a local-only Sent copy (§8 item 42 — no real IMAP APPEND yet)
- [x] `POST /api/email-accounts/{emailAccount}/messages` (`ComposeController`) + `SendMessageRequest` (to/cc/bcc/subject/body_html/attachments validation) — ownership-scoped, `422`/`503` mapped the same way as the IMAP endpoints (`HandlesSmtpMailExceptions`)
- [x] `message_attachments.storage_path` (nullable, new migration) — a composed message's attachment content is stored locally (Laravel's private `local` disk) since it comes from an upload, not a live IMAP fetch; a received message's attachment still leaves this `null` (§3.3, unchanged)
- [x] `GET /api/attachments/{attachment}` (`AttachmentController`) — downloads a composed message's stored attachment straight from disk; a received message's attachment (`storage_path` still `null`) is now fetched **live from IMAP** by UID + part number (§8 item 46 — the originally-deferred piece got built once the user actually needed it), not the earlier `501` stub
- [x] Attachment chips in `MessageReader.vue` are now clickable and actually download (§8 item 46) — they were plain, non-interactive `<span>`s before; `api.js` gained `downloadBlob()` (fetch with the Bearer header a plain `<a href>` could never attach, since download auth can't ride in a URL)
- [x] `ComposeModal.vue` + `stores/compose.js` — plain `<textarea>` body (no rich-text editor library added — not in the original tech stack, §2), attachment picker with remove, wired to a "نوشتن ایمیل" button in `MailboxView.vue`'s header
- [x] `api.js` gained `postForm()` (raw `FormData`, no `Content-Type` override) alongside the existing JSON helpers, needed because attachments require `multipart/form-data`
- [x] Feature tests (10 new, 57 total): send success + local Sent copy, send with attachment (content + metadata), SMTP auth failure → `422` + `needs_reauth`, SMTP connection failure → `503` (both driven through the *real* `TransportException`-message classification logic, not a stubbed exception), validation, cross-user ownership (403), sending with no synced Sent folder yet (succeeds, skips the local copy), attachment download (success/501/403)
- [x] Verified end-to-end in a real browser (Playwright): compose → fill → submit → a genuine SMTP connection attempt to `mail.iranserver.com` from this sandbox (no real egress here either, same as every other phase) correctly times out at 15s and surfaces as the Persian-chrome/English-message `503` error banner, exactly like Phase 1/3's IMAP failure path
- **Checkpoint met — for 4a's scope:** can compose, attach a file, and get correct success/error handling through a real SMTP attempt. **Not yet verified:** an actual successful send against a real IranServer account (no real SMTP egress in this sandbox) — do that next, same pattern as every other phase's real-account check.

### Phase 4b — Drafts, Reply/Forward threading, real IMAP APPEND to Sent (not started)
- [ ] Real IMAP `APPEND` of the sent message into the account's actual Sent folder (needs verifying against real IranServer first — does an SMTP send already leave a copy there, or must the app APPEND it itself?) and reconciling the local-only copy's synthetic `uid` (§8 item 42) with the real server-assigned one once folder sync picks it up
- [ ] Drafts CRUD (save/edit/delete), not just a compose-time-only state
- [ ] Reply/Forward with `In-Reply-To`/`References` threading headers, using the `in_reply_to` column already stored per message (§3.3, item 25)
- **Checkpoint:** deferred — confirm scope/priority before starting, per the original Phase 4 stretch-goal flag.

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

Frontend (Phase 3):
```bash
cd frontend
cp .env.example .env   # VITE_API_BASE_URL, defaults to http://127.0.0.1:8000/api
npm install
npm run dev            # http://localhost:5173
```
Requires the backend running (`php artisan serve`) at the URL in `VITE_API_BASE_URL`. CORS is already permissive by default (`config('cors')` → `allowed_origins: ['*']`), so no backend change was needed for the frontend's dev-server origin.

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
| 4 | Folder persistence (Phase 2) | **Superseded by item 17** — originally live-fetch-only, revisited once real Phase 2 FRs were scoped |
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
| 15 | Auth-failure detection for real servers | `EmailAuthService` also catches `ImapServerErrorException` and parses its RFC 5530 response code, because real-world testing showed IranServer doesn't always throw the library's own `AuthFailedException` for bad credentials (§3.6) |
| 16 | Handshake failure logging | Added `Log::error` with exception class/message (and any wrapped previous exception) on every handshake failure — an earlier gap meant a real failure left nothing in `laravel.log` to debug from (§3.6) |
| 17 | Phase 2 scope: sync engine vs. live-fetch | Switched from item 4's live-fetch-only plan to a local sync-and-cache engine (`folders`/`messages`/`message_attachments`, §3.3) for UX/performance, accepting the larger engineering cost consciously given the 2-day budget |
| 18 | `folders.uid_validity` | Added (`BIGINT UNSIGNED`, nullable) — without it, a server-side UIDVALIDITY change would silently corrupt incremental sync (missed/duplicate/wrong messages); a mismatch now triggers a full resync from `last_uid = 0` (§3.3, §3.6) |
| 19 | Folder type mapping | SPECIAL-USE IMAP attribute (RFC 6154) first, folder-name heuristic fallback — implemented by reading the raw `LIST` response directly, bypassing `webklex`'s `Folder` wrapper which discards SPECIAL-USE flags (§3.6) |
| 20 | Flag sync ordering | Remote IMAP `setFlag`/`unsetFlag` always called before the local `is_read`/`is_flagged` columns are touched; on a remote failure, local state is left exactly as it was (`MessageFlagService`, §3.3) |
| 21 | Auth-failure HTTP status for mail-server errors | Standardized on `422` (not `401`, which is reserved for the app's own Sanctum authentication) — applies to both Phase 1 and Phase 2 IMAP auth failures |
| 22 | `body_html` fetch timing | Lazy — left `NULL` at sync time and only fetched (then cached) the first time `GET /api/messages/{id}` is called, to keep a folder sync's HTTP request comfortably within typical PHP/web-server timeouts; sync batch size capped at 30 messages for the same reason (§3.3) |
| 23 | Table names | `folders`, `messages`, `message_attachments` (not `email_folders`/`emails` as informally suggested earlier in §3.4 — that was only an example, not a decision) |
| 24 | `messages.message_id` → `rfc_message_id` | Renamed to avoid confusion with the row's own `id` primary key |
| 25 | `messages.in_reply_to` | Added (`VARCHAR(255)`, nullable) — captured for free while already parsing standard headers, to avoid a future migration for Message/Conversation threading; **no threading logic is built in Phase 2**, this is storage only |
| 26 | Rendering untrusted `body_html` | Sandboxed `<iframe sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox">` with no `allow-same-origin`, not `v-html` — an email body is attacker-controlled content; the iframe's opaque origin means any embedded script can't reach our DOM, `localStorage` (auth token), or API. **Any future change touching message rendering must keep this** (§5, Phase 3) |
| 27 | Frontend language/RTL | Plain JavaScript (no TypeScript — not in the original tech stack), `<html lang="fa" dir="rtl">`, Persian UI copy throughout — matches the audience and the backend's own Persian folder labels (§5, Phase 3) |
| 28 | `vue-router` added | Not in the original §2 tech stack table — added as an unavoidable implementation detail once a login page + protected app shell were needed; flagged here rather than silently expanding the stack (§5, Phase 3) |
| 29 | Auth-state-change navigation | A logout (explicit or a forced one from a 401) only changes Pinia state, which doesn't itself trigger the router guard — found via an actual browser test where logging out left the user stuck on a blank mailbox route. Fixed with a single `watch(auth.isAuthenticated)` in `App.vue` that redirects to `/login` whenever it goes false and the current route isn't `guestOnly` (§5, Phase 3) |
| 30 | Auto-mark-as-read on open | **Superseded by item 34** — originally a separate frontend-triggered `PATCH .../flags` call after `GET /api/messages/{id}`; that meant two full IMAP connections to open one unread message, fixed by item 34 |
| 31 | Folder hierarchy delimiter | **Real-world lesson, found on a real IranServer mailbox:** its folders use `.` as the hierarchy delimiter (`INBOX.spam`, `INBOX.Sent`), not `/`. `FolderTypeMapper::mapByName()` hardcoded `/`/`\` stripping, so `INBOX.spam` fell through to `custom`/raw-name display instead of matching the `spam` name-fallback. Fixed by threading the server's own per-folder `delimiter` (already present in the raw `LIST` response webklex exposes via `validatedData()`, §3.6) through `FolderSyncService::sync()` into `FolderTypeMapper::map($attributes, $name, $delimiter)`, which now `explode()`s on the *actual* reported delimiter before matching. Never assume `/` — always read it from the server (§3.6) |
| 32 | First-sync auto-trigger | **Real-world lesson:** every folder showed permanently empty because sync is deliberately on-demand-per-folder (FR-2.1) and nothing ran it automatically on first visit — the user had no reason to know to click "دریافت ایمیل جدید" first. Fixed on the frontend only (no backend change): `MessageList.vue`'s `loadList()` checks `folder.last_synced_at`; if it's `null` on page 1, it calls `messages.sync(folderId)` instead of `messages.fetchList()`. `foldersStore.markSynced()` updates that flag locally right after a successful sync (§5, Phase 3) |
| 33 | IMAP header/body decoder config | **Real-world lesson, upstream library flaw (same pattern as the two §3.6 call-outs):** with `webklex/laravel-imap`'s default decoder option (`'utf-8'`) and no `ext-imap` loaded (confirmed absent in this environment), `HeaderDecoder::decode()`'s `'utf-8'` branch calls `Decoder::mimeHeaderDecode()`, whose non-`ext-imap` fallback returns the *raw, undecoded* text wrapped in an object rather than actually decoding it — but because that raw text is non-empty, `decode()` treats it as a successful decode and never falls through to the correctly-working `iconv_mime_decode()`/`mb_decode_mimeheader()` branches it only tries for other option values. Result: real IranServer subjects like `=?UTF-8?B?SVQtMjAyMDgg2K/Ysdiu2YjYp9iz2Kog2YfYr9iz2Ko?=` were stored and displayed completely undecoded. Fixed with a new `backend/config/imap.php` overriding `options.header`/`options.message` to `'iconv'`, which routes straight to the working `iconv_mime_decode()` call — this works because Laravel loads the app's own `config/imap.php` before the package's `LaravelServiceProvider::setVendorConfig()` merges vendor defaults on top only for keys the app hasn't already set. Regression-tested directly against `Webklex\PHPIMAP\Decoder\HeaderDecoder` in `tests/Unit/ImapHeaderDecodingTest.php` (independent of `ext-imap` availability) rather than through a mocked service, since none of the existing mocks exercise webklex's real decoding pipeline |
| 34 | Combined body-fetch + mark-as-read on first open | **Real-world lesson:** opening a previously-unread message was slow because it triggered *two* full IMAP connections back-to-back — `GET /api/messages/{id}` (body fetch, §8 item 22) immediately followed by the frontend's own `PATCH .../flags` call (item 30) to mark it read, each paying a full TCP+TLS+LOGIN+SELECT round-trip to `mail.iranserver.com`. Fixed by having `MessageContentService::ensureBodyHtmlLoaded()` also set the `\Seen` flag (remote-first, same pattern as `MessageFlagService`, §3.3/§8 item 20) and `is_read = true` on the *same* already-open connection/message when the message isn't already read, before its single `save()`. No frontend change was needed — `messages.js`'s existing `if (!message.is_read) { await this.setFlags(...) }` guard now naturally sees `is_read: true` already in the `GET` response and skips the now-redundant second call. Already-read messages are unaffected (no change in their fast path) |
| 35 | `body_html` iframe direction | **Superseded by item 37** — `dir="auto"` on `<html>`/`<body>` alone judges direction from only the first strong-directional character in the *entire* document, which is wrong for a mixed-language email (item 37 explains the real-world case that exposed this) |
| 36 | Defensive MIME-header re-decoding at read time | **Real-world lesson:** item 33's `config/imap.php` fix only affects messages synced *after* it takes effect — it can't retroactively fix a `subject`/`from_name` already persisted with sync's incremental, UIDVALIDITY-aware behavior (§3.3) never re-fetching an already-synced message, and it's also silently inert if a deployment had `config:cache`d before the file existed. Added `App\Support\MimeHeader::decode()` (plain `iconv_mime_decode`/`mb_decode_mimeheader`, no webklex dependency) wired as `Message` model accessors on `subject` and `from_name` (`protected function subject(): Attribute`, `fromName(): Attribute`) — idempotent (untouched if there's no `=?...?=` pattern), so it self-heals every already-bad row on every read regardless of when it was synced or whether the sync-time config fix actually took effect in a given environment |
| 37 | Per-block `dir="auto"` instead of document-level | **Real-world lesson, confirmed against actual `body_html` pulled from the user's real IranServer mailbox:** item 35's single `dir="auto"` on `<body>` didn't fix real Jira Service Desk notification emails, whose Persian paragraphs sit *after* English boilerplate ("Reply above this line.", "resolved this as Done."). `dir="auto"` resolves direction from only the first strong-directional character in the whole subtree, so the English lead-in text forced the entire body — including the fully-Persian paragraphs after it — to LTR, and that direction is inherited by all descendants that don't set their own. (The user separately confirmed Roundcube, a mature production webmail, shows the exact same misalignment on the same real messages — most webmail clients don't do finer-grained detection either.) Fixed with a small script inside the sandboxed iframe's `srcdoc` (`SafeHtmlFrame.vue`) that runs on load and sets `dir="auto"` on every block-level element individually (`p, div, li, td, th, blockquote, h1–h6`) that doesn't already declare its own `dir` — each block now judges its own direction from its own first strong character. Deliberately scoped to block elements only, not inline ones (`span`, `a`, `b`, …), since giving every inline element its own `dir="auto"` would fragment natural bidi flow within a single sentence. Verified directly against the real message HTML with a headless-browser check: `getComputedStyle(el).direction` came back `rtl` for the Persian paragraphs and `ltr` for the surrounding English ones, in the same document |
| 38 | Deduping same-type folders | **Real-world lesson:** a live IranServer account has both a top-level `Junk` folder (RFC 6154 SPECIAL-USE `\Junk` attribute) and a separate `INBOX.spam` child folder matched only by name — both independently mapped to type `spam`, showing as two identical-looking "هرزنامه" entries in the sidebar. Live folder data plus the user cross-checking the same account's Roundcube folder tree (`Inbox > spam` as a nested folder, plus a separate standalone `Junk`) confirmed SPECIAL-USE isn't a reliable tie-breaker here — the folder actually used day-to-day is the one nested under INBOX, and Roundcube itself doesn't merge the two either, it just shows them under different names/positions. `FolderSyncService::sync()` now dedupes after mapping: when multiple folders share a non-custom type, it keeps exactly one canonical folder per type (preferring an INBOX child, e.g. `INBOX.spam`, over a top-level one, e.g. `Junk`) and demotes every other same-type folder to `custom` with its own raw name — never dropping a folder, just not mislabeling two different real mailboxes as if they were the same one |
| 39 | Display-only folder hierarchy | The user asked why the client doesn't nest `spam` under `Inbox` the way both the mail server and the company's own Roundcube do. Rather than reopening the flat-schema decision (§3.3/§8 item 4/17 — no FR needs a persisted tree, and a sync-side hierarchy would mean new columns plus migration work with zero functional benefit), `FolderList.vue` now infers nesting purely from each folder's already-returned raw `name` at render time: a folder nests under another folder in the same list if its name is literally `<parent name>` + a delimiter (`.` or `/`) + the rest. Verified against the account's real folder set: `INBOX.spam` correctly nests under `INBOX`, while `Junk`/`Sent`/`Drafts`/`Trash` (no matching parent in the list) stay top-level — matching Roundcube exactly. New `FolderTreeItem.vue` renders the tree recursively with per-depth indentation. No backend or schema change |
| 40 | English folder display names | The user asked to see folders' official English names (`Inbox`, `Sent`, `Spam`, …) instead of the Persian labels used since Phase 2/3, matching what the mail server and Roundcube call them — even though the rest of the UI stays Persian/RTL. `FolderTypeMapper::DISPLAY_NAMES` changed from Persian strings to their English originals; the `custom`-type fallback was already the raw folder name (already English/provider-specific, e.g. `Junk`), so it needed no change |
| 41 | Phase 4 split into 4a/4b | The user's initial Phase 4 request (SMTP + compose/send + attachments + Sent-folder handling + Drafts + Reply/Forward threading) bundled together more scope than the original Phase 4 plan (§5), which explicitly flagged Reply/Forward as "confirm before starting, may be a stretch goal." Split into **4a** (SMTP service, compose/send, attachments, local-only Sent copy, tests — done now) and **4b** (Drafts CRUD, Reply/Forward threading, real IMAP APPEND to Sent — deferred), confirmed with the user before starting, to keep the "no scope creep" MVP discipline (§1) |
| 42 | Sent copy: local-only, no IMAP APPEND yet | Symfony Mailer only sends — it doesn't put a copy in the account's Sent folder on the server the way some webmail clients' own SMTP submission does. Confirmed with the user this can't be tested against a real IranServer mailbox from this sandbox (no SMTP/IMAP egress here either), so Phase 4a only records a local `messages` row in the synced Sent folder; a real `APPEND` is deferred to 4b once it can be verified live. The local copy's `uid` is a microsecond-resolution timestamp (`(int) round(microtime(true) * 1_000_000)`) rather than a real server-assigned one — deliberately far outside the small sequential integers a real IMAP server hands out, so it won't collide once 4b starts appending real messages and re-syncing this folder. If no Sent folder has been synced yet, the send still succeeds; only the local bookkeeping is skipped (logged, not surfaced as an error — the message truly was sent) |
| 43 | Attachment storage: content only for what we compose | **Refined by item 46** — `message_attachments` gained a nullable `storage_path` column (Phase 4a) so a composed message's attachment content (a file received via upload) is stored on the private `local` disk and downloadable immediately. Originally, a *received* message's attachment (`storage_path` still `null`) returned `501` since live-IMAP-fetch-by-part-number download was never built in Phase 2 — item 46 builds that piece too |
| 44 | SMTP auth-failure detection | Mirrors item 15's IMAP lesson: Symfony Mailer has no typed "bad credentials" exception, every SMTP-level failure is the same `TransportException` with the server's reply code folded into its message. `SmtpTransportFactory::isAuthenticationFailure()` inspects the message text for SMTP reply codes/phrases (`535`, `534`, `530`, `5.7.8`, `5.7.0`, "authentication failed") the same way `ImapConnectionFactory` inspects RFC 5530 codes — untested against a real IranServer SMTP auth failure yet (no egress here), flagged for the same "verify against IranServer directly" caution §3.6 already calls for in Phase 4 |
| 45 | `SmtpTransportManager` split out, not `final` | `SmtpTransportFactory` (business logic + exception mapping) is `final`, matching every other service in this codebase — but that makes it unmockable with Mockery for tests. Split the raw "build a Transport object" step into a separate, deliberately-not-`final` `SmtpTransportManager`, mirroring how `ImapConnectionFactory` depends on webklex's own non-final `ClientManager` rather than being mocked itself. Tests mock `SmtpTransportManager::make()` to return a mocked `TransportInterface`, then drive real success/failure through that mock's `send()` — exercising `SmtpTransportFactory`'s actual classification logic instead of stubbing around it |
| 46 | Attachment download actually built, both directions | **Real-world lesson:** after Phase 4a shipped, the user found attachment download didn't work at all in either direction — not because of a documented `501` gap, but because `MessageReader.vue`'s attachment chips were plain `<span>`s with no click handler or link, so a *composed* message's already-downloadable attachment was still unreachable from the UI. Fixed two things together: (1) `api.js` gained `downloadBlob()` — a raw `fetch` with the `Authorization: Bearer` header attached, since a download needs auth a plain `<a href="/api/attachments/1">` could never carry, then a temporary object-URL `<a>` triggers the browser's save; (2) `MessageContentService::fetchAttachmentContent()` (new) finally implements the "fetch live from IMAP by UID + part number" behavior CLAUDE.md always described but never built (§3.3) — it re-fetches the message, matches its attachments by `part_number`, and returns the real content/MIME type, using the exact same connect→guard→disconnect pattern as `ensureBodyHtmlLoaded()`. `AttachmentController::download()` now branches on `storage_path`: serve from disk if set (composed messages), otherwise fetch live (received messages) with the same `422`/`503` IMAP-failure mapping every other IMAP-backed endpoint uses. Verified against the real byte content in both an automated test (mocked IMAP attachment) and a real browser (Playwright, downloaded file's content matched byte-for-byte) |

Decisions can be revisited per phase — if something changes, update the relevant section above **and** add a row/note here so the log stays the source of truth.

---

*Last updated: **Phase 1 complete, verified against a real IranServer mailbox.** **Phase 2 implemented**, still not independently verified end-to-end against a real mailbox via Postman, but four real-world bugs found through actual use against a real IranServer account have since been diagnosed and fixed (§8 items 31–35): folder-name display for `.`-delimited folders, folders staying permanently empty until first sync, undecoded MIME encoded-word subjects (upstream `webklex/laravel-imap` decoder flaw when `ext-imap` isn't loaded), slow first-open of unread messages (two IMAP connections merged into one), and left-aligned Persian text in the message reader (added `dir="auto"` to the sandboxed iframe). 46 backend tests passing (`php artisan test`), Pint clean, frontend `npm run build` clean. Item 36 adds a defensive, webklex-independent re-decode of `subject`/`from_name` at read time, so already-synced bad rows self-heal without a resync and without depending on the mail-server-config fix having actually taken effect in a given deployment. Item 37 replaces the single document-level `dir="auto"` with a per-block one after directly inspecting real `body_html` from the user's mailbox showed *why* the first attempt didn't work for mixed English/Persian emails — verified with a headless-browser check against the real message content, not just reasoned about. Item 38 resolves the duplicate-`spam`-folder finding from the same investigation: `INBOX.spam` and top-level `Junk` are both real, distinct folders on this IranServer account, and — cross-checked against the same account's Roundcube — the one actually used is the INBOX child, so `FolderSyncService` now keeps exactly one canonical folder per type and demotes the rest to `custom`. Items 39–40 are frontend-only follow-ups the user asked for after seeing that result: `FolderList.vue` now infers and renders `spam`'s nesting under `Inbox` purely from the already-flat `name` field (no schema change), and folder display names switched from Persian to their official English originals (`Inbox`, `Sent`, `Spam`, …), matching the mail server/Roundcube. 47 backend tests passing, frontend `npm run build` clean, tree-building logic verified with a standalone script against the account's exact real folder set. **Phase 3 implemented** — Vue 3 + Pinia + Tailwind v4 mailbox UI, verified end-to-end in a real browser via Playwright (register → mailbox shell → add-account error handling → logout/re-login), catching and fixing two real bugs (§8 items 27/29) neither backend tests nor Postman could have caught. **Phase 4a implemented** — SMTP compose/send (`SmtpTransportManager`/`SmtpTransportFactory`/`SmtpMailService`), attachments (upload, local storage, download), a local-only Sent-folder copy, and the `ComposeModal.vue` UI, per the 4a/4b split confirmed with the user (§8 item 41) to keep Reply/Forward/Drafts/real-IMAP-APPEND out of this round. 57 backend tests passing, Pint clean, frontend `npm run build` clean, and verified end-to-end in a real browser via Playwright: compose → submit → a genuine SMTP connection attempt to `mail.iranserver.com` times out at 15s and correctly surfaces as a `503` error banner (no SMTP egress in this sandbox, same limitation as every other phase's IMAP work). The user then found attachment download didn't actually work in either direction post-deploy — fixed by item 46: attachment chips are now clickable (they had no download wiring at all before), and a received message's attachment is now genuinely fetched live from IMAP instead of the `501` stub. 58 backend tests passing, verified in a real browser (Playwright) that a downloaded file's content matches byte-for-byte. **Next:** the user should (a) re-test the four Phase 2 fixes from items 31–35 and the Phase 3 folder-hierarchy/English-names changes from items 38–40 against their real IranServer account, (b) actually send and verify a real message through Phase 4a's compose UI, and (c) confirm attachment download works both ways against a real received message with a real attachment — this sandbox could only verify the live-IMAP-fetch path against a mocked IMAP attachment, not a real IranServer one. Phase 4b (Drafts, Reply/Forward, real IMAP APPEND) is deliberately not started. Branching strategy (§8, item 5) still unconfirmed. Update the checkboxes and this line as phases complete.*
