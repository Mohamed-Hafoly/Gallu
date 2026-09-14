import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it } from "vitest";
import { useNotifierStore } from "@/stores/notifier";

beforeEach(() => {
  setActivePinia(createPinia());
});

describe("notifier", () => {
  it("starts hidden", () => {
    const notifier = useNotifierStore();

    expect(notifier.visible).toBe(false);
    expect(notifier.message).toBe("");
  });

  it("raises the snackbar with the given message", () => {
    const notifier = useNotifierStore();

    notifier.notify("Category created");

    expect(notifier.visible).toBe(true);
    expect(notifier.message).toBe("Category created");
  });

  it("defaults to the success tone", () => {
    const notifier = useNotifierStore();

    notifier.notify("Saved");

    expect(notifier.tone).toBe("success");
  });

  it("carries an error tone when asked", () => {
    const notifier = useNotifierStore();

    notifier.notify("Delete failed", "error");

    expect(notifier.tone).toBe("error");
  });

  // Only one snackbar exists, so a second call replaces the first rather than
  // queueing behind it.
  it("replaces the previous notification", () => {
    const notifier = useNotifierStore();

    notifier.notify("Delete failed", "error");
    notifier.notify("Category restored");

    expect(notifier.message).toBe("Category restored");
    expect(notifier.tone).toBe("success");
    expect(notifier.visible).toBe(true);
  });
});
