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
     * @param  int|null  $teamId  The destination team; null falls back to per-owner.
     * @param  Document|null  $ignore  The document being updated, excluded from its own check.
     * @return list<mixed>
     */
    public static function title(?int $teamId, int $userId, ?Document $ignore = null): array
    {
        $unique = Rule::unique('documents', 'title')->withoutTrashed();

        if ($teamId === null) {
            // Not reachable over HTTP: team_id is required on both requests and
            // must name a live team, so a document created or edited through
            // the API always has one. Written anyway so the rule is correct on
            // its own terms rather than leaning on a sibling rule to hold, and
            // because documents.team_id is nullable - nullOnDelete() empties it
            // when a team is force-deleted, and factories may leave it unset.
            //
            // whereNull(), not where('team_id', null): the presence verifier
            // emits `team_id = ?` for a null value, which matches nothing.
            $unique->whereNull('team_id')->where('user_id', $userId);
        } else {
            $unique->where('team_id', $teamId);
        }

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
