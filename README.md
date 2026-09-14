# Gallu

A multi-tenant gallery application: teams own documents, documents hold images, and every
listing a user sees is scoped to the team they belong to. Built as a Laravel API with a
separate Vue SPA, fully bilingual (English / Arabic, RTL), with soft deletes and a real
retention policy behind every destructive action.

**Stack** — Laravel 12 · PHP 8.5 · MySQL · Pest &nbsp;|&nbsp; Vue 3 · Vuetify 4 · TypeScript · Vite · Pinia · Vitest

```
backend/    Laravel 12 REST API  — auth, authorization, media, search, retention
frontend/   Vue 3 SPA            — Vuetify UI, file-based routing, i18n (en/ar)
```

> Portfolio project. The two folders are deployed independently; open
> `gallu.code-workspace` to work on both at once.

## What it does

- **Users & teams.** A user belongs to exactly one team, with a role inside it (`admin` or
  `member`). Super-admins sit above teams entirely and see everything.
- **Documents & images.** A team's documents each hold a gallery of images, uploaded with
  automatic thumbnail conversions and tagged with categories.
- **Nothing is ever deleted immediately.** Every model soft-deletes into a bin it can be
  restored from, and is purged only once its retention window expires — users, teams and
  categories after 30 days, documents after 7, images after 24 hours.
- **Team-scoped visibility.** A member sees their teammates' images but can only edit their
  own; an admin sees only their own team; a super-admin sees everything.
- **English and Arabic throughout**, including a right-to-left layout and locale-aware
  validation messages served by the API.

## Engineering notes

The parts of this codebase that took the most thought:

**Session-cookie SPA authentication, not tokens.** Sanctum's stateful guard plus Fortify,
with the SPA on `localhost:3000` and the API on `localhost:8000` so the session cookie stays
same-site. The SPA fetches the CSRF cookie before every auth call, and a 401 anywhere clears
the Pinia session and redirects. The auth bootstrap is awaited *before* the router is
registered, so the navigation guard can decide synchronously.

**Retention as a scheduled sweep, not a queued job.** Each model carries its own window
beside a `Prunable` scope, and `model:prune` runs every five minutes. A job dispatched at
delete time would still fire after a restore, and could not be re-aimed when a window
changed. The subtle half is parent-awareness: a document stamps its images with *its own*
`deleted_at`, so an image binned with its document must not prune on the image clock — it
would hand back an empty document on restore. Children only prune while their parent is
live.

**Force deletes walk the models, not the foreign keys.** The FKs cascade, which fires no
Eloquent events — so Media Library never hears about it and every file and conversion is
orphaned on disk with nothing left to collect it. The cleanup therefore lives in `deleting`
hooks guarded by `isForceDeleting()`, which hold for a prune, a parent's cascade and a
tinker session alike.

**Search through Laravel Scout on the database engine.** Full-text (`MATCH … AGAINST`) on
descriptions, `LIKE` on titles, and related columns — a creator's name, a team's name —
reached through an aliased join in `newScoutQuery()` rather than Scout's engine callback.
That distinction is not cosmetic: the callback appends at the *top level*, beside the
engine's `OR` group, and `AND` binds tighter than `OR`, so a soft-delete constraint added
afterwards attached to only the last branch and binned users reappeared in live listings the
day users became soft-deletable. The tests pin both halves.

**Super-admin is a column, not a role.** Every role assignment here is scoped to exactly one
team, and a global super-admin has no team it could honestly belong to — so the flag lives on
`users`, every policy method returns `false`, and a single `Gate::before` short-circuits for
super-admins. Rules that must bind super-admins too (you may not delete or demote yourself)
are explicit controller guards for exactly that reason.

**Bidi-correct truncation, globally.** Truncated Latin text inside an Arabic UI gets clipped
at its *start* unless direction is resolved per string. Handled once in the stylesheet
(`unicode-bidi: plaintext` plus a physical alignment pin) across every truncating surface,
rather than patched per component.

Tests: 400 Pest feature tests (on MySQL — the suite moved off SQLite when a FULLTEXT index
arrived) and 503 Vitest component tests.

## Running it locally

**Requirements:** PHP 8.2+ (developed on 8.5), Composer, Node 20+, MySQL.

```bash
# API — http://localhost:8000
cd backend
composer setup                            # install, .env, key, migrate
php artisan storage:link                  # media URLs 404 without this
php artisan db:seed                       # demo teams, documents, images
php artisan app:promote-super-admin you@example.com
composer dev                              # serve + queue + scheduler
```

```bash
# SPA — http://localhost:3000
cd frontend
npm install
npm run dev
```

The SPA must talk to `http://localhost:8000` (`frontend/.env`), **not** a custom local domain:
the session cookie has to stay same-site. Pointing it elsewhere means adding that host to
`SANCTUM_STATEFUL_DOMAINS`, setting `SESSION_SAME_SITE=none` with a secure cookie, and serving
it over HTTPS.

The seeder creates two super-admins, `johndoe@example.com` and `janedoe@example.com`, both
with the password `12345678`.

## Testing

```bash
cd backend  && composer test        # Pest — needs a `gallu_test` MySQL database
cd frontend && npm run test         # Vitest
cd frontend && npm run type-check   # vue-tsc
```

## Status

Feature-complete for users, teams, categories, documents and images, including every bin and
restore path. Still open: the landing page is Vuetify's starter content, the settings page is a stub,
and a few placeholder API routes remain.

Architecture notes, and the reasoning behind the non-obvious decisions, are in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
