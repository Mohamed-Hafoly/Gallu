import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useNotifierStore } from "@/stores/notifier";

export interface RunBulkOptions {
  /** i18n key for the all-succeeded notice, given `{ count }`. */
  successKey: string;
  /** i18n key for the partial-failure notice, given the failed `{ count }`. */
  failureKey: string;
  /**
   * The ids that actually went through, so the caller can splice its own list
   * and adjust its own counts. Only the fulfilled ones are passed: a row whose
   * request was rejected is still there.
   */
  onRemoved: (ids: number[]) => void;
}

/**
 * Selection mode for a card grid, and running one action across the picks.
 *
 * Shared by the images grid and the documents grid — the two differ only in
 * which store method each id is handed to, and in what `onRemoved` splices.
 */
export function useBulkSelection() {
  const { t } = useI18n();
  const notifier = useNotifierStore();

  /** Whether the grid is in selection mode. Off until the toggle turns it on. */
  const selecting = ref(false);

  /**
   * Ids, like the admin tables' `selected: number[]` — theirs is keyed by id
   * implicitly, through Vuetify's default item-value; here it is explicit.
   */
  const selected = ref<number[]>([]);

  const bulkInFlight = ref(false);

  function togglePick(id: number) {
    selected.value = selected.value.includes(id)
      ? selected.value.filter((picked) => picked !== id)
      : [...selected.value, id];
  }

  function clear() {
    selected.value = [];
  }

  /** Leaving the mode drops the selection with it; nothing else would. */
  function toggleSelecting() {
    selecting.value = !selecting.value;
    if (!selecting.value) clear();
  }

  /**
   * Run one action across the selection.
   *
   * There is no batch endpoint, so each id is its own request. allSettled
   * rather than all: one rejection must not abandon the rest, and the count of
   * failures is what gets reported — the same shape as the admin tables.
   *
   * Where this deliberately parts company with them: they finish by reloading,
   * which is also how their selection gets cleared. Reloading a card grid would
   * throw away every page scrolled so far and jump the viewport to the top, so
   * the rows that succeeded are handed back to the caller to splice out
   * locally, and the selection is cleared by hand.
   */
  async function runBulk(
    action: (id: number) => Promise<unknown>,
    { successKey, failureKey, onRemoved }: RunBulkOptions,
  ) {
    // Copied before the await: the array is emptied below, and the admin
    // version copies for the same reason.
    const ids = [...selected.value];

    bulkInFlight.value = true;
    try {
      const results = await Promise.allSettled(ids.map((id) => action(id)));
      const failed = results.filter((r) => r.status === "rejected").length;

      onRemoved(ids.filter((_, index) => results[index]!.status === "fulfilled"));

      clear();

      if (failed > 0)
        notifier.notify(t(failureKey, { count: failed }), "error");
      else notifier.notify(t(successKey, { count: ids.length }));
    } finally {
      bulkInFlight.value = false;
    }
  }

  return {
    selecting,
    selected,
    bulkInFlight,
    togglePick,
    toggleSelecting,
    clear,
    runBulk,
  };
}
