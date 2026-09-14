import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useDocumentStore } from "@/stores/document";

vi.mock("@/plugins/axios", () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

const mockedApi = vi.mocked(api);

const document_ = {
  id: 3,
  title: "Quarterly report",
  description: null,
  images_count: 0,
  creator: "Ada Lovelace",
  team: { id: 1, name: "Design" },
  created_at: "2026-08-24T10:00:00.000000Z",
  updated_at: "2026-08-24T10:00:00.000000Z",
  deleted_at: null,
};

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("useDocumentStore", () => {
  // A document owns no upload, so unlike stores/image.ts these are plain JSON —
  // the routes are registered as PATCH/POST without multipart spoofing.
  it("posts a create as plain JSON, not FormData", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: document_ } });

    const created = await useDocumentStore().createDocument({
      title: "Quarterly report",
      team_id: 1,
    });

    expect(mockedApi.post).toHaveBeenCalledWith("/api/documents", {
      title: "Quarterly report",
      team_id: 1,
    });
    const [, body] = mockedApi.post.mock.calls[0];
    expect(body).not.toBeInstanceOf(FormData);
    expect(created).toEqual(document_);
  });

  it("patches an update rather than spoofing the method", async () => {
    mockedApi.patch.mockResolvedValue({ data: { data: document_ } });

    await useDocumentStore().updateDocument(3, { title: "Renamed", team_id: 1 });

    expect(mockedApi.patch).toHaveBeenCalledWith("/api/documents/3", {
      title: "Renamed",
      team_id: 1,
    });
  });

  // The admin table's footer is driven by meta.total, not by the row count.
  it("returns the server's total alongside a page of rows", async () => {
    mockedApi.get.mockResolvedValue({
      data: { data: [document_], meta: { total: 35 } },
    });

    const page = await useDocumentStore().fetchDocumentPage({
      page: 2,
      per_page: 10,
    });

    expect(mockedApi.get).toHaveBeenCalledWith("/api/documents", {
      params: { page: 2, per_page: 10 },
    });
    expect(page).toEqual({ items: [document_], total: 35 });
  });

  // The gallery is the endpoint's other caller: it wants every document it may
  // see, each with its card's cover images.
  it("asks for covers and opts out of paging on the gallery listing", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: [document_] } });

    await useDocumentStore().fetchDocuments();

    expect(mockedApi.get).toHaveBeenCalledWith("/api/documents", {
      params: { per_page: -1, cover: 1 },
    });
  });

  // The route is POST /documents/{document}/restore, like the image, category
  // and team ones — it used to be a bare POST, which collided with store.
  it("restores through the restore route", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: document_ } });

    await useDocumentStore().restoreDocument(3);

    expect(mockedApi.post).toHaveBeenCalledWith("/api/documents/3/restore");
  });
});
