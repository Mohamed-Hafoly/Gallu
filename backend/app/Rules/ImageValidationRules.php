<?php

// app/Rules/ImageValidationRules.php

namespace App\Rules;

use App\Models\Image;

class ImageValidationRules
{
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
