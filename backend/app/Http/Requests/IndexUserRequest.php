<?php

namespace App\Http\Requests;

use App\Http\Controllers\UserController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query string of the admin users table, which pages, sorts and searches
 * server-side (VDataTableServer) rather than loading every row like the
 * categories screen does.
 */
class IndexUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            // -1 is the footer's "All" and 0 is meaningless. No upper bound:
            // once "All" is on the table there is nothing left for a cap to
            // protect. Laravel's limit() ignores a negative value, so
            // paginate(-1) emits no LIMIT clause and returns every row.
            'per_page' => ['sometimes', 'integer', 'min:-1', 'not_in:0'],
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in(UserController::SORTABLE)],
            'sort_order' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Which side of the soft delete to serve, as on documents and
            // images. Absent means live only, which is what keeps binned users
            // out of the main table. Authorised in the controller against
            // UserPolicy::viewTrashed().
            'trashed' => ['sometimes', 'nullable', 'string', Rule::in(['with', 'only'])],
        ];
    }
}
