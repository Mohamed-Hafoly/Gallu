<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\Request;
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
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        $documents = Document::query()
            ->visibleTo($request->user())
            ->with(self::with())
            ->withCount('images')
            ->latest()
            ->get();

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function store(StoreDocumentRequest $request): DocumentResource
    {
        Gate::authorize('create', Document::class);

        $document = $request->user()
            ->documents()
            ->create([
                ...$request->safe()->only(['title', 'description']),
                // Stamped now so the entry stays with the team it was made for,
                // even after its author moves teams. Null only for a super-admin,
                // who belongs to no team; such a document is super-admin-only.
                'team_id' => $request->user()->teamAssignment()['team_id'] ?? null,
            ]);

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        Gate::authorize('update', $document);

        $document->update($request->safe()->only(['title', 'description']));

        return new DocumentResource($document->load(self::with())->loadCount('images'));
    }

    public function destroy(Document $document): Response
    {
        Gate::authorize('delete', $document);

        // images.document_id cascades, so the images go with it.
        $document->delete();

        return response()->noContent();
    }

    public function restore(Document $document): DocumentResource {


        Gate::authorize('restore', $document);

        abort_if(! $document->trashed(), 404);

        $document->restore();

        return new documentResource($document->load('user')->loadCount('members'));

    }
}
