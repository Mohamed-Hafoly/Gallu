import { onUnmounted, ref, watch } from "vue";

/**
 * How far below the viewport the sentinel may still be and count as reached.
 * Generous on purpose: the next page starts loading while the user is still
 * scrolling through the current one, so the grid grows without a visible stall.
 */
const ROOT_MARGIN = "400px";

/**
 * Load more when a sentinel element scrolls into view.
 *
 * An IntersectionObserver rather than Vuetify's `v-infinite-scroll`: that
 * component scrolls its own fixed-height element, which would put a second
 * scrollbar inside the page. Watching a sentinel lets the page itself scroll,
 * which is what a full-width image grid wants.
 *
 * `canLoad` is a getter, not a boolean, so the caller's reactive state is read
 * at the moment the observer fires rather than captured once at setup.
 *
 * @param sentinel The element to watch — bind it with `ref="sentinel"` after the grid.
 */
export function useInfiniteScroll(load: () => unknown, canLoad: () => boolean) {
  const sentinel = ref<HTMLElement | null>(null);

  let observer: IntersectionObserver | null = null;

  function disconnect() {
    observer?.disconnect();
    observer = null;
  }

  // Re-observes rather than observing once on mount: the sentinel sits after
  // the grid and is behind a v-if while the first page loads, so the element
  // the caller hands us is null at setup and again on every reset.
  watch(sentinel, (element) => {
    disconnect();

    if (!element) return;

    observer = new IntersectionObserver(
      ([entry]) => {
        // The guard lives here rather than in the caller so a fast scroll
        // cannot queue several loads of the same page: an intersection while a
        // request is in flight, or past the last page, is simply dropped.
        if (entry.isIntersecting && canLoad()) load();
      },
      { rootMargin: ROOT_MARGIN },
    );

    observer.observe(element);
  });

  onUnmounted(disconnect);

  return { sentinel };
}
