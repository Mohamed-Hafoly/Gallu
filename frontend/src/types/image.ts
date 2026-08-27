import type { Category } from "@/types/category";

export interface Image {
  id: number;
  title: string;
  description: string | null;
  url: string;
  thumb_url: string;
  categories: Category[];
  /** Every image belongs to exactly one document; images.document_id is NOT NULL. */
  document_id: number;
  /**
   * The owner's id, which is what decides whether the caller may edit or delete
   * this image — `creator` is a display name, so two users sharing one would be
   * indistinguishable. See useImagePermissions.
   */
  user_id: number;
  // Required, not optional: images.user_id is NOT NULL, and ImageResource
  // serves `creator` unconditionally rather than behind whenLoaded().
  creator: string;
  created_at: string;
  updated_at: string;
  /**
   * Set only on a soft-deleted image, which is what splits the admin screen's
   * live and pending-deletion tables. Optional rather than nullable-required:
   * the gallery's listing never contains a trashed row, so it is `null` there
   * and there is no point making every caller acknowledge it.
   */
  deleted_at?: string | null;
}
