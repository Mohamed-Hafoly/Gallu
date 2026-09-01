import type { Team } from "@/types/team";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import TeamMembersDialog from "@/components/Teams/TeamMembersDialog.vue";
import i18n from "@/plugins/i18n";
import { useTeamStore } from "@/stores/team";
import { useUserStore } from "@/stores/user";

// The stores import the axios client, and the router's HMR hook throws under
// vitest — stubbed the same way the other dialog specs do it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const team: Team = {
  id: 3,
  name: "Design",
  description: "The design team",
  creator: "Ada Lovelace",
  members_count: 1,
  deleted_at: null,
};

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
    deleted_at: null,
    role: "member",
    team: { id: 3, name: "Design" },
    ...overrides,
  };
}

/** Mounted closed, then opened, so the watcher that loads members actually runs. */
function mountDialog() {
  return mountWithPlugins(TeamMembersDialog, {
    props: { modelValue: false, team },
  });
}

async function openWith(
  wrapper: ReturnType<typeof mountDialog>,
  members: User[],
) {
  vi.mocked(useTeamStore().fetchMembers).mockResolvedValue(members);
  await wrapper.setProps({ modelValue: true });
  await flushPromises();
}

function picker(wrapper: ReturnType<typeof mountDialog>) {
  return wrapper.findComponent({ name: "MemberPicker" });
}

function rows(wrapper: ReturnType<typeof mountDialog>) {
  return wrapper.findComponent({ name: "MemberRows" });
}

function saveButton() {
  return [...document.body.querySelectorAll("button")].find((button) =>
    button.textContent?.includes("Save"),
  ) as HTMLButtonElement;
}

beforeEach(() => {
  document.body.innerHTML = "";
  vi.clearAllMocks();
  // The app defaults to Arabic; these specs assert against the English copy.
  i18n.global.locale.value = "en";
});

describe("seeding", () => {
  it("loads the existing members into the picker", async () => {
    const wrapper = mountDialog();
    await openWith(wrapper, [makeUser({ role: "admin" })]);

    expect(useTeamStore().fetchMembers).toHaveBeenCalledWith(3);
    expect(picker(wrapper).props("modelValue")).toEqual([
      { user: expect.objectContaining({ id: 7 }), role: "admin" },
    ]);
    expect(document.body.textContent).toContain("Grace Hopper");

    wrapper.unmount();
  });
});

describe("dirty tracking", () => {
  it("keeps Save disabled until something changes", async () => {
    const wrapper = mountDialog();
    await openWith(wrapper, [makeUser()]);

    expect(saveButton().disabled).toBe(true);

    rows(wrapper).vm.$emit("update:role", makeUser(), "admin");
    await flushPromises();

    expect(saveButton().disabled).toBe(false);

    wrapper.unmount();
  });

  // Reordering is not an edit, so the comparison has to be order-independent.
  it("is not dirty when the same members come back in another order", async () => {
    const wrapper = mountDialog();
    const first = makeUser({ id: 7, name: "Grace Hopper" });
    const second = makeUser({ id: 9, name: "Alan Turing" });
    await openWith(wrapper, [first, second]);

    picker(wrapper).vm.$emit("update:modelValue", [
      { user: second, role: "member" },
      { user: first, role: "member" },
    ]);
    await flushPromises();

    expect(saveButton().disabled).toBe(true);

    wrapper.unmount();
  });

  it("re-disables Save once the change is saved", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    await openWith(wrapper, [makeUser()]);

    vi.mocked(teamStore.syncMembers).mockResolvedValue([
      makeUser({ role: "admin" }),
    ]);

    rows(wrapper).vm.$emit("update:role", makeUser(), "admin");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    // Reseeded from the response, so the baseline moved with it.
    expect(saveButton().disabled).toBe(true);

    wrapper.unmount();
  });
});

describe("nothing is written before Save", () => {
  it("issues no request for a local role change or removal", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    const user = makeUser();
    await openWith(wrapper, [user]);

    rows(wrapper).vm.$emit("update:role", user, "admin");
    await flushPromises();
    rows(wrapper).vm.$emit("remove", user);
    await flushPromises();

    expect(teamStore.syncMembers).not.toHaveBeenCalled();

    wrapper.unmount();
  });

  it("sends the whole desired membership on Save", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    const kept = makeUser({ id: 7, name: "Grace Hopper" });
    const dropped = makeUser({ id: 9, name: "Alan Turing" });
    await openWith(wrapper, [kept, dropped]);

    vi.mocked(teamStore.syncMembers).mockResolvedValue([kept]);

    rows(wrapper).vm.$emit("remove", dropped);
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(teamStore.syncMembers).toHaveBeenCalledWith(3, [
      { userId: 7, role: "member" },
    ]);
    expect(wrapper.emitted("changed")).toHaveLength(1);

    wrapper.unmount();
  });
});

describe("cancelling", () => {
  // The page hands the same instance back, so an abandoned edit would otherwise
  // still be sitting there with Save enabled.
  it("discards the edit, and a reopen shows the server's state", async () => {
    const wrapper = mountDialog();
    const teamStore = useTeamStore();
    const user = makeUser();
    await openWith(wrapper, [user]);

    rows(wrapper).vm.$emit("remove", user);
    await flushPromises();
    expect(picker(wrapper).props("modelValue")).toHaveLength(0);

    await wrapper.setProps({ modelValue: false });
    await flushPromises();

    expect(teamStore.syncMembers).not.toHaveBeenCalled();

    await openWith(wrapper, [user]);

    expect(picker(wrapper).props("modelValue")).toHaveLength(1);
    expect(saveButton().disabled).toBe(true);

    wrapper.unmount();
  });
});

describe("adding", () => {
  it("searches through the shared picker and stages the pick", async () => {
    const wrapper = mountDialog();
    const userStore = useUserStore();
    await openWith(wrapper, []);

    vi.mocked(userStore.fetchUsers).mockResolvedValue({
      items: [makeUser({ id: 9, name: "Alan Turing", team: null })],
      total: 1,
    });

    const autocomplete = wrapper.findComponent({ name: "VAutocomplete" });
    autocomplete.vm.$emit("update:search", "al");
    await vi.waitFor(() => expect(userStore.fetchUsers).toHaveBeenCalled());
    await flushPromises();

    autocomplete.vm.$emit("update:modelValue", 9);
    await flushPromises();

    expect(picker(wrapper).props("modelValue")).toEqual([
      { user: expect.objectContaining({ id: 9 }), role: "member" },
    ]);
    expect(useTeamStore().syncMembers).not.toHaveBeenCalled();
    expect(saveButton().disabled).toBe(false);

    wrapper.unmount();
  });
});
