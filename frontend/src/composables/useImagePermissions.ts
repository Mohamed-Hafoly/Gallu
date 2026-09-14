import type { Image } from "@/types/image";
import { useAuthStore } from "@/stores/auth";

/**
 * Which image affordances to offer the signed-in user.
 *
 * Cosmetic only — ImagePolicy is the real enforcement, and ImageController
 * calls Gate::authorize on every write path. This exists so a button is not
 * offered where the server will answer 403, the way useDocumentPermissions does
 * for documents.
 *
 * Mirrors ImagePolicy::update: the owner may change their own image, and a team
 * admin may change any image in their team.
 */
export function useImagePermissions() {
  const authStore = useAuthStore();

  /**
   * No team comparison on the admin arm, unlike useDocumentPermissions.canEdit,
   * which keeps one defensively.
   *
   * A document carries its `team` in the payload, so that check costs nothing.
   * An image has no team of its own — it inherits its document's, which is why
   * Image::scopeVisibleTo resolves it through `document` — and ImageResource
   * carries only `document_id`. Putting the document's team on the resource
   * purely to re-derive it here would be the second source of truth the model's
   * own comment warns against.
   *
   * It is satisfied by construction instead: scopeVisibleTo means a
   * non-super-admin only ever receives images from their own team, so any image
   * reaching this dialog already passes the policy's team test.
   */
  function canEdit(image: Image) {
    const user = authStore.user;

    if (!user) return false;

    return (
      user.is_super_admin ||
      user.role === "admin" ||
      image.user_id === user.id
    );
  }

  /**
   * ImagePolicy::delete delegates straight to ::update, so this is canEdit by
   * definition rather than by coincidence. Named separately anyway: the
   * template reads better for it, and if the policy ever splits the two, this
   * is the one place that has to change.
   *
   * ::restore delegates to ::update too, but the gallery's restore button needs
   * no check — the trashed listing is already scoped to rows the caller may
   * restore.
   */
  function canDelete(image: Image) {
    return canEdit(image);
  }

  return { canEdit, canDelete };
}
