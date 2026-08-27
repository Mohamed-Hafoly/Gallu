<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexImageRequest;
use App\Http\Requests\StoreImageRequest;
use App\Http\Requests\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Document;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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

        // Team-scoped, not owner-scoped: a member sees their teammates' images
        // too, they simply cannot edit them.
        $query = Image::query()
            ->visibleTo($request->user())
            // Widens which rows survive the soft-delete scope, never which team
            // they belong to - visibleTo() has already narrowed that, and this
            // runs after it. The gallery sends no `trashed`, so deleted images
            // stay out of /gallery and /documents/{id}.
            ->when($trashed === 'with', fn ($builder) => $builder->withTrashed())
            ->when($trashed === 'only', fn ($builder) => $builder->onlyTrashed())
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
            // it only ever narrows. `users.id` is the owner column's target and
            // images.user_id is NOT NULL, so this is a plain equality with no
            // null case to think about.
            ->when(
                $request->input('owner') === self::OWNER_MINE,
                fn ($builder) => $builder->where('user_id', $request->user()->id),
            )
            // Grouped, so the ORs cannot escape the scope above and turn a
            // search into a cross-team read.
            ->when($search !== '', fn ($builder) => $builder->where(
                fn ($grouped) => $grouped
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    // Exact, and only for a numeric term: `like` on an integer
                    // column would make a search for "1" match documents 1, 10,
                    // 11 and 21, which reads as a broken filter.
                    ->when(
                        ctype_digit($search),
                        fn ($grouped) => $grouped->orWhere('document_id', (int) $search),
                    )
            ))
            ->with(['categories', 'media', 'user']);

        // `creator` is the API's name for the owner's name, which lives on
        // `users`. A correlated subselect rather than a join, so the sort cannot
        // duplicate rows when an image has several media or categories - the
        // same technique User::scopeWithTeamAssignment() uses.
        $query->when(
            $sortBy === self::CREATOR_SORT,
            fn ($builder) => $builder->orderBy(
                User::select('name')->whereColumn('users.id', 'images.user_id'),
                $direction,
            ),
            fn ($builder) => $builder->orderBy($sortBy, $direction),
        );

        // A stable tie-break, and not optional once the feed can be sorted by a
        // non-unique column. created_at is not unique - images inserted in one
        // batch share it to the second - and LIMIT/OFFSET paging over a
        // non-unique key lets the database order ties differently per page, so
        // page 2 can repeat a row from page 1 or skip one. The document page's
        // infinite scroll would show duplicates; the admin tables would too.
        $query->orderBy('id');

        // Absent means *everything*, unlike IndexUserRequest's caller, which
        // always pages. This endpoint has a second caller - the gallery, which
        // sends only document_id and expects a document's whole set. Letting
        // paginate() fall back to the model's per-page would silently cap that
        // at 15 and lose images from any document larger than that. An explicit
        // 0 cannot reach here: `not_in:0` makes it a 422, so integer() only
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

        return ImageResource::collection($query->paginate($perPage, total: $total));
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

        $image->categories()->sync($request->input('selected_category_ids'));

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

        $image->categories()->sync($request->input('selected_category_ids'));

        return new ImageResource($image->load(['categories', 'media', 'user']));
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

        $image->restore();

        return new ImageResource($image->load(['categories', 'media', 'user']));
    }
}
