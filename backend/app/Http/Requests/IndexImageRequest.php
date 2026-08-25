<?php

namespace App\Http\Requests;

use App\Http\Controllers\ImageController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of the images listing, which serves two callers: the gallery's
 * plain list and the admin screen's two server-paginated tables.
 *
 * `document_id` is only a filter — it narrows the list, it does not widen it.
 * The controller applies it after scopeVisibleTo(), so passing another team's
 * document id yields an empty list rather than a leak, and validation
 * deliberately does not check the id against the caller's team: doing so would
 * turn "no such images" into "that document exists but is not yours".
 */
class IndexImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['sometimes', 'integer'],
            // Which side of the soft delete to serve. Absent means live only,
            // which is what keeps deleted images out of the gallery — an enum
            // rather than a boolean because the admin screen's two tables need
            // three states between them, and the trashed table wants *only*
            // deleted rows, not both kinds mixed. Authorised in the controller
            // against ImagePolicy::viewTrashed().
            'trashed' => ['sometimes', 'nullable', 'string', Rule::in(['with', 'only'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            // -1 is the footer's "All" and 0 is meaningless. Same rule as
            // IndexUserRequest, and the controller handles -1 the same way.
            'per_page' => ['sometimes', 'integer', 'min:-1', 'not_in:0'],
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in(ImageController::SORTABLE)],
            'sort_order' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
