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
            // Both unconditional, unlike CategoryResource's whenLoaded()
            // equivalent: whenLoaded() would conflate "no creator" with "the
            // caller forgot to eager load", hiding the second by silently
            // dropping the key. Read directly instead, so a missing
            // ->with('user') throws a LazyLoadingViolationException in dev and
            // CI (Model::shouldBeStrict in AppServiceProvider).
            //
            // `creator` is null-safe because it can genuinely be absent now:
            // belongsTo(User) carries User's soft-delete scope, so it reads null
            // while a binned author is still recoverable, and again permanently
            // if the row is ever force deleted - images.user_id is nullOnDelete
            // since users became soft-deletable. The SPA renders either as
            // "[deleted]"; the image belongs to the team and stays put.
            //
            // `user_id` needs no eager load at all - it is a local column, not a
            // relation. It exists because `creator` is a display name: two users
            // sharing one would be indistinguishable, so the SPA cannot decide
            // "is this mine?" from the name. ImageDetailDialog needs that to
            // decide whether to offer Edit and Delete, mirroring
            // ImagePolicy::update. It is null for a deleted author too, so
            // neither offer is made.
            'user_id' => $this->user_id,
            'creator' => $this->user?->name,
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
