import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import UserCreateDialog from "@/components/Users/UserCreateDialog.vue";
import i18n from "@/plugins/i18n";
import { useUserStore } from "@/stores/user";

// The dialog reaches the store, which imports the axios client, which imports
// the router — and vue-router's HMR hook throws under vitest.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

function mountDialog() {
  return mountWithPlugins(UserCreateDialog, {
    props: { modelValue: true },
  });
}

/** The dialog teleports to document.body, so it is queried there. */
function inputs() {
  // The avatar picker's hidden file input comes first.
  const all = [...document.body.querySelectorAll("input")];
  return {
    file: all[0],
    name: all[1],
    email: all[2],
    password: all[3],
    passwordConfirmation: all[4],
  };
}

function createButton() {
  return document.body.querySelector(
    "button[type='submit']",
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

async function fillValidForm() {
  const { name, email, password, passwordConfirmation } = inputs();
  type(name as HTMLInputElement, "Ada Lovelace");
  type(email as HTMLInputElement, "ada@example.com");
  type(password as HTMLInputElement, "password123");
  type(passwordConfirmation as HTMLInputElement, "password123");
  await flushPromises();
}

beforeEach(() => {
  i18n.global.locale.value = "en";
  document.body.innerHTML = "";
});

describe("submitting", () => {
  it("keeps Create disabled until the form is valid", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    expect(createButton().disabled).toBe(true);

    await fillValidForm();

    expect(createButton().disabled).toBe(false);
    wrapper.unmount();
  });

  it("sends the trimmed fields and the role", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    const { name } = inputs();
    type(name as HTMLInputElement, "  Ada Lovelace  ");
    type(inputs().email as HTMLInputElement, "  ada@example.com  ");
    type(inputs().password as HTMLInputElement, "password123");
    type(inputs().passwordConfirmation as HTMLInputElement, "password123");
    await flushPromises();

    createButton().click();
    await flushPromises();

    // Names and emails are trimmed at submit; passwords deliberately are not,
    // since " hunter2 " is ten characters to the backend.
    expect(useUserStore().createUser).toHaveBeenCalledWith({
      name: "Ada Lovelace",
      email: "ada@example.com",
      password: "password123",
      passwordConfirmation: "password123",
      isSuperAdmin: false,
      avatar: null,
    });
    wrapper.unmount();
  });

  it("rejects a confirmation that does not match", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    type(inputs().password as HTMLInputElement, "password123");
    type(inputs().passwordConfirmation as HTMLInputElement, "different");
    await flushPromises();

    expect(document.body.textContent).toContain("Passwords do not match");
    wrapper.unmount();
  });
});

describe("closing", () => {
  // Every field starts empty, so a stale value would be offered as if typed.
  it("clears the fields for the next open", async () => {
    const wrapper = mountDialog();
    await flushPromises();
    await fillValidForm();

    await wrapper.setProps({ modelValue: false });
    await flushPromises();
    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect((inputs().name as HTMLInputElement).value).toBe("");
    expect((inputs().email as HTMLInputElement).value).toBe("");
    wrapper.unmount();
  });

  // The reset is deferred a tick on purpose: emptying the fields re-runs their
  // rules, so resetting on the same tick is undone and the next open greets you
  // with errors on a form you have not touched.
  it("reopens without validation errors", async () => {
    const wrapper = mountDialog();
    await flushPromises();
    await fillValidForm();

    await wrapper.setProps({ modelValue: false });
    await flushPromises();
    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(document.body.textContent).not.toContain("This field is required");
    wrapper.unmount();
  });
});
