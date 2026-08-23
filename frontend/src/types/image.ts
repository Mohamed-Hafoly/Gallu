import type { Category } from "@/types/category";

export interface Image {
  id: number;
  title: string;
  description: string | null;
  url: string;
  thumb_url: string;
  categories: Category[];
  // Required, not optional: images.user_id is NOT NULL, and ImageResource
  // serves `creator` unconditionally rather than behind whenLoaded().
  creator: string;
  created_at: string;
}
