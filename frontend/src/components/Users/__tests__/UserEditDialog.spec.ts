import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import UserEditDialog from "@/components/Users/UserEditDialog.vue";
import i18n from "@/plugins/i18n";
import { useUserStore } from "@/stores/user";

// This dialog reads the auth store to work out whose row it is, and that store
// imports both the axios client and the router — whose HMR hook throws under
// vitest. Stubbed the same way profile.spec.ts does it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const target: User = {
  id: 7,
  name: "Ada Lovelace",
  email: "ada@example.com",
  avatar_url: "http://localhost/images/default-avatar.jpg",
  avatar_thumb_url: "http://localhost/images/default-avatar.jpg",
  has_avatar: false,
  default_avatar_url: "http://localhost/images/default-avatar.jpg",
  created_at: "2026-08-01T10:00:00Z",
  updated_at: "2026-08-15T10:00:00Z",
  is_super_admin: false,
  role: "member",
};

/** Signed in as a different super-admin unless a spec says otherwise. */
function mountDialog(user: User = target, signedInId = 1) {
  return mountWithPlugins(
    UserEditDialog,
    { props: { modelValue: true, user } },
    { auth: { user: { ...target, id: signedInId, is_super_admin: true } } },
  );
}

/** The dialog teleports to document.body, so it is queried there. */
function editableInputs() {
  const all = [...document.body.querySelectorAll("input")];
  // file picker, then the disabled id / created-at, then name and email.
  return {
    name: all.find((i) => (i as HTMLInputElement).value === "Ada Lovelace") as HTMLInputElement,
    email: all.find((i) => (i as HTMLInputElement).value === "ada@example.com") as HTMLInputElement,
  };
}

function saveButton() {
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

beforeEach(() => {
  i18n.global.locale.value = "en";
  document.body.innerHTML = "";
});

describe("dirty tracking", () => {
  it("keeps Save disabled until something actually changes", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    expect(saveButton().disabled).toBe(true);

    type(editableInputs().name, "Grace Hopper");
    await flushPromises();

    expect(saveButton().disabled).toBe(false);
    wrapper.unmount();
  });

  // Compared trimmed, because the backend trims before storing — a stray
  // trailing space is a no-op edit.
  it("does not count a whitespace-only change as dirty", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    type(editableInputs().name, "Ada Lovelace  ");
    await flushPromises();

    expect(saveButton().disabled).toBe(true);
    wrapper.unmount();
  });
});

describe("saving", () => {
  it("sends the trimmed fields and the role flag", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    type(editableInputs().name, "  Grace Hopper  ");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(useUserStore().updateUser).toHaveBeenCalledWith(7, {
      name: "Grace Hopper",
      email: "ada@example.com",
      avatar: null,
      removeAvatar: false,
      isSuperAdmin: false,
    });
    wrapper.unmount();
  });

  // The backend refuses to let a super-admin change their own flag, so the
  // field is left out of the request entirely rather than sent and rejected.
  it("omits the role flag when editing yourself", async () => {
    const wrapper = mountDialog(target, target.id);
    await flushPromises();

    type(editableInputs().name, "Renamed Self");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(useUserStore().updateUser).toHaveBeenCalledWith(
      7,
      expect.objectContaining({ isSuperAdmin: undefined }),
    );
    wrapper.unmount();
  });
});

describe("the role select", () => {
  it("is offered when editing someone else", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    const select = document.body.querySelector(
      ".v-select input",
    ) as HTMLInputElement;

    expect(select.disabled).toBe(false);
    wrapper.unmount();
  });

  // Shown rather than hidden, matching the id/email/created-at fields above it.
  it("is shown but disabled on your own row", async () => {
    const wrapper = mountDialog(target, target.id);
    await flushPromises();

    const select = document.body.querySelector(
      ".v-select input",
    ) as HTMLInputElement;

    expect(select).not.toBeNull();
    expect(select.disabled).toBe(true);
    wrapper.unmount();
  });
});

describe("closing", () => {
  // The page keeps this dialog mounted and hands back the same user object, so
  // without an explicit reset an abandoned edit survives into the next open.
  it("discards an abandoned edit", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    type(editableInputs().name, "Abandoned Edit");
    await flushPromises();

    await wrapper.setProps({ modelValue: false });
    await flushPromises();
    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    const name = [...document.body.querySelectorAll("input")].find(
      (i) => (i as HTMLInputElement).value === "Ada Lovelace",
    );

    expect(name).toBeDefined();
    expect(saveButton().disabled).toBe(true);
    wrapper.unmount();
  });
});
