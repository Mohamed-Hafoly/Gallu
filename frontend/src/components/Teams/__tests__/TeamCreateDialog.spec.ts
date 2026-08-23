import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import TeamCreateDialog from "@/components/Teams/TeamCreateDialog.vue";
import i18n from "@/plugins/i18n";
import { useTeamStore } from "@/stores/team";
import { useUserStore } from "@/stores/user";

// The stores import the axios client, and the router's HMR hook throws under
// vitest — stubbed the same way the other dialog specs do it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 7,
    name: "Grace Hopper",
    email: "grace@example.com",
    avatar_url: "http://localhost/images/default-avatar.jpg",
    avatar_thumb_url: "http://localhost/images/default-avatar.jpg",
    has_avatar: false,
    default_avatar_url: "http://localhost/images/default-avatar.jpg",
    created_at: "2026-08-01T10:00:00Z",
    updated_at: "2026-08-15T10:00:00Z",
    is_super_admin: false,
    role: "member",
    team: null,
    ...overrides,
  };
}

function mountDialog() {
  return mountWithPlugins(
    TeamCreateDialog,
    { props: { modelValue: true } },
    { auth: { user: makeUser({ id: 1, name: "John Doe" }) } },
  );
}

/** Search, wait out the debounce, then pick the given candidate. */
async function pick(wrapper: ReturnType<typeof mountDialog>, id: number) {
  const autocomplete = wrapper.findComponent({ name: "VAutocomplete" });
  autocomplete.vm.$emit("update:search", "gr");
  await vi.waitFor(() =>
    expect(useUserStore().fetchUsers).toHaveBeenCalled(),
  );
  await flushPromises();

  autocomplete.vm.$emit("update:modelValue", id);
  await flushPromises();
}

function rows(wrapper: ReturnType<typeof mountDialog>) {
  return wrapper.findComponent({ name: "MemberRows" });
}

beforeEach(() => {
  document.body.innerHTML = "";
  vi.clearAllMocks();
  // The app defaults to Arabic; these specs assert against the English copy.
  i18n.global.locale.value = "en";
});

describe("the picked list", () => {
  it("is not rendered until something is picked", async () => {
    const wrapper = mountDialog();
    await flushPromises();

    expect(rows(wrapper).exists()).toBe(false);

    wrapper.unmount();
  });

  it("appears once a user is picked", async () => {
    const wrapper = mountDialog();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [makeUser()],
      total: 1,
    });

    await pick(wrapper, 7);

    expect(rows(wrapper).exists()).toBe(true);
    expect(rows(wrapper).props("users")).toHaveLength(1);
    expect(document.body.textContent).toContain("Grace Hopper");

    wrapper.unmount();
  });

  it("hides again when the last pick is removed", async () => {
    const wrapper = mountDialog();
    const user = makeUser();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [user],
      total: 1,
    });

    await pick(wrapper, 7);
    expect(rows(wrapper).exists()).toBe(true);

    rows(wrapper).vm.$emit("remove", user);
    await flushPromises();

    expect(rows(wrapper).exists()).toBe(false);

    wrapper.unmount();
  });
});

describe("candidates", () => {
  it("offers neither super-admins nor users already picked", async () => {
    const wrapper = mountDialog();
    const userStore = useUserStore();
    vi.mocked(userStore.fetchUsers).mockResolvedValue({
      items: [
        makeUser({ id: 7, name: "Grace Hopper" }),
        makeUser({ id: 8, name: "Root Admin", is_super_admin: true }),
        makeUser({ id: 9, name: "Alan Turing" }),
      ],
      total: 3,
    });

    await pick(wrapper, 7);

    const autocomplete = wrapper.findComponent({ name: "VAutocomplete" });
    autocomplete.vm.$emit("update:search", "an");
    await vi.waitFor(() =>
      expect(userStore.fetchUsers).toHaveBeenCalledTimes(2),
    );
    await flushPromises();

    const titles = (autocomplete.props("items") as { title: string }[]).map(
      (item) => item.title,
    );

    // Grace is picked, Root Admin is a super-admin.
    expect(titles).toEqual(["Alan Turing"]);

    wrapper.unmount();
  });
});

describe("submitting", () => {
  it("sends the picked members with their roles", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [makeUser()],
      total: 1,
    });

    await pick(wrapper, 7);

    // A role change is local only: nothing is written until Create.
    rows(wrapper).vm.$emit("update:role", makeUser(), "admin");
    await flushPromises();
    expect(teamStore.createTeam).not.toHaveBeenCalled();

    const name = [...document.body.querySelectorAll("input")].find(
      (input) => !input.disabled && input.type === "text",
    ) as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      "value",
    )!.set!;
    setter.call(name, "Platform");
    name.dispatchEvent(new Event("input", { bubbles: true }));
    await flushPromises();

    (
      document.body.querySelector("button[type='submit']") as HTMLButtonElement
    ).click();
    await flushPromises();

    expect(teamStore.createTeam).toHaveBeenCalledWith({
      name: "Platform",
      description: null,
      members: [{ userId: 7, role: "admin" }],
    });

    wrapper.unmount();
  });

  it("sends an empty member list when nothing was picked", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    await flushPromises();

    const name = [...document.body.querySelectorAll("input")].find(
      (input) => !input.disabled && input.type === "text",
    ) as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      "value",
    )!.set!;
    setter.call(name, "Platform");
    name.dispatchEvent(new Event("input", { bubbles: true }));
    await flushPromises();

    (
      document.body.querySelector("button[type='submit']") as HTMLButtonElement
    ).click();
    await flushPromises();

    // The store is what drops the key, so the dialog may hand it an empty list.
    expect(teamStore.createTeam).toHaveBeenCalledWith({
      name: "Platform",
      description: null,
      members: [],
    });

    wrapper.unmount();
  });
});
