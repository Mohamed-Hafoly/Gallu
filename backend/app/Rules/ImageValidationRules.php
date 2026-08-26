<?php

// app/Rules/ImageValidationRules.php

namespace App\Rules;

use App\Models\Image;
use Illuminate\Validation\Rule;

class ImageValidationRules
{
    /**
     * Keep in sync with frontend/src/composables/useImageValidationRules.ts,
     * which mirrors the length rules only — uniqueness is server-only.
     *
     * Unique **within the document**, not per owner: a title is how an image is
     * told apart from its siblings on the document page, so two people may not
     * both call one "Front cover" there, while the same title in a different
     * document is fine and expected.
     *
     * Validation-only, with no unique index behind it — deliberately, like
     * documents. Document::booted() cascade-restores images through the query
     * builder, and an index ignores deleted_at, so a restore could fail on a
     * title freed and reused while the document was trashed. That is also why
     * withoutTrashed() belongs here, unlike on teams and categories, whose
     * requests document the opposite trade-off.
     *
     * @param  Image|null  $ignore  The image being updated, excluded from its own check.
     * @return list<mixed>
     */
    public static function title(int $documentId, ?Image $ignore = null): array
    {
        $unique = Rule::unique('images', 'title')
            ->where('document_id', $documentId)
            ->withoutTrashed();

        if ($ignore !== null) {
            $unique->ignore($ignore);
        }

        return ['required', 'string', 'max:140', $unique];
    }

    /**
     * Stock validation.unique reads "The title has already been taken.", which
     * invites "but I have never used that title" now that the scope is the
     * document rather than the caller. Not the `custom` block in the validation
     * lang files: that is keyed by attribute, and `title` is also a document's,
     * whose uniqueness is still per owner.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['title.unique' => __('image.duplicateTitle')];
    }

    public static function image(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'image',
            'mimes:'.implode(',', array_map(
                fn (string $mime) => str($mime)->after('image/')->toString(),
                Image::ACCEPTED_MIME_TYPES,
            )),
            'max:'.intdiv(config('media-library.max_file_size'), 1024),
        ];
    }
}
