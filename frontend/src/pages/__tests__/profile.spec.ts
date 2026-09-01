import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ProfilePage from "@/pages/(auth)/profile.vue";
import i18n from "@/plugins/i18n";
import { useAuthStore } from "@/stores/auth";

// The page reaches the auth store, which pulls in the axios client and the
// real router — and vue-router's HMR hook throws under vitest. Both are
// stubbed the same way the auth store spec does it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const user: User = {
  id: 1,
  name: "John Doe",
  email: "john@example.com",
  avatar_url: "http://localhost/images/default-avatar.jpg",
  avatar_thumb_url: "http://localhost/images/default-avatar.jpg",
  has_avatar: false,
  default_avatar_url: "http://localhost/images/default-avatar.jpg",
  created_at: "2026-08-01T10:00:00Z",
  updated_at: "2026-08-01T10:00:00Z",
  is_super_admin: false,
  deleted_at: null,
  role: "member",
  team: null,
};

function mountPage(overrides: Partial<User> = {}) {
  return mountWithPlugins(ProfilePage, {}, {
    auth: { user: { ...user, ...overrides } },
  });
}

let wrapper: ReturnType<typeof mountPage>;

/** Skips the avatar picker's hidden file input, which comes first. */
function inputs() {
  const all = wrapper.findAll('input[type="text"], input[type="email"]');
  return { name: all[0], email: all[1] };
}

function buttonByText(text: string) {
  return wrapper
    .findAll("button")
    .find((b) => b.text().includes(text))!;
}

/**
 * Both avatar controls are icon-only divs inside the overlay, so they are
 * matched on their icon rather than a label — which keeps this locale-proof.
 */
function overlayHalves() {
  return wrapper.findAll('.v-avatar [role="button"]');
}

function overlayHalf(icon: string) {
  return overlayHalves().find((d) => d.find(`.${icon}`).exists());
}

const editHalf = () => overlayHalf("mdi-pencil");
const deleteHalf = () => overlayHalf("mdi-delete");

async function startEditing() {
  await buttonByText("Edit").trigger("click");
  await flushPromises();
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

afterEach(() => {
  wrapper?.unmount();
  document.body.innerHTML = "";
});

describe("profile page", () => {
  it("fills the fields from the authenticated user", async () => {
    wrapper = mountPage();
    await flushPromises();

    expect((inputs().name.element as HTMLInputElement).value).toBe("John Doe");
    expect((inputs().email.element as HTMLInputElement).value).toBe(
      "john@example.com",
    );
  });

  it("keeps Confirm disabled until something actually changes", async () => {
    wrapper = mountPage();
    await flushPromises();
    await startEditing();

    expect(buttonByText("Confirm").attributes("disabled")).toBeDefined();

    await inputs().name.setValue("Jane Doe");
    await flushPromises();

    expect(buttonByText("Confirm").attributes("disabled")).toBeUndefined();
  });

  it("does not count a whitespace-only change as dirty", async () => {
    wrapper = mountPage();
    await flushPromises();
    await startEditing();

    await inputs().name.setValue("John Doe  ");
    await flushPromises();

    expect(buttonByText("Confirm").attributes("disabled")).toBeDefined();
  });

  it("shows both avatar overlay halves only while editing", async () => {
    wrapper = mountPage({ has_avatar: true });
    await flushPromises();

    expect(overlayHalves()).toHaveLength(0);

    await startEditing();

    expect(overlayHalves()).toHaveLength(2);
    expect(editHalf()).toBeDefined();
    expect(deleteHalf()).toBeDefined();
  });

  // With nothing to delete, edit is the only region — `flex-1` stretches it
  // over the whole overlay rather than leaving a dead half.
  it("gives the overlay a single edit region on the default avatar", async () => {
    wrapper = mountPage();
    await flushPromises();
    await startEditing();

    expect(overlayHalves()).toHaveLength(1);
    expect(editHalf()).toBeDefined();
    expect(deleteHalf()).toBeUndefined();
  });

  // The halves are divs, not buttons, so they need these to stay operable
  // without a mouse.
  it("keeps both overlay halves keyboard-reachable", async () => {
    wrapper = mountPage({ has_avatar: true });
    await flushPromises();
    await startEditing();

    expect(editHalf()!.attributes("tabindex")).toBe("0");
    expect(editHalf()!.attributes("aria-label")).toBe("Change image");
    expect(deleteHalf()!.attributes("tabindex")).toBe("0");
    expect(deleteHalf()!.attributes("aria-label")).toBe("Remove");
  });

  it("drops the delete half once a new avatar is picked", async () => {
    wrapper = mountPage({ has_avatar: true });
    await flushPromises();
    await startEditing();

    expect(deleteHalf()).toBeDefined();

    await wrapper
      .findComponent({ name: "AvatarPicker" })
      .setValue(new File(["x"], "me.jpg", { type: "image/jpeg" }));
    await flushPromises();

    expect(deleteHalf()).toBeUndefined();
    expect(editHalf()).toBeDefined();
  });

  it("counts a picked avatar alone as dirty", async () => {
    wrapper = mountPage();
    await flushPromises();
    await startEditing();

    expect(buttonByText("Confirm").attributes("disabled")).toBeDefined();

    await wrapper
      .findComponent({ name: "AvatarPicker" })
      .setValue(new File(["x"], "me.jpg", { type: "image/jpeg" }));
    await flushPromises();

    expect(buttonByText("Confirm").attributes("disabled")).toBeUndefined();
  });

  it("counts a pending avatar removal as dirty", async () => {
    wrapper = mountPage({ has_avatar: true });
    await flushPromises();
    await startEditing();

    await deleteHalf()!.trigger("click");
    await flushPromises();

    expect(buttonByText("Confirm").attributes("disabled")).toBeUndefined();
  });

  it("offers no removal while the user is on the default avatar", async () => {
    wrapper = mountPage();
    await flushPromises();
    await startEditing();

    expect(deleteHalf()).toBeUndefined();
  });

  describe("email truncation", () => {
    const longEmail = "thisisrlylongaddress@example.com";
    const truncated = "thisisrlylongaddr…@example.com";

    it("shows the shortened address while read-only", async () => {
      wrapper = mountPage({ email: longEmail });
      await flushPromises();

      expect((inputs().email.element as HTMLInputElement).value).toBe(truncated);
    });

    it("restores the full address once editing starts", async () => {
      wrapper = mountPage({ email: longEmail });
      await flushPromises();
      await startEditing();

      expect((inputs().email.element as HTMLInputElement).value).toBe(longEmail);
    });

    // The truncated string is not a valid address, so it must never reach the
    // validators while the field is only displaying it.
    it("does not report a validation error while read-only", async () => {
      wrapper = mountPage({ email: longEmail });
      await flushPromises();

      expect(wrapper.find(".v-messages__message").exists()).toBe(false);
    });

    // The regression that matters: saving must send the real address, never
    // the ellipsised one that was only ever on screen.
    it("submits the full address after an edit", async () => {
      wrapper = mountPage({ email: longEmail });
      await flushPromises();
      await startEditing();

      await inputs().name.setValue("Jane Doe");
      await flushPromises();

      // jsdom doesn't submit a form from a submit button's click, so the
      // submit event is dispatched directly.
      await wrapper.find("form").trigger("submit");
      await flushPromises();

      const authStore = useAuthStore();
      expect(authStore.updateProfile).toHaveBeenCalledWith(
        expect.objectContaining({ email: longEmail }),
      );
    });
  });

  it("restores the original values on cancel", async () => {
    wrapper = mountPage({ has_avatar: true });
    await flushPromises();
    await startEditing();

    await inputs().name.setValue("ABANDONED");
    await deleteHalf()!.trigger("click");
    await flushPromises();

    await buttonByText("Cancel").trigger("click");
    await flushPromises();

    expect((inputs().name.element as HTMLInputElement).value).toBe("John Doe");

    // Back in edit mode the form must read clean again — the abandoned
    // removal must not survive the cancel.
    await startEditing();
    expect(buttonByText("Confirm").attributes("disabled")).toBeDefined();
  });
});
