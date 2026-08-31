import type { Image } from "@/types/image";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

interface CreateImagePayload {
  title: string;
  description?: string;
  /** Required: every image belongs to exactly one document. */
  document_id: number;
  selected_category_ids: number[];
  image: File;
}

/** What an admin table's `@update:options` maps onto. */
export interface ImageListParams {
  page: number;
  per_page: number;
  sort_by?: string;
  sort_order?: "asc" | "desc";
  search?: string;
  /** Absent for live rows; "only" for the pending-deletion table. */
  trashed?: "with" | "only";
  /** Narrows to one document — what the document page's paged feed sends. */
  document_id?: number;
  /**
   * Narrows to the caller's own images, for the document page's "Yours" chip.
   * "All" is the *absence* of this param, not a value of it — the backend
   * accepts no other string and 422s anything else.
   */
  owner?: "mine";
}

export interface ImagePage {
  items: Image[];
  total: number;
  /**
   * Where the listing stops. The infinite-scrolled feed needs this rather than
   * inferring the end from a running item count, which would keep firing one
   * doomed request past the last page whenever the total is an exact multiple
   * of the page size.
   */
  lastPage: number;
}

interface UpdateImagePayload {
  title: string;
  description?: string;
  selected_category_ids: number[];
  image?: File;
}

export const useImageStore = defineStore("image", () => {
  /**
   * One page of the document page's infinite-scrolled feed. Pages, sorts and
   * searches server-side, so the total has to come back alongside the rows -
   * same shape as fetchUsers in stores/user.ts. It sends
   * document_id/page/per_page, plus `owner` only when narrowing.
   *
   * `trashed` is what makes the "Recently deleted" chip its own listing rather
   * than a filter over one array: the trash is a separate bucket, so All and
   * Yours must keep sending nothing, or deleted rows leak into them.
   *
   * Not a 403 for a member: the backend scopes a trashed listing to the
   * caller's own images rather than refusing it, so everyone has a trash and it
   * is simply smaller for some.
   */
  async function fetchImagePage(params: ImageListParams): Promise<ImagePage> {
    const { data } = await api.get("/api/images", { params });

    return {
      items: data.data as Image[],
      total: data.meta.total as number,
      lastPage: data.meta.last_page as number,
    };
  }

  async function createImage(payload: CreateImagePayload) {
    const formData = new FormData();
    formData.append("title", payload.title);
    formData.append("document_id", String(payload.document_id));
    if (payload.description) formData.append("description", payload.description);
    for (const id of payload.selected_category_ids) formData.append("selected_category_ids[]", String(id));
    formData.append("image", payload.image);

    const { data } = await api.post("/api/images", formData);
    return data.data as Image;
  }

  async function updateImage(id: number, payload: UpdateImagePayload) {
    const formData = new FormData();
    formData.append("_method", "PATCH");
    formData.append("title", payload.title);
    if (payload.description) formData.append("description", payload.description);
    for (const catId of payload.selected_category_ids) formData.append("selected_category_ids[]", String(catId));
    if (payload.image) formData.append("image", payload.image);

    const { data } = await api.post(`/api/images/${id}`, formData);
    return data.data as Image;
  }

  /** Soft delete — the row moves to the admin screen's pending-deletion table. */
  async function deleteImage(id: number) {
    await api.delete(`/api/images/${id}`);
  }

  async function restoreImage(id: number) {
    const { data } = await api.post(`/api/images/${id}/restore`);
    return data.data as Image;
  }

  return {
    fetchImagePage,
    createImage,
    updateImage,
    deleteImage,
    restoreImage,
  };
});
