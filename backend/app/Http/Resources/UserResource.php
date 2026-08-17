<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->getFirstMediaUrl(User::AVATAR_COLLECTION),
            'avatar_thumb_url' => $this->getFirstMediaUrl(User::AVATAR_COLLECTION, 'thumb'),
            // The URLs above fall back to the default image, so they can't tell
            // us whether the user actually uploaded one — this is what decides
            // if the SPA offers a "remove" action, and what it previews once
            // removal is pending but not yet saved.
            'has_avatar' => $this->hasMedia(User::AVATAR_COLLECTION),
            'default_avatar_url' => asset(User::DEFAULT_AVATAR_PATH),
        ];
    }
}
