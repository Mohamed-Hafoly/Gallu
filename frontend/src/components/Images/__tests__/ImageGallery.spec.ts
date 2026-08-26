import type { Image } from "@/types/image";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ImageGallery from "@/components/Images/ImageGallery.vue";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// The store is stubbed rather than driven through axios: the point of these
// cases is which arguments the component passes, not how the store serialises.
const { fetchImages, fetchImagePage } = vi.hoisted(() => ({
  fetchImages: vi.fn(),
  fetchImagePage: vi.fn(),
}));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ fetchImages, fetchImagePage }),
}));

/**
 * The chip is the query string, so the spec needs a route it can read and a
 * router whose push() writes back into it — that round trip is exactly what
 * makes a chip click refetch.
 *
 * The route is held behind a mutable box rather than created inline: it has to
 * be `reactive()` for the component's computed to see a query change, and a
 * vi.hoisted() factory runs before Vue is imported. beforeEach fills the box,
 * and the component reads it once at mount, after that.
 */
const { router, push } = vi.hoisted(() => ({
  router: { route: null as { query: Record<string, string> } | null },
  push: vi.fn(),
}));

vi.mock("vue-router", () => ({
  useRoute: () => router.route,
  useRouter: () => ({ push }),
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

  push.mockImplementation(({ query }: { query: Record<string, string> }) => {
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

function imageFixture(id: number): Image {
  return {
    id,
    title: `Image ${id}`,
    description: null,
    url: `https://example.test/${id}.jpg`,
    thumb_url: `https://example.test/${id}-thumb.jpg`,
    categories: [],
    document_id: 7,
    creator: "Ada",
    created_at: "2026-08-01T10:00:00.000000Z",
    updated_at: "2026-08-01T10:00:00.000000Z",
  };
}

function page(ids: number[], lastPage: number) {
  return { items: ids.map((id) => imageFixture(id)), total: ids.length, lastPage };
}

type ListParams = { per_page: number } & Record<string, unknown>;

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
  // /gallery spans every document, so there is nothing an upload could attach
  // to — the button is hidden rather than shown and then failing validation.
  it("hides the upload button when no document is given", async () => {
    const wrapper = mountGallery();
    await flushPromises();

    expect(wrapper.findComponent({ name: "VBtn" }).exists()).toBe(false);
    expect(fetchImages).toHaveBeenCalledWith(undefined);
    expect(fetchImagePage).not.toHaveBeenCalled();
  });

  it("shows no owner chips outside a document", async () => {
    const wrapper = mountGallery();
    await flushPromises();

    expect(wrapper.findComponent({ name: "VChipGroup" }).exists()).toBe(false);
  });

  it("shows the upload button and pages the fetch inside a document", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(wrapper.findComponent({ name: "VBtn" }).exists()).toBe(true);
    expect(fetchImages).not.toHaveBeenCalled();
    expect(feedCalls()).toEqual([{ document_id: 7, page: 1, per_page: 20 }]);
  });

  // The selected chip's count rides along on its own page; only the other one
  // costs a request, and that request asks for a single row.
  it("asks for the idle chip's count with a one-row request", async () => {
    feedQueue = [page([1], 1)];
    idleTotal = 3;

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(countCalls()).toEqual([
      { document_id: 7, page: 1, per_page: 1, owner: "mine" },
    ]);

    const chips = wrapper.findAllComponents({ name: "VChip" });

    expect(chips[0]!.text()).toContain("1");
    expect(chips[1]!.text()).toContain("3");
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
      { document_id: 7, page: 1, per_page: 20, owner: "mine" },
    ]);
  });

  it("refetches from page one when the chip changes", async () => {
    feedQueue = [page([1, 2], 2), page([3], 1)];

    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    wrapper.findComponent({ name: "VChipGroup" }).vm.$emit("update:modelValue", "mine");
    await flushPromises();

    expect(push).toHaveBeenCalledWith({ query: { owner: "mine" } });
    expect(feedCalls().at(-1)).toEqual({
      document_id: 7,
      page: 1,
      per_page: 20,
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

    expect(push).toHaveBeenCalledWith({ query: { search: "cat" } });
    expect(feedCalls().at(-1)).toEqual({
      document_id: 7,
      page: 1,
      per_page: 20,
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

    expect(feedCalls().at(-1)).toEqual({
      document_id: 7,
      page: 2,
      per_page: 20,
    });
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
