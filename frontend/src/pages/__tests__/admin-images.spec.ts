import type { ImageListParams } from "@/stores/image";
import type { Image } from "@/types/image";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import AdminImages from "@/pages/admin/images.vue";
import i18n from "@/plugins/i18n";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const { fetchImagePage, deleteImage, restoreImage } = vi.hoisted(() => ({
  fetchImagePage: vi.fn(),
  deleteImage: vi.fn(),
  restoreImage: vi.fn(),
}));

// The image detail dialog reads the auth store, which imports the real
// router module — building a router here would blow up on its HMR hook.
vi.mock("@/plugins/router", () => ({ default: { replace: vi.fn() } }));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ fetchImagePage, deleteImage, restoreImage }),
}));

/**
 * The live/trashed split is the server's job now, so these assert the *params*
 * each table sends rather than any client-side filtering — and that anything
 * moving a row between the tables refetches both.
 */

function image(id: number, overrides: Partial<Image> = {}): Image {
  return {
    id,
    title: `Image ${id}`,
    description: null,
    url: `/i/${id}.jpg`,
    thumb_url: `/i/${id}-thumb.jpg`,
    categories: [],
    document_id: 1,
    user_id: 1,
    creator: "Ada Lovelace",
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
    ...overrides,
  };
}

async function mountPage() {
  fetchImagePage.mockResolvedValue({ items: [image(1)], total: 1 });
  const wrapper = mountWithPlugins(AdminImages);
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>;

function tables(wrapper: Wrapper) {
  return wrapper.findAllComponents({ name: "VDataTableServer" });
}

/** Every params object the store was handed, newest last. */
function calls(): ImageListParams[] {
  return fetchImagePage.mock.calls.map(([params]) => params);
}

function liveCalls() {
  return calls().filter((p) => p.trashed === undefined);
}

function trashCalls() {
  return calls().filter((p) => p.trashed === "only");
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
});

describe("two listings, one screen", () => {
  it("fetches each table once on mount, live without trashed and trash with only", async () => {
    await mountPage();

    expect(liveCalls()).toHaveLength(1);
    expect(trashCalls()).toHaveLength(1);
  });

  it("renders both as server tables fed by items-length", async () => {
    const wrapper = await mountPage();

    expect(tables(wrapper)).toHaveLength(2);
    expect(tables(wrapper)[0].props("itemsLength")).toBe(1);
    expect(tables(wrapper)[1].props("itemsLength")).toBe(1);
  });

  // Paging one table must not drag the other along — they are independent
  // listings, and a refetch of both on every page change would double the cost.
  it("reloads only the table whose options changed", async () => {
    const wrapper = await mountPage();
    fetchImagePage.mockClear();

    tables(wrapper)[0].vm.$emit("update:options", {
      page: 2,
      itemsPerPage: 10,
      sortBy: [{ key: "creator", order: "desc" }],
    });
    await flushPromises();

    expect(liveCalls()).toHaveLength(1);
    expect(trashCalls()).toHaveLength(0);
    expect(liveCalls()[0]).toMatchObject({
      page: 2,
      sort_by: "creator",
      sort_order: "desc",
    });
  });
});

describe("search", () => {
  // The reason this screen exists: the documents table could only find a
  // document, never an image inside one.
  it("sends the term with both tables and resets both to page one", async () => {
    const wrapper = await mountPage();

    // Put the live table on a later page first, so the reset is observable.
    tables(wrapper)[0].vm.$emit("update:options", {
      page: 3,
      itemsPerPage: 10,
      sortBy: [],
    });
    await flushPromises();
    fetchImagePage.mockClear();

    await wrapper.findComponent({ name: "VTextField" }).setValue("harbour");
    await new Promise((resolve) => setTimeout(resolve, 400));
    await flushPromises();

    expect(liveCalls()[0]).toMatchObject({ page: 1, search: "harbour" });
    expect(trashCalls()[0]).toMatchObject({
      page: 1,
      search: "harbour",
      trashed: "only",
    });
  });

  it("debounces to one pair of requests per typed word", async () => {
    const wrapper = await mountPage();
    fetchImagePage.mockClear();

    const field = wrapper.findComponent({ name: "VTextField" });
    await field.setValue("h");
    await field.setValue("ha");
    await field.setValue("har");
    await new Promise((resolve) => setTimeout(resolve, 400));
    await flushPromises();

    expect(fetchImagePage).toHaveBeenCalledTimes(2);
  });
});

describe("mutations refetch both tables", () => {
  // A delete moves a row down and a restore moves it up, so reloading only the
  // table that was acted on leaves the other showing a row it no longer holds.
  it("reloads both after a restore", async () => {
    const wrapper = await mountPage();
    restoreImage.mockResolvedValue(image(1));
    fetchImagePage.mockClear();

    const trashRow = wrapper.findAll("tbody tr").at(-1)!;
    await trashRow.find("button.v-btn").trigger("click");
    await flushPromises();

    expect(restoreImage).toHaveBeenCalledWith(1);
    expect(liveCalls()).toHaveLength(1);
    expect(trashCalls()).toHaveLength(1);
  });
});
