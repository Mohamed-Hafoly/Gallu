<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            // Present only for the gallery, which asks for it with cover=1: at
            // most DocumentController::COVER_IMAGES, newest first — enough for
            // the card's 2x2 grid, not the document's whole contents. The admin
            // table omits the flag and gets no key at all, then asks
            // /api/images?document_id= when a row is expanded.
            'images' => ImageResource::collection($this->whenLoaded('images')),
            // The real total, which the truncated relation above cannot give —
            // and the only image information the admin listing carries.
            'images_count' => $this->whenCounted('images'),
            // Still nullable, though documents.team_id is NOT NULL: team() is
            // a belongsTo onto a soft-deleting model, so it resolves to null for
            // a *trashed* team wherever the relation was loaded without
            // withTrashed() - self::with() does exactly that, which is the path
            // show/store/update take. Null here means trashed, never absent.
            'team' => $this->whenLoaded('team', fn () => $this->team === null ? null : [
                'id' => $this->team->id,
                'name' => $this->team->name,
                // Non-null means an individual restore is refused with a 409:
                // the team has to come back first, and it brings its documents
                // with it. Read from the loaded relation rather than served as
                // a top-level flag, so it costs no extra query and cannot drift
                // from the name shown beside it.
                //
                // The listing loads `team` through withTrashed(), so this is a
                // real answer there — see Document::teamIsTrashed(), which is
                // what the endpoint actually enforces.
                'deleted_at' => $this->team->deleted_at,
            ]),
            // Null once the author is binned: belongsTo(User) carries User's
            // soft-delete scope, so this reads null for a deleted author while
            // they are still recoverable, and again permanently if the row is
            // ever force deleted - documents.user_id is nullOnDelete. The SPA
            // renders either case as "[deleted]"; the content itself belongs to
            // the team and stays where it is.
            //
            // Still unconditional rather than whenLoaded(): a missing eager load
            // must throw under Model::shouldBeStrict rather than silently
            // dropping the key, which is the distinction whenLoaded() would lose.
            'creator' => $this->user?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Null for a live document; the admin screen reads it to tell the
            // two states apart, as the category and team resources do.
            'deleted_at' => $this->deleted_at,
        ];
    }
}
