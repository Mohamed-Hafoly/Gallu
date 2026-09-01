<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    /**
     * How many images a card's 2x2 cover grid shows.
     */
    private const COVER_IMAGES = 4;

    /**
     * Columns the table may sort on. Anything else is a 422 from
     * IndexDocumentRequest rather than an injectable orderBy.
     *
     * Neither `creator` nor `team` is a column - see index(), which maps both
     * to subqueries. `images_count` is not one either: it is withCount()'s
     * select alias, which both MySQL and SQLite resolve in ORDER BY, so it
     * needs no branch of its own.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'title', 'creator', 'team', 'images_count', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * The API's name for the document owner's name, which lives on `users`.
     */
    public const CREATOR_SORT = 'creator';

    /**
     * The API's name for the owning team's name, which lives on `teams`.
     */
    public const TEAM_SORT = 'team';

    /**
     * What the table's "All" option sends for per_page.
     */
    public const ALL_PER_PAGE = -1;

    /**
     * The relations every DocumentResource needs.
     *
     * `images` is bounded to the newest few rather than loaded whole: the card
     * only renders a 2x2 cover, and an unbounded load would serialise every
     * image of every document — invisible at four each, ruinous at two hundred.
     * withCount() supplies the real total, which the truncated relation can no
     * longer be asked for.
     *
     * `images.user` is required, not merely nice: the nested ImageResource reads
     * `creator` unconditionally, so omitting it throws under
     * Model::shouldBeStrict rather than failing quietly.
     *
     * `images.media` is the opposite case — ImageResource calls getFirstMedia(),
     * and Media Library's helpers do not go through Eloquent's guarded path, so
     * a missing eager load there is an N+1 that strict mode will *not* catch.
     *
     * $trashedImages is what makes a trashed document's card look like anything
     * at all: Document::booted() soft-deletes the images alongside it, so the
     * default scope hides every one of them and the cover falls back to the
     * empty-folder icon. Only the trashed listing passes true — a live document
     * must never show images that were binned on their own.
     *
     * @return array<string, mixed>
     */
    private static function with(bool $trashedImages = false): array
    {
        return [
            'images' => fn ($query) => $query
                ->when($trashedImages, fn ($images) => $images->withTrashed())
                ->latest()
                ->limit(self::COVER_IMAGES),
            'images.media' => fn ($query) => $query,
            'images.categories' => fn ($query) => $query,
            'images.user' => fn ($query) => $query,
            'user' => fn ($query) => $query,
            // So show(), store() and update() report `team` too. Without it the
            // resource's whenLoaded() drops the key, and a created document
            // would come back describing every field except the one just picked.
            'team' => fn ($query) => $query,
        ];
    }

    /**
     * Refuse a team the caller has no business filing a document under.
     *
     * Not in DocumentPolicy: Gate::before grants a super-admin every ability, so
     * a policy check would never be reached for the one role allowed to choose
     * freely. Everyone else is pinned to their own team, whatever the picker
     * sent — which on update() also means they cannot move a document out of it.
     */
    private static function authorizeTeam(User $user, int $teamId): void
    {
        abort_if(
            ! $user->is_super_admin
                && $teamId !== ($user->teamAssignment()['team_id'] ?? null),
            403,
        );
    }

    public function index(IndexDocumentRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        $trashed = $request->input('trashed');

        // Authorised rather than silently ignored: a member who forges the flag
        // should be told no, not handed a quietly narrower list they cannot
        // distinguish from an empty trash.
        if ($trashed !== null) {
            Gate::authorize('viewTrashed', Document::class);
        }

        $search = $request->string('search')->trim()->toString();
        $sortBy = $request->input('sort_by') ?: 'id';
        $direction = $request->input('sort_order') ?: 'asc';

        // Which columns are searched is Document::toSearchableArray()'s to say,
        // and the engine wraps them in one OR group. The creator and team names
        // are inside that group through the joins Document::newScoutQuery()
        // adds - deliberately not through search()'s callback, which appends at
        // the top level where an OR would escape the visibleTo() below. Read the
        // note on newScoutQuery() before moving either.
        //
        // An empty term is no search at all: the engine leaves the query alone
        // when the term is blank, so this needs no conditional.
        $builder = Document::search($search)
            // The bin, on the Scout builder rather than inside query() below.
            // That placement is the whole point of scout.soft_delete being on:
            // search() seeds a __soft_deleted = 0 where, withTrashed() drops it
            // and onlyTrashed() flips it to 1, and the engine turns whichever
            // survives into the matching Eloquent constraint.
            //
            // It must not move into query(). constrainForSoftDeletes() runs
            // *after* that callback, so an Eloquent-level onlyTrashed() there is
            // silently overridden and the bin comes back empty.
            //
            // Widens which rows survive the soft-delete scope, never which team
            // they belong to - visibleTo() narrows that inside query(), and an
            // AND either way.
            ->when($trashed === 'with', fn ($builder) => $builder->withTrashed())
            ->when($trashed === 'only', fn ($builder) => $builder->onlyTrashed())
            // Everything that must AND with the search. The database engine
            // applies this straight to the query, so these are real constraints
            // rather than post-filters - and they land outside the search group,
            // which is exactly where the team scope has to be.
            ->query(fn (Builder $query) => $query
                ->visibleTo($request->user())
                // `team` is rendered by the admin table and `images_count` by
                // both callers, so these are never conditional.
                //
                // withTrashed() on the team, so a trashed document still reports
                // the team it belonged to rather than a bare "-". That is the
                // whole explanation for why its restore button is off:
                // DocumentResource passes the team's deleted_at through, and
                // restore() refuses while it is set.
                ->with(['user', 'team' => fn ($team) => $team->withTrashed()])
                // Counted through withTrashed() on a trashed listing, for the
                // reason in self::with(): the images went down with the document,
                // so the default scope would report 0 for every row - and the
                // count is one of the columns this listing can be sorted by.
                ->withCount(['images' => fn ($images) => $images
                    ->when($trashed !== null, fn ($query) => $query->withTrashed())])
                // Only the gallery's card grid wants the cover thumbnails; the
                // admin table shows a count and links to the document page, which
                // fetches its own images. See IndexDocumentRequest for why this
                // is a parameter.
                ->when(
                    $request->boolean('cover'),
                    fn ($query) => $query->with(self::with($trashed !== null)),
                ))
            // `creator` and `team` are the API's names for values that live on
            // other tables. Both sort on the *name* the cell shows rather than on
            // the foreign key, which would order by insertion and read as broken.
            //
            // Correlated subselects rather than the joins newScoutQuery() adds,
            // because a sort must work on an unsearched listing too - where there
            // are no joins at all. Their own `users` and `teams` are why those
            // joins are aliased.
            //
            // withTrashed() on the team subselect, so it agrees with the cell:
            // the eager load above is widened the same way, and a trashed
            // document sorts under the team name it displays rather than under
            // null. documents.team_id is nullable, so nulls remain possible
            // either way - both databases sort them first ascending.
            ->orderBy(match ($sortBy) {
                self::CREATOR_SORT => User::select('name')->whereColumn('users.id', 'documents.user_id'),
                self::TEAM_SORT => Team::withTrashed()->select('name')->whereColumn('teams.id', 'documents.team_id'),
                default => $sortBy,
            }, $direction)
            // A stable tie-break, and load-bearing here in a way it is not on the
            // users listing: none of the sortable columns is unique - documents
            // seeded in one batch share created_at to the second - and
            // LIMIT/OFFSET over a non-unique key lets the database order ties
            // differently per page, so page 2 can repeat a row from page 1 or
            // skip one.
            //
            // Scout's engine appends an id tie-break of its own only when no
            // column is declared full-text, and `description` is - so on this
            // model there is no implicit one at all. Qualified, because `id` is
            // ambiguous once newScoutQuery() has joined.
            ->orderBy('documents.id');

        // Absent means *everything*, as in ImageController::index and unlike
        // IndexUserRequest's caller: this endpoint's second caller is the
        // gallery, which expects every document it may see. An explicit 0
        // cannot reach here - `not_in:0` makes it a 422, so integer() only
        // returns 0 for a missing param.
        $perPage = $request->integer('per_page');

        if ($perPage === self::ALL_PER_PAGE || $perPage === 0) {
            // Scout's paginate() takes no pre-counted total to hand back, so the
            // single page is built from the result set rather than counted and
            // then fetched again. get() applies no limit, so it is both the page
            // and the count; max() keeps per_page positive when a search matches
            // nothing.
            $results = $builder->get();

            return DocumentResource::collection(new LengthAwarePaginator(
                $results,
                $results->count(),
                max($results->count(), 1),
                1,
                ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()],
            ));
        }

        return DocumentResource::collection($builder->paginate($perPage));
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function store(StoreDocumentRequest $request): DocumentResource
    {
        Gate::authorize('create', Document::class);

        $teamId = (int) $request->validated('team_id');
        self::authorizeTeam($request->user(), $teamId);

        $document = $request->user()
            ->documents()
            ->create([
                ...$request->safe()->only(['title', 'description']),
                // Stamped now so the entry stays with the team it was made for,
                // even after its author moves teams.
                'team_id' => $teamId,
            ]);

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        Gate::authorize('update', $document);

        // The policy has already established the document is in the caller's own
        // team; this establishes the *destination* is one they may file under,
        // so only a super-admin can move a document between teams.
        $teamId = (int) $request->validated('team_id');
        self::authorizeTeam($request->user(), $teamId);

        $document->update([
            ...$request->safe()->only(['title', 'description']),
            'team_id' => $teamId,
        ]);

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function destroy(Document $document): Response
    {
        Gate::authorize('delete', $document);

        // A soft delete, so the FK's ON DELETE CASCADE never fires — an UPDATE
        // cannot trigger it. Document::booted() is what takes the images down
        // with it, stamped with this same deleted_at so restore() can put back
        // exactly the ones it trashed.
        $document->delete();

        return response()->noContent();
    }

    public function restore(Document $document): DocumentResource
    {
        Gate::authorize('restore', $document);

        abort_if(! $document->trashed(), 404);

        // Not a DocumentPolicy rule, for the reason UserController's "you may
        // not delete or demote yourself" guards are not either: Gate::before
        // grants a super-admin every ability, and the super-admin is precisely
        // who can see and act on these rows — Document::scopeVisibleTo() hides
        // them from everyone else, since User::teamAssignment() reads a trashed
        // team as no membership. A policy method would be dead code.
        //
        // 409 rather than 422 (nothing was submitted to validate) or 403 (this
        // is a state conflict, not a permission): the caller may restore this
        // document, just not yet. The SPA reads the message off it — see the
        // axios interceptor, which surfaces `message` for any status below 500.
        abort_if($document->teamIsTrashed(), 409, __('document.teamTrashed'));

        $document->restore();

        return new DocumentResource($document->load(['user', 'team'])->loadCount('images'));
    }
}
