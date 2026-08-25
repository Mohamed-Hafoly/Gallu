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
}

export interface ImagePage {
  items: Image[];
  total: number;
}

interface UpdateImagePayload {
  title: string;
  description?: string;
  selected_category_ids: number[];
  image?: File;
}

export const useImageStore = defineStore("image", () => {
  /**
   * Every image the caller may see, or just one document's when an id is given.
   * The filter narrows server-side and is applied after the team scope, so an
   * id from another team yields an empty list rather than a leak.
   */
  async function fetchImages(documentId?: number) {
    const { data } = await api.get("/api/images", {
      params: documentId === undefined ? {} : { document_id: documentId },
    });
    return data.data as Image[];
  }

  /**
   * One page of an admin table. Unlike fetchImages() this pages, sorts and
   * searches server-side, so the total has to come back alongside the rows for
   * the table's footer - same shape as fetchUsers in stores/user.ts.
   *
   * `trashed` is what makes the admin screen's two tables two listings rather
   * than one filtered array: the live table sends nothing, the pending-deletion
   * table sends "only". The gallery must never send it at all, or deleted
   * images reappear in /gallery and /documents/{id}; the backend 403s a plain
   * member who tries.
   */
  async function fetchImagePage(params: ImageListParams): Promise<ImagePage> {
    const { data } = await api.get("/api/images", { params });

    return { items: data.data as Image[], total: data.meta.total as number };
  }

  async function createImage(payload: CreateImagePayload) {
    const formData = new FormData();
    formData.append("title", payload.title);
    formData.append("document_id", String(payload.document_id));
    if (payload.description) formData.append("description", payload.description);
    for (const id of payload.selected_category_ids) formData.append("selected_category_ids[]", String(id));
    formData.append("image", payload.image);

    const { data } = await api.post("/api/images", formData);
    console.log("createImage response", data.data);
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
    console.log("updateImage response", data.data);
    return data.data as Image;
  }

  /** Soft delete — the row moves to the admin screen's pending-deletion table. */
  async function deleteImage(id: number) {
    const response = await api.delete(`/api/images/${id}`);
    console.log("deleteImage response", response.status);
  }

  async function restoreImage(id: number) {
    const { data } = await api.post(`/api/images/${id}/restore`);
    return data.data as Image;
  }

  return {
    fetchImages,
    fetchImagePage,
    createImage,
    updateImage,
    deleteImage,
    restoreImage,
  };
});
