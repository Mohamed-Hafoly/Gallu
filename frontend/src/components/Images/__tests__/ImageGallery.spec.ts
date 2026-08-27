import type { Image } from "@/types/image";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ImageGallery from "@/components/Images/ImageGallery.vue";
import i18n from "@/plugins/i18n";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// The store is stubbed rather than driven through axios: the point of these
// cases is which arguments the component passes, not how the store serialises.
const { fetchImages, fetchImagePage, restoreImage } = vi.hoisted(() => ({
  fetchImages: vi.fn(),
  fetchImagePage: vi.fn(),
  restoreImage: vi.fn(),
}));

// The image detail dialog reads the auth store, which imports the real
// router module — building a router here would blow up on its HMR hook.
vi.mock("@/plugins/router", () => ({ default: { replace: vi.fn() } }));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ fetchImages, fetchImagePage, restoreImage }),
}));

/**
 * The chip is the query string, so the spec needs a route it can read and a
 * router whose replace() writes back into it — that round trip is exactly what
 * makes a chip click refetch.
 *
 * The route is held behind a mutable box rather than created inline: it has to
 * be `reactive()` for the component's computed to see a query change, and a
 * vi.hoisted() factory runs before Vue is imported. beforeEach fills the box,
 * and the component reads it once at mount, after that.
 */
const { router, replace } = vi.hoisted(() => ({
  router: { route: null as { query: Record<string, string> } | null },
  replace: vi.fn(),
}));

vi.mock("vue-router", () => ({
  useRoute: () => router.route,
  useRouter: () => ({ replace }),
}));

/**
 * Captures the callback the component's IntersectionObserver is built with, so
 * a spec can say "the sentinel came into view" without a layout engine. The
 * setup file's stub is inert on purpose; this one is only for the paging cases.
 */
let intersect: (() => void) | null = null;

/** Elements the component actually handed to an observer. */
let observed: Element[] = [];

beforeEach(() => {
  vi.clearAllMocks();
  router.route = reactive({ query: {} as Record<string, string> });
  intersect = null;
  observed = [];

  fetchImages.mockResolvedValue([]);
  restoreImage.mockResolvedValue(undefined);
  feedQueue = [];
  idleTotal = 0;

  // Dispatched on per_page rather than call order: the chip-count probe and the
  // feed's own page are fired together, so a mockResolvedValueOnce queue would
  // hand the grid whichever the component happened to call first.
  fetchImagePage.mockImplementation((params: ListParams) => {
    if (params.per_page === 1) {
      return Promise.resolve({ items: [], total: idleTotal, lastPage: 1 });
    }

    return Promise.resolve(
      feedQueue.shift() ?? { items: [], total: 0, lastPage: 1 },
    );
  });

  replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    router.route!.query = { ...query };
  });

  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      intersect = () =>
        callback(
          [{ isIntersecting: true } as IntersectionObserverEntry],
          this as never,
        );
    }

    observe(element: Element) {
      observed.push(element);
    }

    unobserve() {}
    disconnect() {}
    takeRecords() {
      return [];
    }
  } as never;
});

function imageFixture(id: number, overrides: Partial<Image> = {}): Image {
  return {
    id,
    title: `Image ${id}`,
    description: null,
    url: `https://example.test/${id}.jpg`,
    thumb_url: `https://example.test/${id}-thumb.jpg`,
    categories: [],
    document_id: 7,
    user_id: 1,
    creator: "Ada",
    created_at: "2026-08-01T10:00:00.000000Z",
    updated_at: "2026-08-01T10:00:00.000000Z",
    ...overrides,
  };
}

function page(ids: number[], lastPage: number) {
  return { items: ids.map((id) => imageFixture(id)), total: ids.length, lastPage };
}

type ListParams = { per_page: number } & Record<string, unknown>;

/** What every feed request carries before any chip, search or sort is applied. */
const FEED_DEFAULTS = {
  document_id: 7,
  per_page: 20,
  sort_by: "created_at",
  sort_order: "desc",
};

/** Feed pages handed out in order, one per per_page-20 request. */
let feedQueue: ReturnType<typeof page>[] = [];

/** What the one-row count probe reports for the chip that is not selected. */
let idleTotal = 0;

/** The paged feed's own requests, told apart from the chip-count probes. */
function feedCalls() {
  return fetchImagePage.mock.calls
    .map(([params]) => params as ListParams)
    .filter((params) => params.per_page === 20);
}

/** The one-row requests that only exist to read meta.total for a chip. */
function countCalls() {
  return fetchImagePage.mock.calls
    .map(([params]) => params as ListParams)
    .filter((params) => params.per_page === 1);
}

function mountGallery(props: Record<string, unknown> = {}) {
  return mountWithPlugins(ImageGallery, { props });
}

describe("ImageGallery", () => {
  // /gallery spans every document, so it has no filter row at all: no chips to
  // narrow by owner, and no search or sort, since it is one unpaged request.
  it("fetches everything at once when no document is given", async () => {
    const wrapper = mountGallery();
    await flushPromises();

    expect(wrapper.findComponent({ name: "VTextField" }).exists()).toBe(false);
    expect(fetchImages).toHaveBeenCalledWith(undefined);
    expect(fetchImagePage).not.toHaveBeenCalled();
  });

  it("shows no owner chips outside a document", async () => {
    const wrapper = mountGallery();
    await flushPromises();

    expect(wrapper.findComponent({ name: "VChipGroup" }).exists()).toBe(false);
  });

  // The upload button lives in the document header now, not here — this is
  // only about the feed being paged rather than fetched whole.
  it("pages the fetch inside a document", async () => {
    mountGallery({ documentId: 7 });
    await flushPromises();

    expect(fetchImages).not.toHaveBeenCalled();
    expect(feedCalls()).toEqual([{ ...FEED_DEFAULTS, page: 1 }]);
  });

  // The selected chip's count rides along on its own page; the other two cost a
  // request each, and those ask for a single row.
  it("asks for the idle chips' counts with one-row requests", async () => {
    feedQueue = [page([1], 1)];
    idleTotal = 3;

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(countCalls()).toEqual([
      { document_id: 7, page: 1, per_page: 1, owner: "mine" },
      { document_id: 7, page: 1, per_page: 1, trashed: "only" },
    ]);

    const chips = wrapper.findAllComponents({ name: "VChip" });

    expect(chips[0]!.text()).toContain("1");
    expect(chips[1]!.text()).toContain("3");
    expect(chips[2]!.text()).toContain("3");
  });

  // Both omissions matter: `owner` because "All" is the absence of the filter
  // and the backend 422s any other value, `trashed` because sending it would
  // pull soft-deleted images back into the document page.
  it("sends neither owner nor trashed on the default listing", async () => {
    mountGallery({ documentId: 7 });
    await flushPromises();

    const params = feedCalls()[0]!;

    expect(params).not.toHaveProperty("owner");
    expect(params).not.toHaveProperty("trashed");
  });

  it("narrows to the caller's images when the url says so", async () => {
    router.route!.query = { owner: "mine" };

    mountGallery({ documentId: 7 });
    await flushPromises();

    expect(feedCalls()).toEqual([
      { ...FEED_DEFAULTS, page: 1, owner: "mine" },
    ]);
  });

  it("refetches from page one when the chip changes", async () => {
    feedQueue = [page([1, 2], 2), page([3], 1)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "mine");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: { owner: "mine" } });
    expect(feedCalls().at(-1)).toEqual({
      ...FEED_DEFAULTS,
      page: 1,
      owner: "mine",
    });
    // Replaced, not appended: the previous chip's rows are gone.
    expect(wrapper.findAllComponents({ name: "VCard" })).toHaveLength(1);
  });

  // Selecting "All" clears the param rather than setting owner=all, and leaves
  // any other query key — the search planned for this row — alone.
  it("drops the owner param for All and keeps the rest of the query", async () => {
    router.route!.query = { owner: "mine", search: "cat" };

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "all");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: { search: "cat" } });
    // The search rides through the chip click and is still on the wire.
    expect(feedCalls().at(-1)).toEqual({
      ...FEED_DEFAULTS,
      page: 1,
      search: "cat",
    });
  });

  /**
   * The specs below drive the observer's callback directly, which would keep
   * passing even if the sentinel ref never bound and nothing was ever watched.
   * This is the case that asserts the wiring itself.
   */
  it("hands the sentinel element to the observer", async () => {
    feedQueue = [page([1, 2], 2)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const sentinel = wrapper.find(".py-6.justify-center").element;

    expect(observed).toContain(sentinel);
  });

  it("appends the next page when the sentinel comes into view", async () => {
    feedQueue = [page([1, 2], 2), page([3, 4], 2)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(wrapper.findAllComponents({ name: "VCard" })).toHaveLength(2);

    intersect!();
    await flushPromises();

    expect(feedCalls().at(-1)).toEqual({ ...FEED_DEFAULTS, page: 2 });
    expect(wrapper.findAllComponents({ name: "VCard" })).toHaveLength(4);
  });

  it("stops paging once the last page has been reached", async () => {
    feedQueue = [page([1, 2], 2), page([3, 4], 2)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    intersect!();
    await flushPromises();

    expect(feedCalls()).toHaveLength(2);

    // The sentinel is unrendered at the end of the listing, so there is nothing
    // left to observe and no doomed third request.
    expect(wrapper.find("[class*='justify-center'][class*='py-6']").exists()).toBe(
      false,
    );
  });

  it("passes the document id down to the create dialog", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const dialog = wrapper.findComponent({ name: "ImageCreateDialog" });

    expect(dialog.exists()).toBe(true);
    expect(dialog.props("documentId")).toBe(7);
  });
});

/**
 * The trash is a third bucket, not a subset: All and Yours are live-only, and a
 * deleted image appears under Recently deleted and nowhere else.
 *
 * Visible to everyone — the backend scopes a trashed listing to the caller's
 * own images rather than refusing it, so there is no role gate here.
 */
describe("recently deleted chip", () => {
  function chips(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findAllComponents({ name: "VChip" });
  }

  function alert(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findComponent({ name: "VAlert" });
  }

  it("renders a third chip inside a document", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(chips(wrapper)).toHaveLength(3);
  });

  it("sends trashed only, and no owner, when selected", async () => {
    router.route!.query = { trashed: "only" };

    mountGallery({ documentId: 7 });
    await flushPromises();

    expect(feedCalls()).toEqual([
      { ...FEED_DEFAULTS, page: 1, trashed: "only" },
    ]);
  });

  // The invariant: nothing that did not ask for deleted rows may receive any.
  it("never sends trashed on All or Yours", async () => {
    mountGallery({ documentId: 7 });
    await flushPromises();

    for (const params of feedCalls()) expect(params).not.toHaveProperty("trashed");

    router.route!.query = { owner: "mine" };
    mountGallery({ documentId: 7 });
    await flushPromises();

    for (const params of feedCalls()) expect(params).not.toHaveProperty("trashed");
  });

  // One axis, so a chip click must clear the other key or the URL ends up
  // claiming two filters at once.
  it("clears the other axis when switching chips", async () => {
    router.route!.query = { owner: "mine" };

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "trash");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: { trashed: "only" } });

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "all");
    await flushPromises();

    expect(replace).toHaveBeenLastCalledWith({ query: {} });
  });

  it("warns about permanent deletion only on that chip", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(alert(wrapper).exists()).toBe(false);

    router.route!.query = { trashed: "only" };
    const trashed = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(alert(trashed).exists()).toBe(true);
    // Through i18n rather than a literal: this spec does not pin the locale,
    // and the app's default is Arabic.
    expect(alert(trashed).text()).toContain(
      i18n.global.t("admin.images.trashedTitleNote"),
    );
  });

  it("restores an image, drops it and re-counts", async () => {
    router.route!.query = { trashed: "only" };
    feedQueue = [page([1, 2], 1)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const restoreButton = wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.props("icon") === "mdi-restore")!;

    countCalls().length = 0;
    await restoreButton.trigger("click");
    await flushPromises();

    expect(restoreImage).toHaveBeenCalledWith(1);
    expect(wrapper.findAllComponents({ name: "VCard" })).toHaveLength(1);
  });

  it("offers no restore button outside the trash chip", async () => {
    feedQueue = [page([1], 1)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(
      wrapper
        .findAllComponents({ name: "VBtn" })
        .some((button) => button.props("icon") === "mdi-restore"),
    ).toBe(false);
  });
});

/**
 * Search and sort mirror the admin images table and live in the URL alongside
 * the chips, so a refresh or a shared link lands on the same view.
 */
describe("search and sort", () => {
  function searchField(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findComponent({ name: "VTextField" });
  }

  function sortSelect(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findComponent({ name: "VSelect" });
  }

  function directionButton(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => String(button.props("icon")).startsWith("mdi-sort-"))!;
  }

  // Debounced: a typed word is one request, not one per letter.
  it("writes the term to the url once, after the pause", async () => {
    vi.useFakeTimers();

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    searchField(wrapper).vm.$emit("update:modelValue", "bea");
    searchField(wrapper).vm.$emit("update:modelValue", "beach");

    // The watcher is flushed on the microtask queue, so it has to run before
    // the clock moves — otherwise there is no timer yet to advance past.
    await flushPromises();

    expect(replace).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);
    vi.useRealTimers();
    await flushPromises();

    expect(replace).toHaveBeenCalledTimes(1);
    expect(replace).toHaveBeenCalledWith({ query: { search: "beach" } });
  });

  it("sends the term and refetches from page one", async () => {
    router.route!.query = { search: "beach" };

    mountGallery({ documentId: 7 });
    await flushPromises();

    expect(feedCalls()).toEqual([
      { ...FEED_DEFAULTS, page: 1, search: "beach" },
    ]);
  });

  // Counts have to agree with the grid, or the chips advertise rows the search
  // has hidden. Ordering cannot change a total, so the sort stays out.
  it("narrows the chip counts by the search but not the sort", async () => {
    router.route!.query = { search: "beach", sort_by: "title" };

    mountGallery({ documentId: 7 });
    await flushPromises();

    for (const params of countCalls()) {
      expect(params.search).toBe("beach");
      expect(params).not.toHaveProperty("sort_by");
      expect(params).not.toHaveProperty("sort_order");
    }
  });

  it("defaults to newest first", async () => {
    mountGallery({ documentId: 7 });
    await flushPromises();

    expect(feedCalls()[0]).toMatchObject({
      sort_by: "created_at",
      sort_order: "desc",
    });
  });

  it("puts a chosen sort field in the url", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    sortSelect(wrapper).vm.$emit("update:modelValue", "title");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: { sort_by: "title" } });
    expect(feedCalls().at(-1)).toMatchObject({ page: 1, sort_by: "title" });
  });

  it("flips the direction and refetches from page one", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    await directionButton(wrapper).trigger("click");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: { sort_order: "asc" } });
    expect(feedCalls().at(-1)).toMatchObject({ page: 1, sort_order: "asc" });
  });

  // Every live row's deleted_at is null, so sorting by it anywhere else would
  // be a sort that does nothing.
  it("offers deleted_at only on the trash chip", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const values = (items: unknown) =>
      (items as { value: string }[]).map((item) => item.value);

    expect(values(sortSelect(wrapper).props("items"))).not.toContain("deleted_at");

    router.route!.query = { trashed: "only" };
    const trashed = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(values(sortSelect(trashed).props("items"))).toContain("deleted_at");
  });

  // Leaving the trash must not strand the select on a value it no longer lists.
  it("drops a deleted_at sort when leaving the trash", async () => {
    router.route!.query = { trashed: "only", sort_by: "deleted_at" };

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "all");
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ query: {} });
    expect(feedCalls().at(-1)).toMatchObject({ sort_by: "created_at" });
  });
});

/**
 * Class-level only: jsdom has no layout engine, so nothing here can prove the
 * row actually wraps or where anything lands — that is checked in a browser.
 * What this guards is the requirement, not the styling: sorting stays at the
 * end of whatever line it is on.
 */
describe("filter row layout", () => {
  function filterRow(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findComponent({ name: "VChipGroup" }).element.parentElement!;
  }

  it("wraps rather than squeezing the chips", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(filterRow(wrapper).className).toContain("flex-wrap");
    // justify-between would put a lone wrapped item at the line's *start*.
    expect(filterRow(wrapper).className).not.toContain("justify-between");
  });

  it("keeps the sort controls at the end of their line", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const sortGroup = wrapper.findComponent({ name: "VSelect" }).element.parentElement!;

    // ms-auto, not ml-auto: it has to flip with RTL.
    expect(sortGroup.className).toContain("ms-auto");
  });
});

/**
 * The card's time carries its own label, so a bare relative time can never be
 * mistaken for the wrong thing. Locale pinned to en: this spec does not pin one
 * and the app defaults to Arabic, so the assertions would otherwise compare
 * against Arabic strings.
 */
describe("card timestamp", () => {
  const HOUR = 3_600_000;
  const DAY = 24 * HOUR;

  function cardText(wrapper: ReturnType<typeof mountGallery>) {
    return wrapper.findComponent({ name: "VCard" }).text();
  }

  beforeEach(() => {
    i18n.global.locale.value = "en";
  });

  // Three clearly separated values, so passing cannot be a coincidence.
  it("labels the time as the last update, and uses updated_at", async () => {
    feedQueue = [
      {
        items: [
          imageFixture(1, {
            created_at: new Date(Date.now() - 9 * DAY).toISOString(),
            updated_at: new Date(Date.now() - 3 * HOUR).toISOString(),
          }),
        ],
        total: 1,
        lastPage: 1,
      },
    ];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(cardText(wrapper)).toContain("last updated 3 hours ago");
    // created_at, which the card deliberately no longer shows.
    expect(cardText(wrapper)).not.toContain("9 days ago");
  });

  // "last updated" would be a lie here — the card is showing deleted_at.
  it("labels it as a deletion on the trash chip", async () => {
    router.route!.query = { trashed: "only" };
    feedQueue = [
      {
        items: [
          imageFixture(1, {
            created_at: new Date(Date.now() - 9 * DAY).toISOString(),
            updated_at: new Date(Date.now() - 3 * HOUR).toISOString(),
            deleted_at: new Date(Date.now() - 5 * DAY).toISOString(),
          }),
        ],
        total: 1,
        lastPage: 1,
      },
    ];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(cardText(wrapper)).toContain("deleted 5 days ago");
    expect(cardText(wrapper)).not.toContain("last updated");
  });
});
