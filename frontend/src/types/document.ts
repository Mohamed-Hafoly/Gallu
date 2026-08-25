import type { Image } from "@/types/image";

export interface Document {
  id: number;
  title: string;
  description: string | null;
  /**
   * At most the four most recent images, newest first — enough for the card's
   * 2x2 cover, not the document's whole contents. Use `images_count` for the
   * total, and /api/images?document_id= for the full set.
   *
   * Empty is a valid document, so this is `[]`, never absent.
   */
  images: Image[];
  /** The true number of images, which `images` is truncated away from. */
  images_count: number;
  // Required, not optional: documents.user_id is NOT NULL, and DocumentResource
  // serves `creator` unconditionally rather than behind whenLoaded().
  creator: string;
  created_at: string;
}
