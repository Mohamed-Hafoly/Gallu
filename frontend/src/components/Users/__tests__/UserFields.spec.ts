import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import UserFields from "@/components/Users/UserFields.vue";
import i18n from "@/plugins/i18n";

// The component imports useAuthValidationRules, which is inert, but mounting
// pulls in Vuetify's select — which reaches the store chain in sibling specs.
// Mocked here for consistency with the rest of the suite.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

function mountFields(props: Record<string, unknown> = {}) {
  return mountWithPlugins(UserFields, {
    props: { name: "Ada Lovelace", email: "ada@example.com", ...props },
  });
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

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("name and email", () => {
  it("emits an update when the name changes", async () => {
    const wrapper = mountFields();

    await wrapper.findAll("input")[0].setValue("Grace Hopper");

    expect(wrapper.emitted("update:name")?.at(-1)).toEqual(["Grace Hopper"]);
  });

  it("emits an update when the email changes", async () => {
    const wrapper = mountFields();

    await wrapper.findAll("input")[1].setValue("grace@example.com");

    expect(wrapper.emitted("update:email")?.at(-1)).toEqual([
      "grace@example.com",
    ]);
  });

  // Mirrors UserValidationRules::name() on the backend — a four-character
  // minimum, measured trimmed.
  it("rejects a name under four characters", async () => {
    const wrapper = mountFields();

    type(wrapper.findAll("input")[0].element as HTMLInputElement, "Al");
    await flushPromises();

    expect(wrapper.text()).toContain("At least 4 characters");
  });

  it("rejects a malformed email", async () => {
    const wrapper = mountFields();

    type(wrapper.findAll("input")[1].element as HTMLInputElement, "not-an-email");
    await flushPromises();

    expect(wrapper.text()).toContain("Enter a valid email address");
  });
});

describe("the role select", () => {
  // Read off the prop rather than by opening the menu: Vuetify teleports the
  // overlay and does not open it for a synthetic click under jsdom.
  it("offers exactly the two roles the endpoints accept", () => {
    const items = mountFields()
      .findComponent({ name: "VSelect" })
      .props("items");

    // "Team admin" is deliberately absent — it is per-team, and neither
    // endpoint accepts anything but the global boolean. Boolean values, since
    // that is what both stores send as is_super_admin.
    expect(items).toEqual([
      { title: "User", value: false },
      { title: "Admin", value: true },
    ]);
  });

  // The reason roleOptions is a computed: a plain array would freeze t() at
  // whichever locale was active when the component was set up.
  it("relabels its options when the locale changes", async () => {
    const wrapper = mountFields();

    i18n.global.locale.value = "ar";
    await flushPromises();

    const titles = (
      wrapper.findComponent({ name: "VSelect" }).props("items") as {
        title: string;
      }[]
    ).map((item) => item.title);

    expect(titles).toEqual(["مستخدم", "مدير"]);
  });

  it("is enabled by default", () => {
    const input = mountFields().find(".v-select input").element as HTMLInputElement;

    expect(input.disabled).toBe(false);
  });

  // The edit dialog passes this on your own row: the backend refuses a
  // self-promotion, so the control is shown but not offered.
  it("is disabled when roleDisabled is set", () => {
    const input = mountFields({ roleDisabled: true }).find(".v-select input").element as HTMLInputElement;

    expect(input.disabled).toBe(true);
  });
});

describe("the after-email slot", () => {
  // The create dialog's password fields go here so they land between email and
  // role rather than after the whole block.
  it("renders between the email and the role select", () => {
    const wrapper = mountWithPlugins(UserFields, {
      props: { name: "Ada Lovelace", email: "ada@example.com" },
      slots: { "after-email": "<p data-test='slotted'>slotted</p>" },
    });

    const order = [...wrapper.element.querySelectorAll("input, [data-test]")];
    const slotIndex = order.findIndex((el) => Object.hasOwn(el.dataset, "test"));
    const selectIndex = order.findIndex((el) =>
      el.closest(".v-select") !== null,
    );

    expect(slotIndex).toBeGreaterThan(1);
    expect(slotIndex).toBeLessThan(selectIndex);
  });

  it("renders nothing when the slot is unused", () => {
    expect(mountFields().find("[data-test='slotted']").exists()).toBe(false);
  });
});
