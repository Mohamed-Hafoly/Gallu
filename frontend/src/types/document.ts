import type { Image } from "@/types/image";

export interface Document {
  id: number;
  title: string;
  description: string | null;
  /**
   * At most the four most recent images, newest first — enough for the card's
   * 2x2 cover, not the document's whole contents.
   *
   * Optional because only the gallery asks for it, by sending `cover: 1`. The
   * admin table omits the flag and gets no key at all, then fetches a
   * document's images from /api/images?document_id= when its row is expanded.
   * Use `images_count` for the total either way.
   */
  images?: Image[];
  /** The true number of images, which `images` is truncated away from. */
  images_count: number;
  // Required, not optional: documents.user_id is NOT NULL, and DocumentResource
  // serves `creator` unconditionally rather than behind whenLoaded().
  creator: string;
  /**
   * Nullable the way User["team"] is: a document created before the team became
   * required belongs to none.
   *
   * The team is served through withTrashed() on the listings, so a document
   * that went down with its team still names it. `deleted_at` is what tells the
   * two apart, and the only signal the SPA has that an individual restore would
   * be refused: a live document can no longer have a trashed team, so a non-null
   * value here means this row waits for its team to come back.
   */
  team: { id: number; name: string; deleted_at: string | null } | null;
  created_at: string;
  updated_at: string;
  /** Null for a live document; set once it is soft-deleted. */
  deleted_at: string | null;
}
