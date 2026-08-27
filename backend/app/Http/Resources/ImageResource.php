<?php

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Image
 */
class ImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->getFirstMedia(Image::IMAGES_COLLECTION);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'url' => $media?->getFullUrl(),
            'thumb_url' => $media?->getFullUrl('thumb'),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            // Unconditional, unlike CategoryResource's whenLoaded() equivalent:
            // images.user_id is NOT NULL and cascades on delete, so an image
            // without a creator cannot exist. whenLoaded() would conflate that
            // invariant with "the caller forgot to eager load", hiding the bug
            // by silently dropping the key. Read directly instead, so a missing
            // ->with('user') throws a LazyLoadingViolationException in dev and
            // CI (Model::shouldBeStrict in AppServiceProvider) rather than
            // quietly serving images with no creator.
            //
            // `user_id` is unconditional for the same reason but needs no eager
            // load at all — it is a local column, not a relation. It exists
            // because `creator` is a display name: two users sharing one would
            // be indistinguishable, so the SPA cannot decide "is this mine?"
            // from the name. ImageDetailDialog needs that to decide whether to
            // offer Edit and Delete, mirroring ImagePolicy::update.
            'user_id' => $this->user_id,
            'creator' => $this->user->name,
            'document_id' => $this->document_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Null on every live image, which is exactly how the admin screen
            // splits its two tables. The gallery receives it too and ignores
            // it — its listing can never contain a trashed row anyway.
            'deleted_at' => $this->deleted_at,
        ];
    }
}
