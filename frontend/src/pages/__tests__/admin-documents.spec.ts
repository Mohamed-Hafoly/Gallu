import type { DocumentListParams } from "@/stores/document";
import type { Document } from "@/types/document";
import type { Image } from "@/types/image";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import AdminDocuments from "@/pages/admin/documents.vue";
import i18n from "@/plugins/i18n";

// The stores import the axios client, and the router's HMR hook throws under
// vitest — stubbed the same way the other page and dialog specs do it. The
// router arrives transitively, through the create dialog's auth store.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const { fetchDocumentPage, fetchImages, deleteDocument, restoreDocument } =
  vi.hoisted(() => ({
    fetchDocumentPage: vi.fn(),
    fetchImages: vi.fn(),
    deleteDocument: vi.fn(),
    restoreDocument: vi.fn(),
  }));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({
    fetchDocumentPage,
    deleteDocument,
    restoreDocument,
  }),
}));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ fetchImages }),
}));

/**
 * The listing is server-side, so these assert the *params* the page sends
 * rather than any client-side filtering — and, for the expanded rows, that a
 * document's images are a second request made on demand rather than data
 * riding along with the row.
 */

/** The search box debounces for 300ms before it even starts fetching. */
const SEARCH_DELAY = 350;

function settle(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function document_(id: number, overrides: Partial<Document> = {}): Document {
  return {
    id,
    title: `Document ${id}`,
    description: null,
    images_count: 0,
    creator: "Ada Lovelace",
    team: { id: 1, name: "Design" },
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
    ...overrides,
  };
}

function image(id: number, creator: string): Image {
  return {
    id,
    title: `Image ${id}`,
    description: null,
    url: `/i/${id}.jpg`,
    thumb_url: `/i/${id}-thumb.jpg`,
    categories: [],
    document_id: 1,
    creator,
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
  };
}

async function mountPage() {
  const wrapper = mountWithPlugins(AdminDocuments);
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>;

/** The live table; the trashed one is the second VDataTableServer. */
function table(wrapper: Wrapper) {
  return wrapper.findAllComponents({ name: "VDataTableServer" })[0];
}

function trashTable(wrapper: Wrapper) {
  return wrapper.findAllComponents({ name: "VDataTableServer" })[1];
}

function bodyRows(wrapper: Wrapper) {
  return table(wrapper).findAll("tbody tr");
}

/**
 * Found by icon, not by index: the first action is a :to link, which Vuetify
 * renders as an <a> rather than a <button>, so positional lookups silently
 * point at the wrong control.
 */
function rowButton(row: ReturnType<typeof bodyRows>[number], icon: string) {
  // querySelector rather than the wrapper's find(), which eslint reads as
  // Array.prototype.find being handed a function reference.
  return row
    .findAll("button")
    .find((button) => button.element.querySelector(icon) !== null)!;
}

/**
 * The page drives two listings, so a bare "last call" is ambiguous — the live
 * and trashed tables are told apart by the `trashed` param they send.
 */
function callsFor(trashed: "only" | undefined): DocumentListParams[] {
  return fetchDocumentPage.mock.calls
    .map((call) => call[0] as DocumentListParams)
    .filter((params) => params.trashed === trashed);
}

function lastParams(): DocumentListParams {
  return callsFor(undefined).at(-1)!;
}

function lastTrashParams(): DocumentListParams {
  return callsFor("only").at(-1)!;
}

/** How many times the live table has been fetched. */
function liveFetches() {
  return callsFor(undefined).length;
}

/**
 * Clicks a row's expand toggle. Driving the model directly is not equivalent:
 * v-data-table declares the expansion model as `readonly string[]` but writes
 * the raw item value — a number — into it, so an emitted `["1"]` matches no row.
 *
 * The toggle is the last button in the row, because the expand column is
 * appended after `actions`.
 */
async function expand(wrapper: Wrapper, rowIndex: number) {
  const buttons = bodyRows(wrapper)[rowIndex].findAll("button");
  await buttons.at(-1)!.trigger("click");
  await flushPromises();
}

beforeEach(() => {
  i18n.global.locale.value = "en";
  fetchDocumentPage.mockReset();
  fetchImages.mockReset();
  deleteDocument.mockReset();
  restoreDocument.mockReset();
  deleteDocument.mockResolvedValue(undefined);
  restoreDocument.mockResolvedValue(undefined);

  fetchDocumentPage.mockResolvedValue({
    items: Array.from({ length: 10 }, (_, index) => document_(index + 1)),
    total: 35,
  });
  fetchImages.mockResolvedValue([]);
});

describe("admin documents listing", () => {
  it("fetches the first page on mount and reports the server's total", async () => {
    const wrapper = await mountPage();

    expect(liveFetches()).toBe(1);
    expect(lastParams()).toMatchObject({ page: 1, per_page: 10 });
    expect(bodyRows(wrapper)).toHaveLength(10);
    expect(wrapper.find(".v-data-table-footer__info").text()).toContain("35");
  });

  it("sends the sort the table emits, rather than reordering locally", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 1,
      itemsPerPage: 10,
      sortBy: [{ key: "id", order: "desc" }],
    });
    await flushPromises();

    expect(lastParams()).toMatchObject({ sort_by: "id", sort_order: "desc" });
  });

  it("debounces the search into one request and sends the term", async () => {
    const wrapper = await mountPage();

    const field = wrapper.findComponent({ name: "VTextField" });
    await field.setValue("Des");
    await field.setValue("Design");
    await settle(SEARCH_DELAY);
    await flushPromises();

    expect(liveFetches()).toBe(2);
    expect(lastParams()).toMatchObject({ search: "Design" });
  });

  // Searching from a later page would otherwise land on an empty page of a much
  // shorter result set.
  it("returns to page one when the search changes", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 3,
      itemsPerPage: 10,
      sortBy: [],
    });
    await flushPromises();
    expect(lastParams().page).toBe(3);

    await wrapper.findComponent({ name: "VTextField" }).setValue("Design");
    await settle(SEARCH_DELAY);
    await flushPromises();

    expect(lastParams().page).toBe(1);
  });
});

describe("expanded image sub-rows", () => {
  it("does not carry images in the listing itself", async () => {
    await mountPage();

    // No `cover` flag: the listing is a plain table, and DocumentResource
    // returns no images key for it at all.
    expect(lastParams()).not.toHaveProperty("cover");
    expect(fetchImages).not.toHaveBeenCalled();
  });

  it("fetches a document's images on expand, filtered to that document", async () => {
    fetchImages.mockResolvedValue([image(1, "Ada Lovelace"), image(2, "Alan T")]);
    const wrapper = await mountPage();

    await expand(wrapper, 0);

    expect(fetchImages).toHaveBeenCalledWith(1);

    const nested = wrapper.findComponent({ name: "VDataTable" });
    expect(nested.exists()).toBe(true);
    expect(nested.props("items")).toHaveLength(2);
  });

  // The point of the nested table: a document's creator and an image's creator
  // are separate columns, and an image uploaded by a teammate must show theirs.
  it("lists each image with its own creator, not the document's", async () => {
    fetchImages.mockResolvedValue([
      image(1, "Ada Lovelace"),
      image(2, "Grace Hopper"),
    ]);
    const wrapper = await mountPage();

    await expand(wrapper, 0);

    const nested = wrapper.findComponent({ name: "VDataTable" });
    expect(nested.text()).toContain("Grace Hopper");
  });

  // Keyed by document id and kept after collapse, so re-expanding is free.
  it("does not refetch a row's images when it is expanded again", async () => {
    fetchImages.mockResolvedValue([image(1, "Ada Lovelace")]);
    const wrapper = await mountPage();

    await expand(wrapper, 0);
    await expand(wrapper, 0);
    await expand(wrapper, 0);

    expect(fetchImages).toHaveBeenCalledTimes(1);
  });

  it("shows a fallback line instead of an empty table when a document has none", async () => {
    fetchImages.mockResolvedValue([]);
    const wrapper = await mountPage();

    await expand(wrapper, 0);

    expect(wrapper.findComponent({ name: "VDataTable" }).exists()).toBe(false);
    expect(wrapper.text()).toContain(
      i18n.global.t("admin.documents.noImages") as string,
    );
  });
});

describe("create dialog", () => {
  it("opens the dialog from the add button", async () => {
    const wrapper = await mountPage();

    const dialog = wrapper.findComponent({ name: "DocumentCreateDialog" });
    expect(dialog.props("modelValue")).toBe(false);

    const add = wrapper
      .findAll("button")
      .find((button) =>
        button.text().includes(i18n.global.t("admin.documents.add") as string),
      )!;
    await add.trigger("click");

    expect(dialog.props("modelValue")).toBe(true);
  });

  it("reloads both listings once the dialog reports a document was created", async () => {
    const wrapper = await mountPage();
    expect(liveFetches()).toBe(1);

    wrapper.findComponent({ name: "DocumentCreateDialog" }).vm.$emit("created");
    await flushPromises();

    expect(liveFetches()).toBe(2);
    expect(callsFor("only")).toHaveLength(2);
  });
});

describe("trashed table", () => {
  it("asks the two tables for opposite sides of the soft delete", async () => {
    await mountPage();

    expect(lastParams().trashed).toBeUndefined();
    expect(lastTrashParams().trashed).toBe("only");
  });

  it("keeps the two tables' paging independent", async () => {
    const wrapper = await mountPage();

    trashTable(wrapper).vm.$emit("update:options", {
      page: 4,
      itemsPerPage: 10,
      sortBy: [],
    });
    await flushPromises();

    expect(lastTrashParams().page).toBe(4);
    // The live table was not refetched, so its page is untouched.
    expect(lastParams().page).toBe(1);
  });

  it("restores a row and reloads both tables", async () => {
    const wrapper = await mountPage();
    const before = liveFetches();

    await rowButton(
      trashTable(wrapper).findAll("tbody tr")[0],
      ".mdi-restore",
    ).trigger("click");
    await flushPromises();

    expect(restoreDocument).toHaveBeenCalledWith(1);
    // Both, because a restore moves the row from one table to the other.
    expect(liveFetches()).toBe(before + 1);
  });
});

describe("delete", () => {
  async function confirmDelete(wrapper: Wrapper) {
    const dialog = wrapper.findAllComponents({ name: "ConfirmDialog" })[0];
    dialog.vm.$emit("confirm");
    await flushPromises();
  }

  it("deletes the row behind the confirm dialog and reloads both tables", async () => {
    const wrapper = await mountPage();
    const before = liveFetches();

    await rowButton(bodyRows(wrapper)[0], ".mdi-delete").trigger("click");
    await confirmDelete(wrapper);

    expect(deleteDocument).toHaveBeenCalledWith(1);
    expect(liveFetches()).toBe(before + 1);
  });

  it("does not delete until the dialog is confirmed", async () => {
    const wrapper = await mountPage();

    await rowButton(bodyRows(wrapper)[0], ".mdi-delete").trigger("click");
    await flushPromises();

    expect(deleteDocument).not.toHaveBeenCalled();
  });
});

describe("bulk actions", () => {
  /** There is no batch endpoint, so each id is its own request. */
  it("issues one delete per selected id and reloads once", async () => {
    const wrapper = await mountPage();
    const before = liveFetches();

    table(wrapper).vm.$emit("update:modelValue", [1, 2, 3]);
    await flushPromises();

    wrapper.findAllComponents({ name: "ConfirmDialog" })[1].vm.$emit("confirm");
    await flushPromises();

    expect(deleteDocument).toHaveBeenCalledTimes(3);
    expect(liveFetches()).toBe(before + 1);
  });

  // allSettled, not all: one rejection must not abandon the rest.
  it("still reloads and reports the count when part of a bulk run fails", async () => {
    deleteDocument
      .mockResolvedValueOnce(undefined)
      .mockRejectedValueOnce(new Error("500"))
      .mockResolvedValueOnce(undefined);

    const wrapper = await mountPage();
    const before = liveFetches();

    table(wrapper).vm.$emit("update:modelValue", [1, 2, 3]);
    await flushPromises();
    wrapper.findAllComponents({ name: "ConfirmDialog" })[1].vm.$emit("confirm");
    await flushPromises();

    expect(deleteDocument).toHaveBeenCalledTimes(3);
    expect(liveFetches()).toBe(before + 1);
  });

  it("restores every selected trashed id", async () => {
    const wrapper = await mountPage();

    trashTable(wrapper).vm.$emit("update:modelValue", [4, 5]);
    await flushPromises();

    const restoreSelected = wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) =>
        button.text().includes("Restore selected"),
      )!;
    await restoreSelected.trigger("click");
    await flushPromises();

    expect(restoreDocument).toHaveBeenCalledTimes(2);
    expect(restoreDocument).toHaveBeenCalledWith(4);
    expect(restoreDocument).toHaveBeenCalledWith(5);
  });
});
