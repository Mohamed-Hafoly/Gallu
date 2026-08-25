import type { Document } from "@/types/document";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

interface CreateDocumentPayload {
  title: string;
  description?: string;
  /** Required: every docuemnt belongs to exactly one team. */
  team_id: number;
}

interface UpdateDocumentPayload {
  title: string;
  description?: string;
}

/**
 * Plain JSON throughout, unlike stores/image.ts: a document owns no upload, so
 * there is no FormData and no POST spoofing PATCH.
 *
 * Read-only for now: creating and editing documents is a later change, and a
 * document's image membership is set from the image side anyway, when an image
 * is uploaded into it.
 */
export const useDocumentStore = defineStore("document", () => {
  async function fetchDocuments() {
    const { data } = await api.get("/api/documents");
    return data.data as Document[];
  }

  async function createDocument(payload: CreateDocumentPayload) {
    const formData = new FormData();
    formData.append("title", payload.title);
    formData.append("team_id", String(payload.team_id));
    if (payload.description)
      formData.append("description", payload.description);

    const { data } = await api.post("/api/documents", formData);
    console.log("createDocument response", data.data);
    return data.data as Document;
  }
  async function updateDocument(id: number, payload: UpdateDocumentPayload) {
    const formData = new FormData();
    formData.append("_method", "PATCH");
    formData.append("title", payload.title);
    if (payload.description)
      formData.append("description", payload.description);

    const { data } = await api.post(`/api/documents/${id}`, formData);
    console.log("updateDocument response", data.data);
    return data.data as Document;
  }

  /** Soft delete — the row moves to the admin screen's pending-deletion table. */
  async function deleteDocument(id: number) {
    const response = await api.delete(`/api/documents/${id}`);
    console.log("deleteDocument response", response.status);
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
    fetchDocument,
    createDocument,
    updateDocument,
    deleteDocument,
    restoreDocument,
  };
});
