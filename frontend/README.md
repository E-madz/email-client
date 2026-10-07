# Email Client — Frontend

Vue 3 (Composition API, `<script setup>`) + Pinia + Vue Router + Tailwind CSS v4. Talks to the Laravel API in `../backend` over Bearer-token auth — see the repo root `CLAUDE.md` for the full architecture and decision log.

## Setup

```bash
cp .env.example .env   # VITE_API_BASE_URL — defaults to http://127.0.0.1:8000/api
npm install
npm run dev            # http://localhost:5173
```

Requires the backend running at the URL configured in `.env`.

## Structure

- `src/lib/api.js` — thin `fetch` wrapper (auth header, JSON, error normalization)
- `src/stores/` — one Pinia store per domain: `auth`, `accounts`, `folders`, `messages`
- `src/router/` — route guard for authenticated/guest-only pages
- `src/views/` — `LoginView`, `RegisterView`, `MailboxView`
- `src/components/` — UI building blocks (account switcher, folder list, message list/reader, shared loading/error/empty states)

## Build

```bash
npm run build
```
