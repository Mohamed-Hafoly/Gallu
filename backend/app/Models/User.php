<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
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
     * Default ambient team context for spatie, used until team middleware
     * sets one per request.
     *
     * `model_has_roles.team_id` is NOT NULL and part of the primary key, so
     * every assignment needs a concrete id. Real teams auto-increment from 1,
     * which leaves 0 free and unambiguous. Nothing to do with super-admin —
     * that is the `is_super_admin` column.
     */
    public const GLOBAL_TEAM_ID = 0;

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
            'is_super_admin' => 'boolean',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /**
     * The role this user is presented as. Binary until teams land, at which
     * point a team admin resolves to RoleName::Admin here.
     */
    public function role(): RoleName
    {
        return $this->is_super_admin ? RoleName::SuperAdmin : RoleName::Member;
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

    /**
     * Apply the avatar half of a multipart form submission, if it asked for one.
     *
     * Clearing the collection is enough to "remove" an avatar — the collection
     * falls back to the default image. Shared by the Fortify profile action and
     * the admin user endpoint so both agree on what `remove_avatar` means; the
     * flag arrives over multipart as the string "1", hence filter_var.
     *
     * @param  array<string, mixed>  $input
     */
    public function applyAvatarInput(array $input): void
    {
        if (filter_var($input['remove_avatar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->clearMediaCollection(self::AVATAR_COLLECTION);

            return;
        }

        if (! isset($input['avatar'])) {
            return;
        }

        $this->setAvatarFromFile($input['avatar']);
    }
}
