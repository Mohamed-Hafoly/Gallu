import type { Category } from "@/types/category";
import { flushPromises } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import CategoryEditDialog from "@/components/Categories/CategoryEditDialog.vue";
import i18n from "@/plugins/i18n";

// The component reaches the store, which imports the axios client, which
// imports the router — and vue-router's HMR hook throws under vitest. Mocking
// the client severs that chain, as the store specs already do.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const category: Category = {
  id: 2,
  name_en: "Tools",
  name_ar: "أدوات",
  creator: "John Doe",
  deleted_at: null,
};

/** The dialog teleports to document.body, so it is queried there. */
function nameInputs() {
  const inputs = [...document.body.querySelectorAll("input")];
  // id and creator come first and are read-only.
  return { english: inputs[2], arabic: inputs[3] };
}

function saveButton() {
  return [...document.body.querySelectorAll("button")].find((b) =>
    b.querySelector(".mdi-content-save"),
  ) as HTMLButtonElement;
}

/** Vue only reacts to input events, not to assigning `.value` directly. */
function type(input: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    "value",
  )!.set!;
  setter.call(input, value);
  input.dispatchEvent(new Event("input", { bubbles: true }));
}

function mountDialog() {
  return mountWithPlugins(CategoryEditDialog, {
    props: { category, modelValue: true },
  });
}

let wrapper: ReturnType<typeof mountDialog>;

beforeEach(() => {
  i18n.global.locale.value = "en";
});

afterEach(() => {
  wrapper?.unmount();
  document.body.innerHTML = "";
});

describe("CategoryEditDialog", () => {
  it("fills both name fields from the category prop", async () => {
    wrapper = mountDialog();
    await flushPromises();

    expect(nameInputs().english.value).toBe("Tools");
    expect(nameInputs().arabic.value).toBe("أدوات");
  });

  it("keeps Save disabled until a name actually changes", async () => {
    wrapper = mountDialog();
    await flushPromises();

    expect(saveButton().disabled).toBe(true);

    type(nameInputs().english, "Toolset");
    await flushPromises();

    expect(saveButton().disabled).toBe(false);
  });

  it("does not count a whitespace-only change as dirty", async () => {
    wrapper = mountDialog();
    await flushPromises();

    type(nameInputs().english, "Tools  ");
    await flushPromises();

    expect(saveButton().disabled).toBe(true);
  });

  // Regression guard: the dialog stays mounted between openings, and the page
  // passes the same object back, so a watcher on `category` alone never fires.
  it("discards an abandoned edit when reopened", async () => {
    wrapper = mountDialog();
    await flushPromises();

    type(nameInputs().english, "ABANDONED");
    await flushPromises();

    await wrapper.setProps({ modelValue: false });
    await flushPromises();

    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(nameInputs().english.value).toBe("Tools");
    expect(saveButton().disabled).toBe(true);
  });
});
