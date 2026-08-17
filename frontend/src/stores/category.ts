import type { Category } from "@/types/category";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

export const useCategoryStore = defineStore("category", () => {
  /**
   * Every live category, for the image tagging pickers. Served by its own
   * endpoint because the admin listing deliberately includes trashed rows.
   */
  async function fetchPickerCategories() {
    const { data } = await api.get("/api/categories/picker");
    return data.data as Category[];
  }

  /**
   * Every category including soft-deleted ones, for the admin screen. It
   * splits live from trashed on `deleted_at` and pages, sorts and filters
   * client-side, so this is a single unpaginated request.
   */
  async function fetchAllCategories() {
    const { data } = await api.get("/api/categories");
    return data.data as Category[];
  }

  /** The backend assigns the id and takes the creator from the session. */
  async function createCategory(payload: {
    name_en: string;
    name_ar: string;
  }) {
    const { data } = await api.post("/api/categories", payload);
    return data.data as Category;
  }

  /**
   * Either name may be sent on its own; the backend requires at least one.
   */
  async function updateCategory(
    id: number,
    payload: { name_en?: string; name_ar?: string },
  ) {
    const { data } = await api.patch(`/api/categories/${id}`, payload);
    return data.data as Category;
  }

  /** Soft delete — the row moves to the pending-deletion table. */
  async function deleteCategory(id: number) {
    await api.delete(`/api/categories/${id}`);
  }

  async function restoreCategory(id: number) {
    const { data } = await api.post(`/api/categories/${id}/restore`);
    return data.data as Category;
  }

  return {
    fetchPickerCategories,
    fetchAllCategories,
    createCategory,
    updateCategory,
    deleteCategory,
    restoreCategory,
  };
});
