# Architecture

Notes on how this application is put together, and why the non-obvious decisions were made
that way. The README covers what it does; this covers how.

## Two apps, one product

`backend/` and `frontend/` are independent applications that share nothing but an HTTP
contract. The API never renders a view — Laravel's own asset pipeline was removed — and the
SPA never talks to the database. They live in one repository because they change together.

Every API response goes through a `*Resource` class, so `/api/user` returns a shape the SPA
reads as `data.data`. Validation rules are deliberately duplicated: `backend/app/Rules/*.php`
mirrors `frontend/src/composables/use*ValidationRules.ts`, so a form can reject input before a
round trip while the API stays the only authority. They are changed together.

## Authentication

Session-cookie SPA auth: Sanctum's stateful guard plus Fortify. Not tokens.

- Fortify's routes are published under the `api` prefix but keep `web` middleware, so the SPA
  calls `/api/login`, `/api/register`, `/api/logout`.
- `statefulApi()` is enabled, with the SPA's origin as the only allowed CORS origin and
  credentials on.
- Axios sends credentials and the XSRF header; every auth call fetches `/sanctum/csrf-cookie`
  first. A 401 from anywhere clears the Pinia session and redirects to the login page.
- Both ends must stay same-site, which is why the SPA talks to `localhost:8000` rather than a
  prettier local domain.

Bootstrapping order matters: the auth store's `fetchUser()` is awaited *before* the router is
registered, so the navigation guard can decide between public and private routes
synchronously rather than flashing a redirect.

Login is rate-limited to five attempts a minute per email and IP. Throttle responses are
rendered as JSON with a translated message.

## Authorization

Three role names, but only two are roles. `admin` and `member` are spatie roles, seeded once
with a null team so a single definition serves every team. **Super-admin is a boolean column
on `users`**, not a role, for two reasons:

- Every role assignment in this app is scoped to exactly one team — the pivot's `team_id` is
  `NOT NULL` and part of its primary key. A global super-admin has no team it could honestly
  belong to.
- A reserved "system team" id would be a real row: it would show up in team pickers and the
  admin table, and be renameable or deletable by the very screen it is meant to guard.

The flag is not mass-assignable. It is set by direct assignment in the users endpoints, or by
`php artisan app:promote-super-admin` — which exists because there is no first super-admin to
grant it through the UI.

A single `Gate::before` grants a super-admin every ability, returning `true` or `null` and
never `false` — returning `false` there would deny every check in the application rather than
just the one. That inverts how the policies read: their methods return `false` because the
gate has already short-circuited for the only role allowed through. Rules that must bind
super-admins too (you may not delete or demote yourself) are explicit controller guards,
since no policy can express them.

Four policies follow that shape — `UserPolicy`, `TeamPolicy`, `CategoryPolicy` and the pair
`DocumentPolicy` / `ImagePolicy`, which do carry real rules because ownership inside a team is
a genuine distinction. The one deliberate hole is `CategoryController::picker()`: tagging an
image needs the category list and every user uploads images, so that single endpoint is
ungated while the listing beside it — same model, but carrying trashed rows and creator names
for the admin screen — is not. Teams can gate their picker because only a super-admin ever
opens a team select; categories cannot, which is why they need a policy of their own rather
than reusing the teams shape.

## Teams

Membership *is* the role assignment. There is no `team_id` column on `users` and no pivot
table of its own, because either would be a second source of truth.

- `User::assignToTeam()` is the only writer of membership. It clears existing assignments
  first, which is what enforces one team per user — the package would happily hold one per
  team.
- Role lookups are memoised per process and per team context, so anything switching the
  ambient team has to drop the cached relation around the switch.
- The ambient team is set per request by middleware registered *inside* the authenticated
  route group, not globally — a global prepend runs before the session starts, leaving the
  user null on every request.
- A team-less user gets `null`, not a sentinel: null matches no assignment on reads, and makes
  an unscoped write fail loudly against the `NOT NULL` column instead of silently writing an
  orphan.

Because the user resource reports role and team from that assignment, any endpoint serving a
*collection* of users has to eager-load it through a dedicated scope, or it is an N+1.

## Soft deletes and retention

Everything soft-deletes, and each model carries its own retention window as a constant beside
a `Prunable` scope: users, teams and categories 30 days, documents 7 days, images 24 hours. A
scheduled `model:prune` runs every five minutes.

**A sweep rather than a queued job**, because a job dispatched at delete time would still fire
after a restore, and could not be re-aimed when a window changed.

**Children prune on their own clock only while their parent is live.** Binning a document
stamps its images with the *document's* `deleted_at`, so the timestamp alone cannot
distinguish "binned with its document" from "binned by hand" — and pruning on it would empty a
document's bin on day one while the document itself lives to day seven, handing back an empty
document and breaking the restore-whole promise. Each child scope therefore requires a live
parent.

**Force-delete cleanup lives in `deleting` hooks guarded by `isForceDeleting()`**, not in the
prune hook. The prune hook only fires when the pruner reaches *that* model, so a team's cascade
force-deleting its documents went straight past it and the images were removed by SQL after
all. On `deleting`, the invariant holds for a prune, a parent's cascade, and a manual delete
alike.

**Those hooks exist for the files, not the rows.** The foreign keys already cascade, but SQL
cascades fire no Eloquent events — so Media Library never hears about it, and every file,
conversion and media row is orphaned on disk with nothing left to collect it. Parents
therefore walk their children through the models.

**Deleting a user deliberately does not cascade.** A team is a container; an author is not.
Their documents and images belong to the team, so binning a member drops only the authorship —
the content stays, and the UI renders a `[deleted]` creator. Their role assignment survives
too, which is exactly what lets a restore return them to the same team with the same role, and
their email stays reserved so a restore is always lossless.

## Listings and paging

Two strategies, deliberately not unified:

- **Server-paginated** — the users table, the admin documents table, and a document's image
  feed. Page, sort and search travel as query parameters; the response's `meta.total` feeds the
  data table's footer, or `meta.total` / `meta.last_page` drive the feed's infinite scroll.
  Sort keys are whitelisted against a `SORTABLE` constant on the controller, so an unknown
  column is a 422 rather than an `orderBy` the caller chose.
- **Client-filtered** — categories and teams. One `index` call returns every row, live and
  trashed together, and the page filters in the browser.

The split is about expected row count, not house style: a few dozen teams never need paging,
while users and documents grow without bound. Copy whichever fits what a table will hold.

The query string is the source of truth for the server-paginated screens — the chip, the term
and the sort all live there — so a reload or a shared link restores the same view, and every
refetch funnels through one place rather than each control fetching for itself.

## Search

All three searchable models go through Laravel Scout on the `database` engine — no index, no
queue, no re-indexing; the engine queries the tables directly.

- Only real columns belong in the searchable array; the engine qualifies each key onto the
  model's own table and ignores the values entirely.
- Anything that is not a column on that table — a creator's name, a team's name — is reached
  through an **aliased join in `newScoutQuery()`**, never through `search()`'s engine callback.
  The callback appends at the *top level*, beside the engine's `OR` group, and `AND` binds
  tighter than `OR`. That is not theoretical: a team-name callback silently broke the day users
  became soft-deletable, because the soft-delete constraint attached to only the last `OR`
  branch — binned users reappeared in live listings as soon as their name was searched, and the
  trash listing returned every live user whose email matched. Two regression tests pin it.
- Declaring a full-text column silently drops the engine's own id tie-break, so paging over a
  tied sort starts repeating rows. Both controllers keep an explicit qualified `orderBy` on the
  key.
- Full-text semantics are not `LIKE` semantics: whole words only, a minimum token length, stop
  words. Titles deliberately keep a `LIKE` so partial-title search still works.
- Scout's soft-delete support is on, and the widening (`withTrashed` / `onlyTrashed`) must be
  called on the Scout builder *before* `query()`. Inside the callback, the engine's own
  constraint runs afterwards and overrides it — asking for `deleted_at` to be both null and not
  null, so the bin silently returns nothing.

## Media

Images own a single file in a Media Library collection, with one 400×400 thumbnail conversion.
Updating an image replaces the file only when one is uploaded; otherwise it renames the
existing media so its name never drifts from the title. Avatars share one helper between the
profile action and the admin endpoints so all three agree on what "replace" and "remove" mean —
the remove flag arrives as a string over multipart, because a file upload has to ride along on
a POST spoofing PATCH.

Media URLs derive from `APP_URL`, so they break if it is wrong or if `storage:link` has not been
run.

## Localization

Both ends are bilingual, and wired together: axios sends the current locale as
`Accept-Language`, middleware applies it if it is a supported locale, and API messages come from
the matching language files. Frontend messages are merged with Vuetify's own locale packs, and
the English file types the translation keys.

Truncation is bidi-correct globally rather than per component: one stylesheet rule resolves
direction per string on every truncating surface, because otherwise a Latin value inside an
Arabic UI is clipped at its start.

## The SPA

Routing is file-based over `src/pages/`, so the file tree *is* the route table and there is no
route array to keep in sync. Two consequences worth knowing: the documents listing lives at
`src/pages/index.vue` because it is the home page, and `/documents` survives only as a redirect
declared alongside the generated routes; and `(auth)/` is a pathless group, so login and
register sit at the root rather than under `/auth`.

The navigation drawer has two modes rather than two components. One composable returns either
the main links or the admin links depending on whether the path starts with `/admin`, and the
drawer renders whichever it is handed. Items are added by editing those arrays, not the markup.
Each entry's `value` must equal its real path, because the list matches `value` against the
current route to decide what is active — a mismatch silently kills the highlight rather than
erroring.

Components and Vuetify's own components are auto-imported, and the generated declaration files
(`components.d.ts`, `typed-router.d.ts`) are build output that happens to be committed: never
hand-edit them. Vuetify and Tailwind are both active, with Tailwind used for spacing and
typography tweaks alongside Vuetify props and theme colour tokens.

Validation rules are duplicated on purpose, split by domain: each `use*ValidationRules.ts`
composable mirrors a `*ValidationRules.php` class, so a form can reject input before a round
trip while the API remains the only authority. They are changed together.

## Testing

Pest feature tests run on MySQL — the same engine as production. The suite moved off SQLite
when a FULLTEXT index arrived, which SQLite cannot create at all.

One trap worth knowing: `RefreshDatabase` wraps each test in a transaction it never commits, and
InnoDB processes full-text index updates at commit time — so `MATCH … AGAINST` finds nothing a
test just inserted while a `LIKE` on the same row finds it, and negative assertions pass for the
wrong reason. Full-text tests therefore use `DatabaseMigrations` and are kept few, since each
costs a fresh migration.

The frontend suite is Vitest on jsdom, configured inside the Vite config rather than a config
of its own, so tests inherit the app's plugins and the `@` alias for free. Only
`src/**/__tests__/**/*.spec.ts` is collected — a spec anywhere else is silently ignored — and
specs live beside the code they cover.

Two setup details are load-bearing. `ResizeObserver` and `matchMedia` are shimmed, because jsdom
provides neither and Vuetify's layout and display code needs both; anything mounting a Vuetify
component depends on that. And Vuetify is inlined in the test server deps, because its
components import CSS directly and Node cannot handle that natively — any new dependency
shipping raw CSS imports needs the same treatment.

The router guard is tested as an exported function rather than by driving a real navigation, so
tests do not lazy-load every page component.
