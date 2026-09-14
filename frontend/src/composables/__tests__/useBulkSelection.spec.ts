import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import { useBulkSelection } from "@/composables/useBulkSelection";
import i18n from "@/plugins/i18n";
import { useNotifierStore } from "@/stores/notifier";

/** Needs a component instance for useI18n() and the notifier store. */
function mountSelection() {
  let api: ReturnType<typeof useBulkSelection>;

  mountWithPlugins(
    defineComponent({
      setup() {
        api = useBulkSelection();
        return () => null;
      },
    }),
  );

  return api!;
}

beforeEach(() => {
  vi.clearAllMocks();
  // The app defaults to Arabic; these cases assert on English copy.
  i18n.global.locale.value = "en";
});

describe("picking", () => {
  it("adds and removes an id", () => {
    const selection = mountSelection();

    selection.togglePick(1);
    selection.togglePick(2);
    expect(selection.selected.value).toEqual([1, 2]);

    selection.togglePick(1);
    expect(selection.selected.value).toEqual([2]);
  });

  // Leaving the mode drops the selection with it; nothing else would.
  it("clears the picks on the way out of selection mode", () => {
    const selection = mountSelection();

    selection.toggleSelecting();
    selection.togglePick(1);
    selection.toggleSelecting();

    expect(selection.selecting.value).toBe(false);
    expect(selection.selected.value).toEqual([]);
  });
});

describe("running an action across the selection", () => {
  it("calls the action once per pick and hands back what went through", async () => {
    const selection = mountSelection();
    const action = vi.fn().mockResolvedValue(undefined);
    const onRemoved = vi.fn();

    selection.togglePick(1);
    selection.togglePick(2);

    await selection.runBulk(action, {
      successKey: "admin.documents.bulkDeleted",
      failureKey: "admin.documents.bulkDeleteFailed",
      onRemoved,
    });

    expect(action).toHaveBeenCalledTimes(2);
    expect(onRemoved).toHaveBeenCalledWith([1, 2]);
    expect(selection.selected.value).toEqual([]);
    expect(useNotifierStore().notify).toHaveBeenCalledWith(
      "Selected documents deleted (2)",
    );
  });

  // allSettled rather than all: one rejection must not abandon the rest, and
  // only the ids that actually went through may leave the grid.
  it("keeps going past a rejection and reports how many failed", async () => {
    const selection = mountSelection();
    const action = vi
      .fn()
      .mockRejectedValueOnce(new Error("nope"))
      .mockResolvedValue(undefined);
    const onRemoved = vi.fn();

    selection.togglePick(1);
    selection.togglePick(2);

    await selection.runBulk(action, {
      successKey: "admin.documents.bulkDeleted",
      failureKey: "admin.documents.bulkDeleteFailed",
      onRemoved,
    });

    expect(action).toHaveBeenCalledTimes(2);
    expect(onRemoved).toHaveBeenCalledWith([2]);
    expect(useNotifierStore().notify).toHaveBeenCalledWith(
      "Some documents could not be deleted (1)",
      "error",
    );
  });

  // The bar's spinner rides on this, and it must come down even when a request
  // rejects — which allSettled guarantees, but the finally is what proves it.
  it("lowers the in-flight flag when it is done", async () => {
    const selection = mountSelection();

    selection.togglePick(1);
    const pending = selection.runBulk(
      vi.fn().mockRejectedValue(new Error("nope")),
      {
        successKey: "admin.documents.bulkDeleted",
        failureKey: "admin.documents.bulkDeleteFailed",
        onRemoved: vi.fn(),
      },
    );

    expect(selection.bulkInFlight.value).toBe(true);
    await pending;
    expect(selection.bulkInFlight.value).toBe(false);
  });
});
