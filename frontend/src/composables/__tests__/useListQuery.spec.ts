import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, reactive } from "vue";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import { useListQuery } from "@/composables/useListQuery";

/**
 * The whole point of this composable is the round trip through the URL, so the
 * spec needs a reactive route it can read and a router whose replace() writes
 * back into it. Held behind a mutable box because a vi.hoisted() factory runs
 * before Vue is imported.
 */
const { router, replace } = vi.hoisted(() => ({
  router: { route: null as { query: Record<string, string> } | null },
  replace: vi.fn(),
}));

vi.mock("vue-router", () => ({
  useRoute: () => router.route,
  useRouter: () => ({ replace }),
}));

const FILTERS = {
  all: {},
  mine: { owner: "mine" },
  trash: { trashed: "only" },
} as const;

type Filter = keyof typeof FILTERS;

/**
 * Composables that call useRoute need a component instance, and this one also
 * registers watchers — so it is mounted rather than called bare.
 */
function mountQuery() {
  let api: ReturnType<typeof useListQuery<Filter>>;

  mountWithPlugins(
    defineComponent({
      setup() {
        api = useListQuery<Filter>({
          filters: FILTERS,
          defaultSortBy: "created_at",
          defaultSortOrder: "desc",
        });

        return () => null;
      },
    }),
  );

  return api!;
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.useRealTimers();
  router.route = reactive({ query: {} as Record<string, string> });

  replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    router.route!.query = { ...query };
  });
});

describe("the active filter", () => {
  // "All" is the absence of every param, not a value of one, so the default URL
  // stays clean.
  it("falls back to the first filter when no param is set", () => {
    expect(mountQuery().filter.value).toBe("all");
    expect(mountQuery().filterParams.value).toEqual({});
  });

  it("reads a filter off its own param", () => {
    router.route!.query = { owner: "mine" };

    expect(mountQuery().filter.value).toBe("mine");
  });

  // A hand-written URL carrying two chips' params is not a state any chip can
  // show; the later-declared one wins, as the branch this replaced did.
  it("prefers the last declared filter when two match", () => {
    router.route!.query = { owner: "mine", trashed: "only" };

    expect(mountQuery().filter.value).toBe("trash");
  });
});

describe("switching chips", () => {
  it("clears the previous chips param before setting the new one", () => {
    router.route!.query = { owner: "mine" };
    const query = mountQuery();

    query.filterChip.value = "trash";

    expect(router.route!.query).toEqual({ trashed: "only" });
  });

  // The chips are one axis; the search and sort are others, and a chip click
  // must not wipe them.
  it("keeps the search and sort across a chip change", () => {
    router.route!.query = { search: "trip", sort_by: "title" };
    const query = mountQuery();

    query.filterChip.value = "trash";

    expect(router.route!.query).toEqual({
      search: "trip",
      sort_by: "title",
      trashed: "only",
    });
  });

  // Every live row's deleted_at is null, so leaving the trash sorted by it
  // would strand the select on a value it no longer offers.
  it("drops a deleted_at sort when leaving the trash", () => {
    router.route!.query = { trashed: "only", sort_by: "deleted_at" };
    const query = mountQuery();

    query.filterChip.value = "all";

    expect(router.route!.query).toEqual({});
  });

  it("keeps a deleted_at sort while staying in the trash", () => {
    router.route!.query = { owner: "mine", sort_by: "deleted_at" };
    const query = mountQuery();

    query.filterChip.value = "trash";

    expect(router.route!.query).toMatchObject({ sort_by: "deleted_at" });
  });
});

describe("the search term", () => {
  // Debounced into the URL rather than straight into a request: the query
  // string is what the fetch reads, so writing it per keystroke would be a
  // request per letter.
  it("waits for typing to settle before writing the URL", async () => {
    vi.useFakeTimers();
    const query = mountQuery();

    query.searchInput.value = "tr";
    await flushPromises();
    query.searchInput.value = "trip";
    await flushPromises();

    expect(replace).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);

    expect(replace).toHaveBeenCalledExactlyOnceWith({
      query: { search: "trip" },
    });
  });

  // "" is sent as nothing at all, so an emptied field leaves no param behind.
  it("removes the param when the field is cleared", async () => {
    vi.useFakeTimers();
    router.route!.query = { search: "trip" };
    const query = mountQuery();

    // v-text-field's clear button writes null, not "".
    query.searchInput.value = null;
    await flushPromises();
    vi.advanceTimersByTime(300);

    expect(router.route!.query).toEqual({});
    expect(query.searchTerm.value).toBeUndefined();
  });

  // Back/forward and a chip's own fallback can change the term without going
  // through the field.
  it("follows the URL when the term changes elsewhere", async () => {
    const query = mountQuery();

    router.route!.query = { search: "later" };
    await flushPromises();

    expect(query.searchInput.value).toBe("later");
  });
});

describe("the sort", () => {
  it("defaults to the column and direction it was given", () => {
    const query = mountQuery();

    expect(query.sortBy.value).toBe("created_at");
    expect(query.sortOrder.value).toBe("desc");
  });

  it("reads both off the URL", () => {
    router.route!.query = { sort_by: "title", sort_order: "asc" };
    const query = mountQuery();

    expect(query.sortBy.value).toBe("title");
    expect(query.sortOrder.value).toBe("asc");
  });

  // One key at a time, so writing the column cannot drop the direction.
  it("patches one key without disturbing the others", () => {
    router.route!.query = { trashed: "only", sort_order: "asc" };
    const query = mountQuery();

    query.replaceQuery({ sort_by: "title" });

    expect(router.route!.query).toEqual({
      trashed: "only",
      sort_order: "asc",
      sort_by: "title",
    });
  });

  it("removes a key patched with undefined", () => {
    router.route!.query = { sort_by: "title" };
    const query = mountQuery();

    query.replaceQuery({ sort_by: undefined });

    expect(router.route!.query).toEqual({});
  });
});
