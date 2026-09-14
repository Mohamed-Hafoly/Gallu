import type { Document } from "@/types/document";
import { computed } from "vue";
import { useAuthStore } from "@/stores/auth";

/**
 * Which document affordances to offer the signed-in user.
 *
 * Cosmetic only — DocumentPolicy is the real enforcement, the way
 * useNavLinks.ts and the router guard are cosmetic next to the backend
 * policies. This exists so the buttons match what the server will actually
 * allow, not to keep anyone out.
 *
 * Mirrors DocumentPolicy: `create` is `role() === Admin`, `update` adds
 * `sharesTeamWith()`, and AppServiceProvider's `Gate::before` waves a
 * super-admin through both.
 */
export function useDocumentPermissions() {
  const authStore = useAuthStore();

  /**
   * The `team != null` half is not in the policy but is needed here: a
   * team-less admin passes DocumentPolicy::create and then fails
   * DocumentController::authorizeTeam(), because there is no team to file the
   * document under. Better no button than one that always 403s.
   */
  const canCreate = computed(() => {
    const user = authStore.user;

    if (!user) return false;

    return user.is_super_admin || (user.role === "admin" && user.team !== null);
  });

  /**
   * Document::scopeVisibleTo already narrows the listing to the caller's own
   * team, so for an admin this is true of every card they can see. The team
   * comparison is kept anyway, as the "belt to that braces" authorisation in
   * ImageController::store is: it costs nothing and does not quietly start
   * lying if the listing's scope ever changes.
   *
   * A document whose team reads null is not editable here by anyone,
   * super-admin included. This page never offers the team picker, so there
   * would be no id to submit and team_id is required - the edit would 422 with
   * an error the form has no field to fix. Those live under /admin/documents,
   * which does offer the picker.
   *
   * Null means *trashed*, never absent: documents.team_id is NOT NULL, and
   * force-deleting a team takes its documents with it (cascadeOnDelete). What
   * reaches here as null is a soft-deleted team on an endpoint that loaded the
   * relation without withTrashed(). Such a document is waiting on its team's
   * restore anyway, so refusing the edit is the right answer either way.
   */
  function canEdit(document: Document) {
    const user = authStore.user;

    if (!user || !document.team) return false;

    return (
      user.is_super_admin ||
      (user.role === "admin" && document.team.id === user.team?.id)
    );
  }

  /**
   * DocumentPolicy::delete delegates straight to ::update, so this is canEdit
   * by definition rather than by coincidence. Named separately anyway: the
   * template reads better for it, and if the policy ever splits the two, this
   * is the one place that has to change.
   */
  function canDelete(document: Document) {
    return canEdit(document);
  }

  /**
   * A super-admin belongs to no team, so there is nothing to default the
   * create form to and they have to pick one; everyone else files under their
   * own team and never sees the field.
   */
  const lockedTeamId = computed(() =>
    authStore.user?.is_super_admin ? undefined : authStore.user?.team?.id,
  );

  return { canCreate, canEdit, canDelete, lockedTeamId };
}
