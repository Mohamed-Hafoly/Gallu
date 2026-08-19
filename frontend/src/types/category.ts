export interface Category {
  id: number;
  name_en: string;
  name_ar: string;
  creator?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  deleted_at?: string | null;
}
