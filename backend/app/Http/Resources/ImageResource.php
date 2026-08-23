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
            'creator' => $this->user->name,
            'created_at' => $this->created_at,
        ];
    }
}
