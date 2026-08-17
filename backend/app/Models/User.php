<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithMedia, Notifiable;

    /**
     * The media collection holding the user's profile image.
     */
    public const AVATAR_COLLECTION = 'avatar';

    /**
     * Served straight out of `public/`, not the media disk, because it is a
     * fallback rather than an upload — see registerMediaCollections().
     */
    public const DEFAULT_AVATAR_PATH = 'images/default-avatar.jpg';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /**
     * A user without an uploaded avatar falls back to the shipped default
     * image, so getFirstMediaUrl() never returns an empty string and the SPA
     * needs no placeholder of its own. Registered for the conversion too,
     * otherwise the thumb URL would come back empty.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(Image::ACCEPTED_MIME_TYPES)
            ->useFallbackUrl(asset(self::DEFAULT_AVATAR_PATH))
            ->useFallbackUrl(asset(self::DEFAULT_AVATAR_PATH), 'thumb');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->nonQueued();
    }

    /**
     * Store an uploaded file as this user's avatar.
     *
     * The collection is `singleFile()`, so this replaces any existing avatar
     * without an explicit clear. Shared by registration and profile updates.
     */
    public function setAvatarFromFile(UploadedFile $file): void
    {
        $this->addMedia($file)
            ->usingName($this->name)
            ->usingFileName(Str::uuid().'.'.$file->getClientOriginalExtension())
            ->toMediaCollection(self::AVATAR_COLLECTION);
    }
}
