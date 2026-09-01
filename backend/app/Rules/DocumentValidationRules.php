<?php

namespace App\Rules;

use App\Models\Document;
use Illuminate\Validation\Rule;

class DocumentValidationRules
{
    /**
     * Keep in sync with frontend/src/composables/useDocumentValidationRules.ts,
     * which mirrors the length rules only — uniqueness is server-only.
     *
     * Unique **within the team**, not per owner: a title is how a document is
     * told apart from its siblings on the team's documents list, so two people
     * on one team may not both file a "Q3 Report", while the same title in
     * another team is fine and expected.
     *
     * Validation-only, with no unique index behind it — deliberately, like
     * images. Document::booted() cascade-restores through the query builder,
     * and an index ignores deleted_at, so a restore could fail on a title freed
     * and reused while the document was trashed. That is also why
     * withoutTrashed() belongs here, unlike on teams and categories, whose
     * requests document the opposite trade-off.
     *
     * Not nullable, and neither are the callers' arguments. rules() is built
     * before validation runs, so a request that omits team_id still reaches
     * here - but it arrives as 0, which Request::integer() returns for an
     * absent key. No team has id 0 (auto-increment starts at 1), so the scope
     * below matches nothing and cannot report a false duplicate, and the
     * sibling `required` rule is what answers with a 422. A null would only be
     * a second way of spelling the same thing, and would need a branch to
     * unpick.
     *
     * @param  int  $teamId  The destination team; 0 for a request that named none.
     * @param  Document|null  $ignore  The document being updated, excluded from its own check.
     * @return list<mixed>
     */
    public static function title(int $teamId, ?Document $ignore = null): array
    {
        $unique = Rule::unique('documents', 'title')
            ->withoutTrashed()
            ->where('team_id', $teamId);

        if ($ignore !== null) {
            $unique->ignore($ignore);
        }

        return ['required', 'string', 'max:140', $unique];
    }

    /**
     * Stock validation.unique reads "The title has already been taken.", which
     * invites "but I have never used that title" now that the scope is the team
     * rather than the caller. Not the `custom` block in the validation lang
     * files: that is keyed by attribute, and `title` is also an image's, whose
     * scope is its document.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['title.unique' => __('document.duplicateTitle')];
    }
}
