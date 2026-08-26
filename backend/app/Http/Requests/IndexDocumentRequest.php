<?php

namespace App\Http\Requests;

use App\Http\Controllers\DocumentController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of the documents listing, which serves two callers with opposite
 * needs — the same split IndexImageRequest describes.
 *
 * `cover` is what separates them. The gallery's card grid needs each document's
 * few newest images to draw its 2x2 cover, so it sends cover=1; the admin table
 * renders no thumbnails at all and fetches a document's images only when its row
 * is expanded, via /api/images?document_id=. Eager-loading covers for the admin
 * table would serialise four images, their media and their categories per row
 * for nothing.
 */
class IndexDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            // -1 is the footer's "All" and 0 is meaningless. Same rule as
            // IndexUserRequest and IndexImageRequest; the controller handles -1
            // the same way.
            'per_page' => ['sometimes', 'integer', 'min:-1', 'not_in:0'],
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in(DocumentController::SORTABLE)],
            'sort_order' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Which side of the soft delete to serve. Absent means live only,
            // which is what keeps deleted documents out of the gallery.
            // Authorised in the controller against DocumentPolicy::viewTrashed().
            'trashed' => ['sometimes', 'nullable', 'string', Rule::in(['with', 'only'])],
            'cover' => ['sometimes', 'boolean'],
        ];
    }
}
