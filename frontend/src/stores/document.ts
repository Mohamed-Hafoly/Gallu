import type { Document } from "@/types/document";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

interface CreateDocumentPayload {
  title: string;
  description?: string;
  /** Required: every document belongs to exactly one team. */
  team_id: number;
}

interface UpdateDocumentPayload {
  title: string;
  description?: string;
  /** Required, as on create: the edit dialog offers the same team picker. */
  team_id: number;
}

/** What an admin table's `@update:options` maps onto. */
export interface DocumentListParams {
  page: number;
  per_page: number;
  sort_by?: string;
  sort_order?: "asc" | "desc";
  search?: string;
  /** Absent for live rows; "only" for the pending-deletion table. */
  trashed?: "with" | "only";
  /**
   * Asks for each row's four newest images, for the card grid's 2x2 cover. The
   * admin table omits it and gets a count alone.
   *
   * 1 rather than true: axios serialises a boolean as the string "true", which
   * Laravel's `boolean` rule rejects — it takes 1, 0, "1" and "0" only. Typed
   * as the literal so the wrong value cannot be sent, since a 422 here is a
   * blank grid rather than a visible error.
   */
  cover?: 1;
}

export interface DocumentPage {
  items: Document[];
  total: number;
  /**
   * Where the listing stops. The infinite-scrolled grid needs this rather than
   * inferring the end from a running item count, which would keep firing one
   * doomed request past the last page whenever the total is an exact multiple
   * of the page size.
   */
  lastPage: number;
}

/**
 * Plain JSON throughout, unlike stores/image.ts: a document owns no upload, so
 * there is no FormData and no POST spoofing PATCH.
 *
 * A document's image membership is still set from the image side, when an image
 * is uploaded into it — there is no endpoint here for attaching one.
 */
export const useDocumentStore = defineStore("document", () => {
  /**
   * Every document the caller may see, with each card's 2x2 cover grid.
   *
   * `cover` is what asks for those images; `per_page: -1` opts out of paging,
   * which the endpoint otherwise applies for the admin table. Both are explicit
   * because the same endpoint serves both callers.
   */
  async function fetchDocuments() {
    const { data } = await api.get("/api/documents", {
      params: { per_page: -1, cover: 1 },
    });
    return data.data as Document[];
  }

  /**
   * One page of a server-paged listing — the admin table and the documents card
   * grid both. Unlike fetchDocuments() this pages, sorts and searches
   * server-side, so the total has to come back alongside the rows for the
   * table's footer and the chips' counts — same shape as fetchImagePage in
   * stores/image.ts.
   *
   * `cover` is the caller's to send: the table draws no thumbnails and asks the
   * image store for a document's images only when its row is expanded, while
   * the card grid needs the four covers on every row.
   */
  async function fetchDocumentPage(
    params: DocumentListParams,
  ): Promise<DocumentPage> {
    const { data } = await api.get("/api/documents", { params });

    return {
      items: data.data as Document[],
      total: data.meta.total as number,
      lastPage: data.meta.last_page as number,
    };
  }

  async function createDocument(payload: CreateDocumentPayload) {
    const { data } = await api.post("/api/documents", payload);
    return data.data as Document;
  }

  async function updateDocument(id: number, payload: UpdateDocumentPayload) {
    const { data } = await api.patch(`/api/documents/${id}`, payload);
    return data.data as Document;
  }

  /** Soft delete — the row moves to the admin screen's pending-deletion table. */
  async function deleteDocument(id: number) {
    await api.delete(`/api/documents/${id}`);
  }

  /** One document, for the header on /documents/[id]. 403 if it is not yours. */
  async function fetchDocument(id: number) {
    const { data } = await api.get(`/api/documents/${id}`);
    return data.data as Document;
  }

  async function restoreDocument(id: number) {
    const { data } = await api.post(`/api/documents/${id}/restore`);
    return data.data as Document;
  }

  return {
    fetchDocuments,
    fetchDocumentPage,
    fetchDocument,
    createDocument,
    updateDocument,
    deleteDocument,
    restoreDocument,
  };
});
