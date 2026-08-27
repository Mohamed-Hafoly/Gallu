<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
     * `creator` is not a column - see index(), which maps it to a subquery.
     * `team` is absent deliberately: the admin table's team header is not
     * sortable.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'title', 'creator', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * The API's name for the document owner's name, which lives on `users`.
     */
    public const CREATOR_SORT = 'creator';

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
     * @return array<string, mixed>
     */
    private static function with(): array
    {
        return [
            'images' => fn ($query) => $query->latest()->limit(self::COVER_IMAGES),
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

        $query = Document::query()
            ->visibleTo($request->user())
            // Widens which rows survive the soft-delete scope, never which team
            // they belong to - visibleTo() has already narrowed that, and this
            // runs after it.
            ->when($trashed === 'with', fn ($builder) => $builder->withTrashed())
            ->when($trashed === 'only', fn ($builder) => $builder->onlyTrashed())
            // Grouped, so the ORs cannot escape the scope above and turn a
            // search into a cross-team read.
            ->when($search !== '', fn ($builder) => $builder->where(
                fn ($grouped) => $grouped
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
            ))
            // `team` is rendered by the admin table and `images_count` by both
            // callers, so these are never conditional.
            ->with(['user', 'team'])
            ->withCount('images')
            // Only the gallery's card grid wants the cover thumbnails; the admin
            // table shows a count and links to the document page, which fetches
            // its own images. See IndexDocumentRequest for why this is a
            // parameter.
            ->when($request->boolean('cover'), fn ($builder) => $builder->with(self::with()));

        // `creator` is the API's name for the owner's name, which lives on
        // `users`. A correlated subselect rather than a join, so the sort cannot
        // duplicate rows when a document has several images - the same technique
        // ImageController::index and User::scopeWithTeamAssignment() use.
        $query->when(
            $sortBy === self::CREATOR_SORT,
            fn ($builder) => $builder->orderBy(
                User::select('name')->whereColumn('users.id', 'documents.user_id'),
                $direction,
            ),
            fn ($builder) => $builder->orderBy($sortBy, $direction),
        );

        // Absent means *everything*, as in ImageController::index and unlike
        // IndexUserRequest's caller: this endpoint's second caller is the
        // gallery, which expects every document it may see. An explicit 0
        // cannot reach here - `not_in:0` makes it a 422, so integer() only
        // returns 0 for a missing param.
        $perPage = $request->integer('per_page');
        $total = null;

        if ($perPage === self::ALL_PER_PAGE || $perPage === 0) {
            // Not simply paginate(-1): a negative limit is dropped by the query
            // builder while the offset is still emitted, and `OFFSET` without
            // `LIMIT` is a syntax error in both SQLite and MySQL. Counting once
            // and paginating by that keeps a single page and an honest
            // meta.per_page - the total is handed back so paginate() does not
            // run the same count a second time.
            $total = $query->toBase()->getCountForPagination();
            $perPage = max($total, 1);
        }

        return DocumentResource::collection($query->paginate($perPage, total: $total));
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

        $document->restore();

        return new DocumentResource($document->load(['user', 'team'])->loadCount('images'));
    }
}
