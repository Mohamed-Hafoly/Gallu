import type { Category } from "@/types/category";

export interface Image {
  id: number;
  title: string;
  description: string | null;
  url: string;
  thumb_url: string;
  categories: Category[];
  created_at: string;
}
