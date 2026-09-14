import type { DocumentListParams } from "@/stores/document";
import type { Document } from "@/types/document";
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

const { fetchDocumentPage, deleteDocument, restoreDocument } = vi.hoisted(
  () => ({
    fetchDocumentPage: vi.fn(),
    deleteDocument: vi.fn(),
    restoreDocument: vi.fn(),
  }),
);

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({
    fetchDocumentPage,
    deleteDocument,
    restoreDocument,
  }),
}));

/**
 * The listing is server-side, so these assert the *params* the page sends
 * rather than any client-side filtering.
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
    team: { id: 1, name: "Design", deleted_at: null },
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
    ...overrides,
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

beforeEach(() => {
  i18n.global.locale.value = "en";
  fetchDocumentPage.mockReset();
  deleteDocument.mockReset();
  restoreDocument.mockReset();
  deleteDocument.mockResolvedValue(undefined);
  restoreDocument.mockResolvedValue(undefined);

  fetchDocumentPage.mockResolvedValue({
    items: Array.from({ length: 10 }, (_, index) => document_(index + 1)),
    total: 35,
  });
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

/**
 * A live document can no longer have a trashed team — Team::booted() takes them
 * down with it — so a non-null team.deleted_at means this row is waiting for its
 * team to come back, and its own restore would be a 409.
 */
describe("a document whose team is trashed", () => {
  const BINNED_TEAM = {
    id: 1,
    name: "Design",
    deleted_at: "2026-08-30T10:00:00.000000Z",
  };

  /** Live rows keep their live team; the trash serves rows waiting on theirs. */
  function withBlockedTrash() {
    fetchDocumentPage.mockImplementation((params: DocumentListParams) =>
      Promise.resolve({
        items:
          params.trashed === "only"
            ? [
              document_(4, {
                team: BINNED_TEAM,
                deleted_at: "2026-08-30T10:00:00.000000Z",
              }),
              document_(5, { deleted_at: "2026-08-30T10:00:00.000000Z" }),
            ]
            : [document_(1)],
        total: 2,
      }),
    );
  }

  it("disables its restore button and says which team to restore", async () => {
    withBlockedTrash();
    const wrapper = await mountPage();

    const rows = trashTable(wrapper).findAll("tbody tr");
    const blocked = rowButton(rows[0], ".mdi-restore");
    const allowed = rowButton(rows[1], ".mdi-restore");

    expect(blocked.attributes("disabled")).toBeDefined();
    expect(blocked.attributes("title")).toContain("Design");
    expect(allowed.attributes("disabled")).toBeUndefined();
  });

  // Not just cosmetic on the button: the row must not be selectable either, or
  // a bulk restore would send a request that can only come back 409.
  it("refuses to bulk restore it, while restoring the rest", async () => {
    withBlockedTrash();
    const wrapper = await mountPage();

    trashTable(wrapper).vm.$emit("update:modelValue", [4, 5]);
    await flushPromises();

    const restoreSelected = wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.text().includes("Restore selected"))!;
    await restoreSelected.trigger("click");
    await flushPromises();

    expect(restoreDocument).toHaveBeenCalledTimes(1);
    expect(restoreDocument).toHaveBeenCalledWith(5);
  });

  // The name is served through withTrashed(), so the cell explains the disabled
  // button rather than showing the "-" a scoped-away relation used to give.
  it("names the trashed team in its own cell", async () => {
    withBlockedTrash();
    const wrapper = await mountPage();

    const row = trashTable(wrapper).findAll("tbody tr")[0];

    expect(row.find(".mdi-delete-clock").exists()).toBe(true);
    expect(row.text()).toContain("Design");
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

/**
 * Images are managed on the document page now, not in a sub-row here, so the
 * count doubles as the way in. Nothing on this screen expands any more.
 */
describe("image count column", () => {
  /**
   * The count cell's link. `:to` renders an anchor rather than a button, so
   * rowButton() cannot find it — and two anchors in the row carry this icon,
   * since the actions column's "Open document" points at the same place. The
   * count is what tells them apart: that one is icon-only.
   */
  function countButton(wrapper: Wrapper, rowIndex: number) {
    return bodyRows(wrapper)
      [rowIndex].findAll("a")
      .find(
        (link) =>
          link.element.querySelector(".mdi-open-in-new") !== null &&
          link.text().trim() !== "",
      )!;
  }

  it("shows each document's image count", async () => {
    fetchDocumentPage.mockResolvedValue({
      items: [document_(1, { images_count: 7 })],
      total: 1,
    });

    const wrapper = await mountPage();

    expect(countButton(wrapper, 0).text()).toContain("7");
  });

  it("links the count to the document page", async () => {
    const wrapper = await mountPage();

    // The prop, not an href: the router is mocked, so RouterLink resolves
    // nothing and the anchor renders without one. `append-icon` is what
    // separates this from the actions column's icon-only "Open document".
    const link = bodyRows(wrapper)[0]
      .findAllComponents({ name: "VBtn" })
      .find(
        (button: { props: (name: string) => unknown }) =>
          button.props("appendIcon") === "mdi-open-in-new",
      )!;

    expect(link.props("to")).toEqual({
      name: "/documents/[id]",
      params: { id: 1 },
    });
  });

  // The sub-rows are gone; a surviving expand toggle would be the tell.
  it("no longer expands rows", async () => {
    const wrapper = await mountPage();

    expect(table(wrapper).props("showExpand")).toBeFalsy();
    expect(wrapper.find(".mdi-chevron-down").exists()).toBe(false);
  });

  /**
   * The column sorts on withCount()'s `images_count` select alias, which
   * DocumentController::SORTABLE lists — it used to be sortable: false, back
   * when sending that key was a 422.
   *
   * Asserted on the header rather than only through a synthetic update:options,
   * because the emit-driven case below would pass whatever the flag said.
   */
  function imageCountHeader(headers: unknown) {
    return (headers as { key: string; sortable?: boolean }[]).find(
      (header) => header.key === "images_count",
    )!;
  }

  it("lets both tables sort by the count", async () => {
    const wrapper = await mountPage();

    expect(imageCountHeader(table(wrapper).props("headers")).sortable).toBe(
      true,
    );
    // trashedHeaders is derived from headers, so this is what keeps them
    // agreeing rather than a second declaration.
    expect(imageCountHeader(trashTable(wrapper).props("headers")).sortable).toBe(
      true,
    );
  });

  it("sends the count sort to the endpoint", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 1,
      itemsPerPage: 10,
      sortBy: [{ key: "images_count", order: "desc" }],
    });
    await flushPromises();

    expect(lastParams()).toMatchObject({
      sort_by: "images_count",
      sort_order: "desc",
    });
  });
});

/**
 * The team column sorts on the team's name, through a correlated subselect the
 * backend maps `team` to — it was sortable: false while the only candidate was
 * the `team_id` column, which would have ordered by insertion.
 */
describe("team column", () => {
  function teamHeader(headers: unknown) {
    return (headers as { key: string; sortable?: boolean }[]).find(
      (header) => header.key === "team",
    )!;
  }

  it("lets both tables sort by the team", async () => {
    const wrapper = await mountPage();

    expect(teamHeader(table(wrapper).props("headers")).sortable).toBe(true);
    expect(teamHeader(trashTable(wrapper).props("headers")).sortable).toBe(true);
  });

  it("sends the team sort to the endpoint", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 1,
      itemsPerPage: 10,
      sortBy: [{ key: "team", order: "asc" }],
    });
    await flushPromises();

    expect(lastParams()).toMatchObject({ sort_by: "team", sort_order: "asc" });
  });
});
