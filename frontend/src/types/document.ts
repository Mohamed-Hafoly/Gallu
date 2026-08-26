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
   */
  team: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
  /** Null for a live document; set once it is soft-deleted. */
  deleted_at: string | null;
}
