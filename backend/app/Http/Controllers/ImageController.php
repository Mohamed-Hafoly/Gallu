<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexImageRequest;
use App\Http\Requests\StoreImageRequest;
use App\Http\Requests\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Document;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Columns the admin tables may sort on. Anything else is a 422 from
     * IndexImageRequest rather than an injectable orderBy.
     *
     * `creator` is not a column - see index(), which maps it to a subquery.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'title', 'creator', 'document_id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * The API's name for the image owner's name, which lives on `users`.
     */
    public const CREATOR_SORT = 'creator';

    /**
     * The one accepted value of the `owner` filter, which narrows a listing to
     * the caller's own images. There is deliberately no `all` counterpart -
     * "all" is the absence of the filter, not a value of it.
     */
    public const OWNER_MINE = 'mine';

    /**
     * What the table's "All" option sends for per_page.
     */
    public const ALL_PER_PAGE = -1;

    public function index(IndexImageRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Image::class);

        $trashed = $request->input('trashed');

        // Authorised rather than silently ignored: a member who forges the flag
        // should be told no, not handed a quietly narrower list they cannot
        // distinguish from an empty trash.
        if ($trashed !== null) {
            Gate::authorize('viewTrashed', Image::class);
        }

        $search = $request->string('search')->trim()->toString();
        $sortBy = $request->input('sort_by') ?: 'id';
        $direction = $request->input('sort_order') ?: 'asc';

        // Which columns are searched is Image::toSearchableArray()'s to say, and
        // the engine wraps them in one OR group. The creator's name is inside
        // that group through the join Image::newScoutQuery() adds - deliberately
        // not through search()'s callback, which appends at the top level where
        // an OR would sit beside the group rather than within it. Read the note
        // on newScoutQuery() before moving it.
        //
        // An empty term is no search at all: the engine leaves the query alone
        // when the term is blank, so this needs no conditional.
        $builder = Image::search($search)
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
            // AND either way. The gallery sends no `trashed`, so deleted images
            // stay out of /gallery and /documents/{id}.
            ->when($trashed === 'with', fn ($builder) => $builder->withTrashed())
            ->when($trashed === 'only', fn ($builder) => $builder->onlyTrashed())
            // Everything that must AND with the search. The database engine
            // applies this straight to the query, so these are real constraints
            // rather than post-filters - and they land outside the search group,
            // which is exactly where the team scope has to be.
            ->query(fn (Builder $query) => $query
                // Team-scoped, not owner-scoped: a member sees their teammates'
                // images too, they simply cannot edit them.
                //
                // First, so Eloquent's callScope() nests the search group before
                // the plain filters below are appended beside it.
                ->visibleTo($request->user())
                // Applied after the scope, never instead of it, so filtering by
                // another team's document id returns nothing rather than leaking.
                ->when(
                    $request->filled('document_id'),
                    fn ($builder) => $builder->where('document_id', $request->integer('document_id')),
                )
                // A member's trash holds their own images; an admin's holds the
                // team's. Narrowed rather than refused, the way scopeVisibleTo
                // narrows rather than denying - a member has a trash, it is just
                // smaller. Gate::allows() rather than a role check so Gate::before
                // still lets a super-admin see everything.
                ->when(
                    $trashed !== null && ! Gate::allows('viewAllTrashed', Image::class),
                    fn ($builder) => $builder->where('user_id', $request->user()->id),
                )
                // Same rule as document_id: a filter, applied after the scope, so
                // it only ever narrows. `users.id` is the owner column's target
                // and images.user_id is NOT NULL, so this is a plain equality
                // with no null case to think about.
                ->when(
                    $request->input('owner') === self::OWNER_MINE,
                    fn ($builder) => $builder->where('user_id', $request->user()->id),
                )
                ->with(['categories', 'media', 'user']))
            // `creator` is the API's name for the owner's name, which lives on
            // `users`. A correlated subselect rather than the join
            // newScoutQuery() adds, because a sort must work on an unsearched
            // listing too - where there is no join at all. Its own `users` is
            // why that join is aliased.
            ->when(
                $sortBy === self::CREATOR_SORT,
                fn ($builder) => $builder->orderBy(
                    User::select('name')->whereColumn('users.id', 'images.user_id'),
                    $direction,
                ),
                fn ($builder) => $builder->orderBy($sortBy, $direction),
            )
            // A stable tie-break, and not optional once the feed can be sorted
            // by a non-unique column. created_at is not unique - images inserted
            // in one batch share it to the second - and LIMIT/OFFSET paging over
            // a non-unique key lets the database order ties differently per
            // page, so page 2 can repeat a row from page 1 or skip one. The
            // document page's infinite scroll would show duplicates; the admin
            // tables would too.
            //
            // Scout's engine appends an id tie-break of its own only when no
            // column is declared full-text, and `description` is - so on this
            // model there is no implicit one at all. Qualified, because `id` is
            // ambiguous once newScoutQuery() has joined.
            ->orderBy('images.id');

        // Absent means *everything*, unlike IndexUserRequest's caller, which
        // always pages. This endpoint has a second caller - the gallery, which
        // sends only document_id and expects a document's whole set. Letting
        // paginate() fall back to the model's per-page would silently cap that
        // at 15 and lose images from any document larger than that. An explicit
        // 0 cannot reach here: `not_in:0` makes it a 422, so integer() only
        // returns 0 for a missing param.
        $perPage = $request->integer('per_page');

        if ($perPage === self::ALL_PER_PAGE || $perPage === 0) {
            // Scout's paginate() takes no pre-counted total to hand back, so the
            // single page is built from the result set rather than counted and
            // then fetched again. get() applies no limit, so it is both the page
            // and the count; max() keeps per_page positive when a search matches
            // nothing.
            $results = $builder->get();

            return ImageResource::collection(new LengthAwarePaginator(
                $results,
                $results->count(),
                max($results->count(), 1),
                1,
                ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()],
            ));
        }

        return ImageResource::collection($builder->paginate($perPage));
    }

    public function store(StoreImageRequest $request): ImageResource
    {
        $document = Document::findOrFail($request->integer('document_id'));

        // Authorised against the target document, since the image does not
        // exist yet. StoreImageRequest already rejected a document outside the
        // caller's team, so this is the belt to that braces.
        Gate::authorize('create', [Image::class, $document]);

        $image = $request->user()
            ->images()
            ->create([
                ...$request->safe()->only(['title', 'description']),
                'document_id' => $document->id,
            ]);

        $image->addMediaFromRequest('image')
            ->usingName($image->title)
            ->usingFileName(Str::uuid().'.'.$request->file('image')->getClientOriginalExtension())
            ->toMediaCollection(Image::IMAGES_COLLECTION);

        $this->syncCategories($image, $request);

        return new ImageResource($image->load(['categories', 'media', 'user']));
    }

    public function update(UpdateImageRequest $request, Image $image): ImageResource
    {
        Gate::authorize('update', $image);

        $image->update($request->safe()->only(['title', 'description']));

        if ($request->hasFile('image')) {
            $image->clearMediaCollection(Image::IMAGES_COLLECTION);
            $image->addMediaFromRequest('image')
                ->usingName($image->title)
                ->usingFileName(Str::uuid().'.'.$request->file('image')->getClientOriginalExtension())
                ->toMediaCollection(Image::IMAGES_COLLECTION);
        } else {
            $image->getFirstMedia(Image::IMAGES_COLLECTION)?->update(['name' => $image->title]);
        }

        $this->syncCategories($image, $request);

        return new ImageResource($image->load(['categories', 'media', 'user']));
    }

    /**
     * Sync the picked categories, preserving attachments to soft-deleted ones.
     *
     * The picker only ever offers live categories, so a trashed one's id never
     * comes back in the payload — while sync() derives the current set from the
     * raw pivot table, which does see those rows. A plain sync therefore drops
     * them silently, and restoring the category later would find the image gone
     * from it.
     *
     * On store() the trashed set is always empty; both paths go through here
     * anyway so they cannot drift apart again.
     */
    private function syncCategories(Image $image, StoreImageRequest|UpdateImageRequest $request): void
    {
        // Qualified, since `id` is ambiguous against the pivot join.
        $trashedIds = $image->categories()->onlyTrashed()->pluck('categories.id')->all();

        // ?? [] because categories are optional and FormData omits an empty
        // array entirely, so validated() hands back null rather than [].
        $image->categories()->sync([
            ...($request->validated('selected_category_ids') ?? []),
            ...$trashedIds,
        ]);
    }

    public function destroy(Image $image): Response
    {
        // Was an abort_if on ownership; an admin may now delete a teammate's
        // image, so the policy decides instead.
        Gate::authorize('delete', $image);

        $image->delete();

        return response()->noContent();
    }

    /**
     * Undo a soft delete, from the admin screen's pending-deletion table.
     *
     * Mirrors TeamController::restore(), including the 404 on a live row: the
     * route is bound withTrashed(), so a live image resolves here perfectly
     * well and would otherwise be "restored" to no effect.
     */
    public function restore(Image $image): ImageResource
    {
        Gate::authorize('restore', $image);

        abort_if(! $image->trashed(), 404);

        // The same rule DocumentController::restore enforces one level up: a
        // live child always has a live parent, so an image cannot come back
        // into a document that is itself in the bin. Restoring the document is
        // the way out, and it takes every image in its bin with it.
        //
        // An abort_if here rather than an ImagePolicy rule, because this binds
        // every role and a policy cannot say that. Gate::before waves a
        // super-admin past any policy; and ImagePolicy::update — which
        // ::restore delegates to — returns true for the owner without ever
        // consulting the document, so an image's own uploader would sail
        // through as well.
        //
        // 409 rather than 422 or 403, as with the document guard: nothing was
        // submitted to validate, and the caller may restore this image — just
        // not yet.
        abort_if($image->documentIsTrashed(), 409, __('image.documentTrashed'));

        $image->restore();

        return new ImageResource($image->load(['categories', 'media', 'user']));
    }
}
