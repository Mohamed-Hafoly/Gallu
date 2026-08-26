<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class   DocumentResource extends JsonResource
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
            // Nullable the way UserResource's team is: a document created by a
            // super-admin before team_id was required belongs to no team.
            'team' => $this->whenLoaded('team', fn () => $this->team === null ? null : [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ]),
            // Unconditional for the same reason as ImageResource's: documents.user_id
            // is NOT NULL and cascades on delete, so a document without a creator
            // cannot exist. whenLoaded() would hide a forgotten eager load by
            // silently dropping the key; read directly and it throws under
            // Model::shouldBeStrict instead.
            'creator' => $this->user->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Null for a live document; the admin screen reads it to tell the
            // two states apart, as the category and team resources do.
            'deleted_at' => $this->deleted_at,
        ];
    }
}
