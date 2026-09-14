# CLAUDE.md

Working notes for anyone — person or coding agent — changing this codebase: the conventions to
follow, the commands to run, and the traps that have already cost someone an afternoon.

**Reading rather than editing?** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) covers the same
design decisions as prose, without the do-this-not-that framing. Start there.

## Repository layout

Two independent apps in one folder, opened together via `gallu.code-workspace`:

- `backend/` — Laravel 12 API (PHP 8.5, MySQL). API only: Laravel's own asset pipeline (`resources/js`, `resources/css`, the welcome view, `vite.config.js`, `package.json`) and the Boost-generated `CLAUDE.md`/`AGENTS.md` were removed when the two apps became one repository.
- `frontend/` — Vue 3 + Vite + Vuetify SPA (TypeScript). Has its own `AGENTS.md` (managed by `@intellectronica/ruler`, source of truth is `frontend/.ruler/AGENTS.md`).

One git repository at the root, holding both apps. Their two histories were subtree-merged in under `backend/` and `frontend/`, so file history follows through; `Mohamed-Hafoly/backend` and `Mohamed-Hafoly/frontend` are the archived originals. A change touching both sides is now one commit. `README.md` and `docs/ARCHITECTURE.md` are the public-facing docs, and this file is tracked too — `AGENTS.md` at the root is a pointer to it. `req.txt`, `misc/`, `.claude/` and `.vscode/` stay gitignored.

`req.txt` at the root is the product spec (gallery app: users/teams/categories/images/documents, soft deletes everywhere, super-admin vs. team-scoped admin visibility). Parts of it are still unimplemented. What exists today:

- Auth (Fortify) — see "Authentication architecture" below.
- Roles, teams, and the super-admin gate — see "Admin section" below.
- Images: a soft-deleted `Image` model with `index`/`store`/`update`/`destroy`, its listing **team-scoped** (`Image::scopeVisibleTo()`, not owner-scoped — `owner=mine` is a filter layered on top), backed by `spatie/laravel-medialibrary` (`^11.23`) — see "Images" below.
- Categories: a `Category` model (soft-deleted, `belongsToMany` images) with full CRUD plus `restore`, and a `picker` endpoint for the image form. Gated by `CategoryPolicy` — super-admin only, except `picker`, which every image upload needs.
- Teams: a soft-deleted `Team` model with full CRUD plus `restore` and a `picker`, gated by `TeamPolicy`, behind `src/pages/admin/teams.vue`.
- Users management: `UserController` `index`/`store`/`update`/`destroy`/`restore` at `/api/users`, super-admin only, behind `src/pages/admin/users.vue` — a two-table screen like documents, live plus pending-deletion. Name, email, avatar, the super-admin flag and team membership are all editable; delete is a **soft** delete and refuses the signed-in user's own account, as does demoting yourself.
- **Deleting a user does not cascade**, deliberately, unlike `Team`→`Document`→`Image`. Those are containers; a user is not one — their documents and images belong to the *team* (both `scopeVisibleTo()` methods scope by team), so binning a member leaves the content and drops only the authorship. Most of that falls out of the trait: every `belongsTo(User)` carries the global scope, so `$document->user` reads null and the resources serve `creator: null`, which the SPA renders as `common.deletedUser` ("[deleted]"). `Team::members()` is a `morphedByMany` onto `User`, so a binned user leaves member lists and `members_count` without any filter of its own, while the `model_has_roles` row survives — which is what makes a restore return them to the same team with the same role. `EloquentUserProvider::retrieveById()` applies global scopes, so they cannot sign in and an open session stops resolving. Their email stays reserved (`Rule::unique` and the DB index both ignore the scope), so a restore is always lossless.
- `documents.user_id` and `images.user_id` are nullable `nullOnDelete` for the same reason — they were `NOT NULL` + `CASCADE`, so a *force* delete would have destroyed a team's content rather than anonymising it. The contrast with `documents.team_id`, which **is** `cascadeOnDelete`, is the whole design: the team is the container, the author is not.
- **Retention pruning** implements `req.txt`'s clock: users 30 days, teams 30 days, documents 7 days, images 24 hours, categories 30 days (`req.txt` is silent on that last one). Each window is a `RETENTION_DAYS`/`RETENTION_HOURS` const on its own model beside a `Prunable` `prunable()` scope, and `routes/console.php` schedules `model:prune` `everyFiveMinutes()`. A **scheduled sweep, not a queued job** — a job dispatched at delete time would still fire after a restore and could not be re-aimed when a window changed. Several things about it are easy to get wrong:
  - **A child prunes on its own clock only while its parent is live.** `Document::booted()` stamps images with the *document's* `deleted_at`, so the timestamp cannot tell "binned with its document" from "binned by hand" — and pruning on it alone would empty a document's bin on day one while the document itself lives to day seven, handing back an empty document and breaking the restore-whole promise `Document::restoring()` makes. Hence `whereHas('document')` in `Image::prunable()` and `whereHas('team')` in `Document::prunable()` — both meaning a *live* parent, since a `belongsTo` onto a soft-deleting model carries its scope.
  - **The cleanup lives in `deleting` hooks guarded by `isForceDeleting()`, not in `Prunable::pruning()`.** That hook fires only when `model:prune` reaches *that* model, so `Team`'s cascade force-deleting its documents slipped straight past `Document::pruning()` and the images went via SQL after all — caught by `PruneTrashedTest`, not by inspection. On `deleting` the invariant holds for the prune, for a parent's cascade, and for tinker alike. Each model pairs the existing soft-delete `deleted`/`restoring` cascade with a force-delete branch in the same `booted()`.
  - **Those hooks exist for the media, not for the rows.** `images.document_id` and `documents.team_id` are both `cascadeOnDelete`, so force-deleting a team removes documents and images in SQL two levels down — firing no model events, so spatie's deleting hook never runs and every file, `thumb` and `media` row is orphaned on disk with nothing left to collect it (`media` carries no FK). `Document` and `Team` therefore walk the children through the models. Use `->get()->each(...)`, never `Builder::each()`: it chunks by offset, and each delete shifts the rows still to come.
  - **`model_has_roles` is FK-less on `team_id` and `model_id`** (only `role_id` is constrained), so membership rows survive both a destroyed team and a destroyed user. `Team` deletes them via `Config::modelHasRolesTable()`; `User` calls `assignToTeam(null)`, the only writer of membership — and only on a force delete, since that surviving row is exactly what lets a *soft* delete's restore return them to the same team and role.
  - **`category_image.category_id` has no `onDelete`**, i.e. RESTRICT — force-deleting a category with images attached raises an integrity constraint violation, which is what `Category`'s `detach()` prevents.
  - The order in the `--model` list is load-bearing (children first), and `composer dev` runs `schedule:work` because there is no cron under Herd on Windows. `tests/Feature/PruneTrashedTest.php` pins all of it.

Documents and the team-scoped visibility rules are **built**: `Document::scopeVisibleTo()` and `Image::scopeVisibleTo()` narrow both listings to the caller's team, applied inside `DocumentController::index()` and `ImageController::index()` as an AND outside the Scout search group. (An earlier revision of this file said they were unbuilt; that has been wrong for some time.)

The users table, the admin documents table and the document page's image feed are the server-paginated listings: page, sort and search arrive as query parameters (validated by `IndexUserRequest`, sort keys whitelisted against `UserController::SORTABLE` so an unknown column is a 422 rather than an injectable `orderBy`), and the response's `meta.total` feeds `VDataTableServer`'s footer (the image feed reads the same `meta.total`/`meta.last_page` to drive infinite scroll instead). Categories and teams deliberately do the opposite — one `index` call returns every row, live and trashed, and the page filters client-side. Copy whichever fits the expected row count; don't assume either is the house style.

The users, documents and images searches all run through **Laravel Scout** (`^11.6`) on the `database` engine (`SCOUT_DRIVER=database`, pinned for the suite in `phpunit.xml`) — `User`, `Document` and `Image` are the `Searchable` models; categories and teams are still hand-rolled, and both filter client-side anyway. Three things about that engine are worth knowing before adding the trait to a fourth model:

- There is **no index**. The engine queries the tables directly, so `DatabaseEngine::update()`/`delete()` are no-ops: no `scout:import`, no queue, no re-indexing when a related row changes, and no extra queries per save.
- `toSearchableArray()`'s **keys** are all it reads, and it qualifies each onto the model's own table — so only real columns belong there, and the values are never used. `User::toSearchableArray()` lists `id`, `name` and `email`. `id` is special-cased by the engine rather than LIKEd: an all-digits term matches the key by *equality* (so `145` finds user 145, not 1450) and the LIKE for that column is dropped; a non-numeric term leaves it a LIKE that matches nothing.
- Anything that is *not* a column on that table — a team or creator name — reaches the search through an **aliased join in `newScoutQuery()`** plus a dotted key like `search_team.name` in `toSearchableArray()` (`qualifyColumn()` leaves a key containing a dot alone). All three models do it this way. `search()`'s second argument, the **engine callback**, can also reach such a column and is the obvious shortcut — do not use it. See the note below on why it broke.

The engine's SQL is plain `LIKE` until a column carries `#[SearchUsingFullText]`, at which point it emits `MATCH ... AGAINST` and needs a real FULLTEXT index — `Document::toSearchableArray()` is the one that does, and it is why the suite runs on MySQL. Declaring such a column also silently drops the engine's own `id` tie-break, which is why both controllers keep an explicit `orderBy` on the key. One last incompatibility: `Laravel\Scout\Builder::paginate()` takes no pre-counted total, so the `per_page=-1` ("All") path builds its `LengthAwarePaginator` by hand from `get()`.

`Document` is the harder case and is worth reading before touching either search:

- **Never search a related column through the engine callback — join it in `newScoutQuery()` instead.** That hook (undocumented but real; `DatabaseEngine::newSearchQuery()` looks for it) lets the joined column sit *inside* the engine's OR group. The callback appends at the **top level**, beside that group, and `AND` binds tighter than `OR` — so anything the engine adds afterwards attaches to the last branch only.
  - This is not theoretical: `User` used a callback for its team name, and it silently broke the day users became soft-deletable. `constrainForSoftDeletes()` also appends at the top level, so the query read `(name OR email) OR EXISTS(team) AND deleted_at IS NULL` — a binned user reappeared in the live listing as soon as their name was searched, and `trashed=only` returned **every live user** whose email matched. Found by probing the running app, not by the suite; `UserCrudTest`'s "keeps a binned user out of a searched live listing" and "keeps live users out of a searched trash listing" now pin it.
  - Scope nesting is not a safety net here. Eloquent's `Builder::callScope()` nests existing wheres before a *local scope* adds its own, which is why `scopeVisibleTo()` survived the same shape on documents and images — but `withTrashed()`/`withoutTrashed()`/`onlyTrashed()` are Builder **macros**, not scopes, so nothing wraps anything for them. `DocumentCrudTest`'s "keeps a search inside the callers team" guards the scoped case.
- **A full-text column removes the engine's tie-break.** `DatabaseEngine` appends its own `id desc` only `when(! $this->getFullTextColumns($builder))`, so declaring one full-text column silently drops it and paging over a tied sort starts repeating rows. `DocumentController::index()` carries an explicit `orderBy('documents.id')` — qualified, because `id` is ambiguous once `newScoutQuery()` has joined.
- **Full-text semantics are not LIKE semantics.** `description` matches whole words, ignores terms under `innodb_ft_min_token_size` (3) and anything on InnoDB's stopword list, and finds nothing for a mid-word fragment. `title` deliberately keeps its `%term%` LIKE so partial-title search still works. `id` is deliberately *not* searchable on `Document` (it is on `User`): the searchable set belongs to the model class, so it could not be limited to the admin table when the gallery calls the same endpoint.
- **`scout.soft_delete` is `true`, and the widening must live on the Scout builder.** `Searchable::search()` seeds a `__soft_deleted = 0` where; Scout's `Builder::withTrashed()` removes it and `onlyTrashed()` flips it to `1`; `DatabaseEngine::constrainForSoftDeletes()` turns whichever survives into `withoutTrashed()` / `withTrashed()` / `onlyTrashed()` on the Eloquent query. `DocumentController::index()` and `ImageController::index()` therefore call `->withTrashed()` / `->onlyTrashed()` **before** `->query()`, not inside it.
  - **Why it is on**, given the database engine works either way: `constrainForSoftDeletes()` is the only thing that keeps trashed rows reachable *through Scout*. On a third-party engine the index decides which rows exist and `Searchable::queryScoutModelsByIds()` merely hydrates them, so the `->query()` callback cannot add back rows the index never returned — the bin would silently come back empty the day `SCOUT_DRIVER` changed. It is one global flag but per-model in effect: `usesSoftDelete()` tests the model's own traits, so `User` is untouched.
  - **The trap**, and it is a silent one either way: the config and the builder calls only work as a pair. Turn the config off and the seeded where disappears, leaving `->withTrashed()` with nothing to act on. Move the calls into `->query()` and `constrainForSoftDeletes()` — which runs *after* that callback — overrides them, asking for `deleted_at` both `is null` and `is not null`, so `trashed=only` returns nothing and `trashed=with` returns only live rows. Both halves are pinned by `expect(config('scout.soft_delete'))->toBeTrue()` in `DocumentCrudTest` and `ImageTrashTest`, beside the `honours the trashed flag` tests. Measured, not inferred.

`Image` is the same shape as `Document` and was migrated the same way — one aliased join in `Image::newScoutQuery()` for the creator name, `description` full-text, `title` LIKE, the team scope (`Image::scopeVisibleTo()`, an EXISTS through `documents`) ANDed outside the search group, and a qualified `images.id` tie-break. Two differences worth knowing: an **absent** `per_page` means *everything* on this endpoint, not the model default — the gallery relies on it to get a document's whole set — and `document_id` is **no longer searchable**. It used to be matched exactly for an all-digit term; Scout can only LIKE a declared column, and `document_id LIKE '%1%'` would match documents 1, 10, 11 and 21. Nothing was lost, because the only image search box is the gallery's, which is always pinned to one document by the `document_id` *filter*.

`src/components/FormDialog.vue` is the shared dialog shell (title + slot) — it was `Categories/CategoryDialog.vue` until the users screen needed the same chrome, so old references to that path are stale.

## Admin section

`req.txt` puts categories, teams and users management behind super-admin. They live under an `/admin` path prefix, alongside a fourth screen the spec does not name — `src/pages/admin/documents.vue`, the team-scoped documents table — so the directory is `src/pages/admin/{categories,documents,teams,users}.vue`, and all four are real screens.

There is deliberately **no** `pages/admin.vue` parent layout: the admin area reuses the same shell as the rest of the app (`App.vue` supplies app bar, drawer, and profile menu), and only the drawer's items differ. Bare `/admin` is unrouted — the entry point links to `admin-users`.

The prefix is for organization and a cheap guard check, **not** security. Route paths ship in the JS bundle and any router guard runs in the client's browser, so hiding nav items hides nothing. Real enforcement has to be a backend policy returning 403.

### Super-admin is a column, not a role and not a team

`App\Enums\RoleName` (`super-admin`/`admin`/`member`) is the vocabulary the API reports a role in, but only two of the three are spatie roles. `RoleSeeder` seeds **`admin` and `member`** with `team_id` null so one definition is reusable by every team. `RoleName::SuperAdmin` is **never assigned through spatie** — it is produced by `User::role()`, which reads the `users.is_super_admin` boolean column first and falls back to the team role.

Two questions recur here, so both answers are written down:

- **Why not a spatie role?** Every spatie assignment in this app is scoped to exactly one team (`model_has_roles.team_id` is `NOT NULL` and part of the primary key). Super-admin is global, above teams — there is no team it could honestly belong to.
- **Why not a reserved `team_id` (a "system team 1")?** Team ids auto-increment, so a reserved id is a real row: it would appear in `/api/teams/picker` and the admin teams table, and be renameable or soft-deletable by the very screen it is meant to guard. It also fights `UserController::applyTeamInput()`, which calls `assignToTeam(null)` precisely to keep super-admins out of every team, and the one-team-per-user invariant `assignToTeam()` enforces. A prior revision of this app did use a `GLOBAL_TEAM_ID = 0` sentinel; the column replaced it deliberately. Don't reintroduce it.

The flag is **not fillable** — registration and the profile update both mass-assign, so it is set only by direct assignment (`UserController::store`) or `forceFill` (`UserController::update`, `PromoteSuperAdminCommand`). There is no first super-admin to grant the flag through the app, so `php artisan app:promote-super-admin <email>` bootstraps one; later promotions belong to the users endpoints.

Authorization hangs off a single `Gate::before` in `AppServiceProvider` that grants a super-admin every ability. It returns **true or null, never false** — returning false there would deny every check in the app rather than just the one. That inverts how the policies read: `UserPolicy` and `TeamPolicy` have methods that all `return false`, which is not a stub but the whole point, since the gate has already short-circuited for the only role allowed through. Adding a role check inside a policy method would be dead code. It also means a rule that must bind super-admins too cannot live in a policy — `UserController`'s "you may not delete or demote yourself" guards are `abort_if`s in the controller for exactly that reason.

### Teams and the ambient team context

Roles come from `spatie/laravel-permission` with its teams feature on (`config/permission.php`: `'teams' => true`, `'models.team' => Team::class`).

There is **no `team_id` column on `users` and no `team_user` pivot**: membership *is* the `model_has_roles` row, so a column would be a second source of truth. That has consequences worth knowing before touching this code:

- `User::assignToTeam(?Team, RoleName)` is the **only writer** of membership. It deletes any existing pivot rows first, which is what enforces one team per user — spatie itself would happily hold an assignment per team. Passing `null` removes the user from every team.
- The package memoises role lookups per process and per team context, so anything that switches `setPermissionsTeamId()` must `unsetRelation('roles')` (or `forgetCachedPermissions()`) around the switch — see `assignToTeam()` and `seedRoles()` in `tests/Pest.php`.
- `User::teamAssignment()` answers "which team is this user in at all", so it queries `model_has_roles` directly rather than going through the `roles` relation, which spatie filters by the ambient team. Trashed teams are excluded, so a soft-deleted team reads as no membership.
- Because `UserResource` reads `teamAssignment()` for both `role` and `team`, any endpoint serving a *collection* of `UserResource` must use `User::scopeWithTeamAssignment()` (three correlated subselects) or it is an N+1 — `UserController::index` does, alongside `->with('media')`.

The ambient team is set per request by `App\Http\Middleware\SetPermissionsTeam`, registered **inside the `auth:sanctum` route group in `routes/api.php`, not in `bootstrap/app.php`** — prepending to the api stack would run before the session starts and leave `$request->user()` null on every request. It sets `null`, not a sentinel, for a team-less user: null matches no pivot row on reads, and makes an `assignRole()` without a team fail loudly against the `NOT NULL` column rather than writing an orphan. `PermissionsTeamContextTest` asserts that.

`DatabaseSeeder` is orchestration only: it seeds roles, two team-less super-admins, categories, then `TeamSeeder` (15 teams, 3-7 members each, via `DocumentSeeder` 3-5 documents each and 4-7 images per document), then 100 unassigned users, then `binSamples()` to put a row in every bin. So after a fresh `db:seed` most users have a team and `getPermissionsTeamId()` resolves, while a super-admin's is null.

The SPA branches on `UserResource`'s `is_super_admin` (alongside `role`, `team`, `created_at`/`updated_at`): `useNavLinks.ts` drops the Admin nav entry for everyone else, and `authGuard` in `src/plugins/router.ts` bounces any `/admin` path home. Both are cosmetic — the policies are what deny.

Editing a user's avatar goes through `User::applyAvatarInput()`, shared by the Fortify profile action and `UserController::store`/`update` so all three agree on what the request means: `avatar` replaces (the collection is `singleFile()`, so no explicit clear is needed) and `remove_avatar` just empties the collection, which falls back to the shipped default image. The flag arrives over multipart as the string `"1"` — hence the `filter_var` rather than a truthiness check — because the SPA sends the update as a POST spoofing PATCH with `_method`, the only way a file can ride along. Add avatar handling to a new endpoint by calling that helper, not by re-implementing either half.

**Categories are gated too, as of `CategoryPolicy`** — `index`, `store`, `update`, `destroy` and `restore` all call `Gate::authorize`. `picker` deliberately does not: tagging an image needs the category list and every user uploads images, so it is the one category endpoint open to any authenticated user. That asymmetry is why categories get a policy of their own rather than reusing the teams shape, where even the picker is super-admin only.

## Images

Images are their own model — `App\Models\Image`, soft-deleted, `belongsTo` a user and `belongsToMany` categories — each owning a single uploaded file in a Media Library collection named by the `Image::IMAGES_COLLECTION` constant. Use that constant rather than the literal `'images'`.

- `Image` implements `HasMedia` / uses `InteractsWithMedia`; the collection is `singleFile()`, accepts the three MIME types in `Image::ACCEPTED_MIME_TYPES` (jpeg/png/webp), and registers one non-queued `thumb` conversion (400×400).
- **There is no standalone images screen.** The only caller of `ImageController::index` is `components/Images/ImageGallery.vue`, the infinite-scrolled feed on `documents/[id]`, and it is always pinned to one document — `document_id` is not even in its sort list, being "fixed on this page". Assume that equality filter is present when reasoning about this endpoint's query plans.
- The listing is **team-scoped, not owner-scoped**: `ImageController::index` applies `Image::scopeVisibleTo()`, so a member sees their teammates' images too and simply cannot edit them. `owner=mine` and `document_id` are filters layered on top, applied after the scope so they only ever narrow. `store` still goes through `$request->user()->images()`; `destroy` and `restore` defer to `ImagePolicy` rather than an ownership `abort_if`.
- Search runs through Scout — `title` and the creator's name by LIKE, `description` through a FULLTEXT index. See the Scout notes near the top before changing what is searchable.
- `update` replaces the file only when one is uploaded — otherwise it renames the existing media to match the new title, so the media row's `name` never drifts from `images.title`.
- `ImageResource` exposes `url`/`thumb_url` from `getFullUrl()`. Media Library's `disk_name` is `public`, whose URL is derived from **`APP_URL`** (`config/filesystems.php`) — so image URLs break if `APP_URL` is wrong or if `php artisan storage:link` has not been run. Both are easy to overlook after moving the project folder, since the `public/storage` link is absolute.
- Validation rules are duplicated in the same style as the user rules: `app/Rules/ImageValidationRules.php` mirrors `frontend/src/composables/useImageValidationRules.ts`. Change both together.
- `tests/Feature/ImageUploadTest.php` and `ImageUpdateTest.php` cover auth, upload, non-image rejection, listing scope, both delete paths, and the rename-vs-replace branch; `ImageIndexTest.php` and `ImageTrashTest.php` cover paging, sorting, the bin and the LIKE side of search. The full-text half lives in `ImageFullTextSearchTest.php`, which uses `DatabaseMigrations` for the reason given in the testing notes — a `RefreshDatabase` transaction cannot see a FULLTEXT index.

## Commands

Backend (`cd backend`; bare `php` works in Git Bash, PowerShell, and cmd — it resolves to Herd's PHP 8.5 via shims in `~\bin`):

```bash
composer setup                 # install, .env, key, migrate
composer dev                   # serve + queue:listen + schedule:work concurrently
composer test                  # config:clear then php artisan test
php artisan test --filter=Name # single test
vendor/bin/pest tests/Feature/ExampleTest.php
vendor/bin/pint                # format
php artisan route:list --except-vendor
```

The app is also served by Laravel Herd at `http://gallu.test` (`APP_URL`), so `php artisan serve` is usually unnecessary for backend-only work.

The SPA, however, talks to `http://localhost:8000` (`frontend/.env`'s `VITE_API_BASE_URL`), i.e. `php artisan serve` as started by `composer dev` — **not** `gallu.test`. This is deliberate: session-cookie auth needs both ends on `localhost` to stay same-site. Pointing the SPA at `gallu.test` breaks login unless you also add it to `SANCTUM_STATEFUL_DOMAINS`, set `SESSION_SAME_SITE=none` + `SESSION_SECURE_COOKIE=true`, and run `herd secure gallu` for HTTPS.

Frontend (`cd frontend`):

```bash
npm run dev          # Vite on port 3000
npm run build        # type-check + build
npm run type-check   # vue-tsc
npm run lint:fix     # eslint (eslint-config-vuetify)
npm run test         # vitest run (one-shot)
npm run test:live    # vitest watch
npm run mcp          # ruler apply — regenerates AGENTS.md from .ruler/
```

## Frontend testing

Vitest + `@vue/test-utils` + `@pinia/testing` on jsdom. Config lives in the `test` block of `vite.config.mts` (there is no separate `vitest.config.*`), so tests inherit the app's plugins and the `@` alias for free.

- Only `src/**/__tests__/**/*.spec.ts` is collected — a spec anywhere else is silently ignored. Tests sit in a `__tests__/` folder beside the code they cover (`src/stores/__tests__/team.spec.ts`, `src/components/Users/__tests__/UserFields.spec.ts`).
- `vitest.setup.ts` shims `ResizeObserver` and `matchMedia`, neither of which jsdom provides and both of which Vuetify's layout/display code needs. Anything mounting a Vuetify component depends on it.
- `vuetify` is in `test.server.deps.inline` because its components `import` CSS directly, which Node can't handle natively. New deps that ship raw CSS imports need the same treatment.
- `plugins/__tests__/router.spec.ts` covers `authGuard` directly rather than driving a real navigation — the exported-function seam exists so tests don't lazy-load every page component.

## Authentication architecture

Session-cookie SPA auth (Sanctum stateful + Fortify), **not** token auth. The pieces that must stay in sync:

- Fortify routes are published under the `api` prefix with `web` middleware (`config/fortify.php`), so the SPA calls `/api/login`, `/api/register`, `/api/logout`, `/api/user/profile-information`.
- `bootstrap/app.php` calls `$middleware->statefulApi()`; `SANCTUM_STATEFUL_DOMAINS=localhost:3000` and `FRONTEND_URL=http://localhost:3000` (the only allowed CORS origin, `supports_credentials: true`).
- The axios client (`frontend/src/plugins/axios.ts`) uses `withCredentials` + `withXSRFToken`; every auth call in `stores/auth.ts` first hits `/sanctum/csrf-cookie`.
- A 401 response anywhere clears the Pinia session and redirects to `login` (axios response interceptor).
- Enabled Fortify features: registration, password reset, profile update, password update. Email verification, 2FA, and passkeys are commented out — enabling one means also adding the SPA side.

Auth bootstrapping order matters: `plugins/index.ts` `await`s `useAuthStore().fetchUser()` **before** `app.use(router)`, so the router guard in `plugins/router.ts` can synchronously decide between the public routes (`login`, `register`) and everything else. Don't move the fetch after router registration.

## Localization

Both sides are bilingual en/ar and are wired together:

- axios sends `Accept-Language: <current i18n locale>` on every request; `SetLocaleFromHeader` middleware (prepended to both `web` and `api` stacks) applies it if it's in `config('app.available_locales')` (`['en','ar']`).
- Backend messages come from `lang/{en,ar}/`; throttle responses are rendered as JSON with a translated `auth.throttle` message in `bootstrap/app.php`.
- Frontend messages are `src/locales/{en,ar}.json`, merged with Vuetify's own locale packs; `src/types/i18n.d.ts` types `t()` keys off `en.json`, so add new keys to `en.json` first. Default locale is `ar`.

## Frontend conventions

- Routing is file-based (`vue-router/vite` + `unplugin-vue-router`) over `src/pages/`; `(auth)/` is a pathless group. The documents listing **is** the index route: `src/pages/index.vue` is the list, named `home`, and `/documents` survives only as a redirect declared in `plugins/router.ts`. There is no settings page — it was a stub the spec never asked for, and `req.txt` lists no such screen. Route *names* are generated (`home`, `admin-users`, `login`, …; the document page is the path-shaped `/documents/[id]`, having no name of its own) and used throughout — `typed-router.d.ts` is generated, don't edit it.
- Vuetify components and `src/components/*` are auto-imported (`vite-plugin-vuetify` + `unplugin-vue-components`); `components.d.ts` is generated.
- Both Vuetify and Tailwind v4 are active; Tailwind utilities are used for spacing/typography tweaks alongside Vuetify props and theme color tokens (`bg-tertiary`, `text-on-tertiary`).
- `@` aliases `src/`.
- The nav drawer has two modes. `useNavLinks.ts` exposes `links`, which swaps between `mainLinks` and `adminLinks` on `route.path.startsWith("/admin")`; `TheNav.vue` binds `links` and renders it through `<v-list :items>`, so items are added by editing the arrays, not the markup. Each entry's `value` must equal its real path (`/admin/teams`) — `v-list`'s `mandatory` prop matches `value` against the route, and a mismatch silently kills the active highlight.
- Truncation is bidi-correct globally, and must stay that way. `src/styles/main.scss` applies `unicode-bidi: plaintext` — dir="auto" as CSS — plus a physical left/right alignment pin to every truncating surface: `.truncate`, `.text-truncate`, `line-clamp-*`, `.v-card-title`/`-subtitle`, `.v-list-item-title`/`-subtitle`, and nowrap data-table cells. Without it the Arabic UI clips a Latin value at its *start*. Do **not** re-add the old per-site patch (`dir="auto"` + `:class="isRtl ? 'text-right' : 'text-left'"`) — it was removed from about ten files in favour of that block. For user text that does not truncate but can disagree with the UI script, add the `bidi-auto` class; for text that is always in the UI locale, plain `text-start` is right. Form fields keep their `dir="auto"`: there it also drives caret and typing direction, which CSS cannot.
- Validation rules are duplicated by design and split by domain: `src/composables/use{Auth,Category,Image,Team}ValidationRules.ts` mirror `backend/app/Rules/UserValidationRules.php` and `ImageValidationRules.php`. Change both sides together.

## Backend conventions

- API responses go through `app/Http/Resources/*Resource.php`; `/api/user` returns `UserResource`, so the SPA reads `data.data`.
- Shared validation lives in `app/Rules/UserValidationRules.php` as static methods, referenced from the Fortify actions in `app/Actions/Fortify/` — extend those rather than inlining rules in actions.
- Login is rate-limited to 5/min per email+IP (`FortifyServiceProvider`).
- Tests run on **MySQL** (`gallu_test`, set in `phpunit.xml`), the same engine as dev (`gallu`). They used to run on in-memory SQLite; the suite moved when `documents.description` gained a FULLTEXT index, which SQLite cannot create or query at all. The upside is that tests now exercise production's exact SQL, so MySQL-only constructs are fair game. The costs: the schema has to exist before the suite will run, and a run went from ~20s to ~50s.
- **`RefreshDatabase` cannot see a FULLTEXT index.** InnoDB processes full-text index updates at *commit* time, and `RefreshDatabase` wraps each test in a transaction it never commits — so `MATCH ... AGAINST` finds nothing a test just inserted while a `LIKE` on the same row finds it. Negative assertions then pass for the wrong reason, which is the dangerous part. `tests/Feature/DocumentFullTextSearchTest.php` uses `DatabaseMigrations` instead and is kept small because each test costs a `migrate:fresh`. Anything not searching a full-text column belongs in the normal `RefreshDatabase` files.
