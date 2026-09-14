import type { LocationQueryValue } from "vue-router";
import { computed, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";

/**
 * How long typing settles before the term reaches the URL and the wire.
 */
const SEARCH_DEBOUNCE = 300;

/**
 * The param that says a listing wants deleted rows. Named here because leaving
 * one is what prunes a `deleted_at` sort — see filterChip below.
 */
const TRASHED_PARAM = "trashed";

/**
 * The sort column only a trashed listing can offer, for the same reason.
 */
const TRASHED_SORT = "deleted_at";

export interface ListQueryOptions<F extends string> {
  /**
   * What each chip puts on the wire, keyed by chip value.
   *
   * The first entry is the default and must send nothing at all: "All" is the
   * *absence* of a filter, not a value of one, so a listing that does not ask
   * for deleted or narrowed rows never receives any.
   *
   * Order matters for the rest: the last matching entry wins, so a filter whose
   * params overlap another's belongs after it.
   */
  filters: Record<F, Readonly<Record<string, string>>>;
  defaultSortBy: string;
  defaultSortOrder?: "asc" | "desc";
}

/**
 * The URL as the single source of truth for a filtered, searched, sorted
 * listing — shared by the images grid and the documents grid, which differ only
 * in which chips they offer.
 *
 * Derived from the query string rather than held in refs, so back/forward and a
 * refresh land on the right state without a second copy to keep in sync, and a
 * link can be shared.
 */
export function useListQuery<F extends string>(options: ListQueryOptions<F>) {
  const route = useRoute();
  const router = useRouter();

  const keys = Object.keys(options.filters) as F[];
  const defaultFilter = keys[0]!;

  /** Every param any chip owns, so switching chips can clear all of them. */
  const ownedParams = new Set(
    keys.flatMap((key) => Object.keys(options.filters[key])),
  );

  const defaultSortOrder = options.defaultSortOrder ?? "desc";

  /**
   * What the user is typing, seeded from the URL and debounced into it.
   *
   * Separate from the query param on purpose: the URL is the source of truth
   * for the *request*, but writing it on every keystroke would refetch per
   * letter and bury the history in near-identical entries.
   */
  const searchInput = ref<string | null>(String(route.query.search ?? ""));

  /** v-text-field's clear button writes null, so every read goes through here. */
  const typedSearch = () => (searchInput.value ?? "").trim();

  function matches(params: Readonly<Record<string, string>>) {
    return Object.entries(params).every(
      ([key, value]) => route.query[key] === value,
    );
  }

  /**
   * The active chip. Scanned from the end so the last declared filter wins,
   * which is what keeps a hand-written URL carrying two chips' params landing
   * on the more specific one rather than the first that happens to match.
   */
  const filter = computed<F>(() => {
    for (let index = keys.length - 1; index > 0; index--) {
      const key = keys[index]!;
      if (matches(options.filters[key])) return key;
    }

    return defaultFilter;
  });

  /** What the active chip puts on the wire. */
  const filterParams = computed(() => options.filters[filter.value]);

  /** The term that actually goes on the wire; "" is sent as nothing at all. */
  const searchTerm = computed(
    () => String(route.query.search ?? "") || undefined,
  );

  const sortBy = computed(() =>
    String(route.query.sort_by ?? options.defaultSortBy),
  );

  const sortOrder = computed<"asc" | "desc">(() =>
    route.query.sort_order === "asc" ? "asc" : defaultSortOrder,
  );

  /** Writes one query key without disturbing the others. */
  function replaceQuery(patch: Record<string, string | undefined>) {
    const query = { ...route.query };

    for (const [key, value] of Object.entries(patch)) {
      if (value === undefined) delete query[key];
      else query[key] = value;
    }

    router.replace({ query });
  }

  /**
   * v-chip-group binds a value, so the chips read and write the query params
   * through here. Spreading the rest of `route.query` is what keeps the search
   * and the sort from being wiped by a chip click.
   *
   * replace rather than push: the chips filter one page, they are not places to
   * navigate between, so a toggle should not cost a history entry. Pushing made
   * Back walk out of a document one chip click at a time instead of leaving it.
   * The cost, taken deliberately: back and forward no longer step through chip
   * states. The params still live in the URL, so a refresh or a shared link
   * lands on the right chip either way.
   */
  const filterChip = computed({
    get: () => filter.value,
    set: (value: F) => {
      const query: Record<string, LocationQueryValue | LocationQueryValue[]> = {
        ...route.query,
      };

      // Every owned param is cleared before the new one is set: the chips are
      // one axis, and leaving another behind would produce a URL that reads as
      // two filters at once, which is not a state any chip can show.
      for (const key of ownedParams) delete query[key];

      Object.assign(query, options.filters[value]);

      // Leaving the trash while sorted by deleted_at would strand the select on
      // a value it no longer offers, and sort live rows by a column that is
      // null on every one of them.
      if (
        !(TRASHED_PARAM in options.filters[value]) &&
        query.sort_by === TRASHED_SORT
      ) {
        delete query.sort_by;
      }

      router.replace({ query });
    },
  });

  /**
   * Debounced into the URL rather than straight into a request: the query
   * string is what the fetch reads, so writing it is what triggers a reload,
   * and doing that per keystroke would be a request per letter.
   */
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  watch(searchInput, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      replaceQuery({ search: typedSearch() || undefined });
    }, SEARCH_DEBOUNCE);
  });

  // Back/forward and a chip's own fallback can change the term without going
  // through the field, so the field follows the URL too.
  watch(searchTerm, (value) => {
    if ((value ?? "") !== typedSearch()) searchInput.value = value ?? "";
  });

  return {
    filter,
    filterParams,
    filterChip,
    searchInput,
    searchTerm,
    sortBy,
    sortOrder,
    replaceQuery,
  };
}
