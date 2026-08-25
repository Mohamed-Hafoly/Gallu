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
            // At most DocumentController::COVER_IMAGES, newest first — enough for
            // the card's 2x2 grid, not the document's whole contents. Anything
            // needing every image asks /api/images?document_id= instead.
            'images' => ImageResource::collection($this->whenLoaded('images')),
            // The real total, which the truncated relation above cannot give.
            'images_count' => $this->whenCounted('images'),
            // Unconditional for the same reason as ImageResource's: documents.user_id
            // is NOT NULL and cascades on delete, so a document without a creator
            // cannot exist. whenLoaded() would hide a forgotten eager load by
            // silently dropping the key; read directly and it throws under
            // Model::shouldBeStrict instead.
            'creator' => $this->user->name,
            'created_at' => $this->created_at,
        ];
    }
}
