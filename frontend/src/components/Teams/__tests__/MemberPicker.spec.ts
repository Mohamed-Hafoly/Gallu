import type { TeamMemberSelection } from "@/types/team";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import MemberPicker from "@/components/Teams/MemberPicker.vue";
import i18n from "@/plugins/i18n";
import { useUserStore } from "@/stores/user";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
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

function mountPicker(modelValue: TeamMemberSelection[] = []) {
  return mountWithPlugins(MemberPicker, { props: { modelValue } });
}

function autocompleteOf(wrapper: ReturnType<typeof mountPicker>) {
  return wrapper.findComponent({ name: "VAutocomplete" });
}

/** Search, wait out the debounce, then select the given id. */
async function search(wrapper: ReturnType<typeof mountPicker>, term: string) {
  autocompleteOf(wrapper).vm.$emit("update:search", term);
  await vi.waitFor(() => expect(useUserStore().fetchUsers).toHaveBeenCalled());
  await flushPromises();
}

beforeEach(() => {
  document.body.innerHTML = "";
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
});

describe("the picked list", () => {
  it("is not rendered while nothing is picked", () => {
    const wrapper = mountPicker();

    expect(wrapper.findComponent({ name: "MemberRows" }).exists()).toBe(false);

    wrapper.unmount();
  });

  it("renders the rows it is given", () => {
    const wrapper = mountPicker([{ user: makeUser(), role: "admin" }]);

    expect(wrapper.findComponent({ name: "MemberRows" }).exists()).toBe(true);
    // Not teleported, unlike the dialogs that host it — so read the wrapper.
    expect(wrapper.text()).toContain("Grace Hopper");

    wrapper.unmount();
  });

  it("annotates a row only for a team other than the one being edited", () => {
    const inThisTeam = makeUser({ id: 7, team: { id: 3, name: "Design" } });
    const fromElsewhere = makeUser({ id: 9, team: { id: 4, name: "Ops" } });

    const wrapper = mountWithPlugins(MemberPicker, {
      props: {
        teamId: 3,
        modelValue: [
          { user: inThisTeam, role: "member" },
          { user: fromElsewhere, role: "member" },
        ],
      },
    });

    // An existing member would otherwise read "Currently in Design" inside the
    // Design dialog, which says nothing.
    expect(wrapper.text()).not.toContain("Currently in Design");
    expect(wrapper.text()).toContain("Currently in Ops");

    wrapper.unmount();
  });
});

describe("picking", () => {
  /**
   * The regression this component exists for. Vuetify resets its own `search`
   * on selection, which empties `candidates` a tick later — the old dialog
   * derived the picked user from that array in a computed, so it evaporated and
   * the Add button silently did nothing. The user has to be captured
   * synchronously in the watcher instead.
   */
  it("keeps the pick after the autocomplete clears its search", async () => {
    const wrapper = mountPicker();
    const userStore = useUserStore();
    vi.mocked(userStore.fetchUsers).mockResolvedValue({
      items: [makeUser({ id: 9, name: "Alan Turing" })],
      total: 1,
    });

    await search(wrapper, "al");
    autocompleteOf(wrapper).vm.$emit("update:modelValue", 9);
    await flushPromises();

    // What Vuetify does next, and what used to wipe the selection.
    autocompleteOf(wrapper).vm.$emit("update:search", "");
    await flushPromises();

    const emitted = wrapper.emitted("update:modelValue")!.at(-1)![0];

    expect(emitted).toEqual([
      { user: expect.objectContaining({ id: 9 }), role: "member" },
    ]);

    wrapper.unmount();
  });

  it("defaults a new pick to member", async () => {
    const wrapper = mountPicker();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [makeUser()],
      total: 1,
    });

    await search(wrapper, "gr");
    autocompleteOf(wrapper).vm.$emit("update:modelValue", 7);
    await flushPromises();

    const emitted = wrapper.emitted("update:modelValue")!.at(-1)![0] as
      TeamMemberSelection[];

    expect(emitted[0].role).toBe("member");

    wrapper.unmount();
  });
});

describe("candidates", () => {
  it("offers neither super-admins nor users already picked", async () => {
    const wrapper = mountPicker([
      { user: makeUser({ id: 7, name: "Grace Hopper" }), role: "member" },
    ]);

    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [
        makeUser({ id: 7, name: "Grace Hopper" }),
        makeUser({ id: 8, name: "Root Admin", is_super_admin: true }),
        makeUser({ id: 9, name: "Alan Turing" }),
      ],
      total: 3,
    });

    await search(wrapper, "an");

    const titles = (
      autocompleteOf(wrapper).props("items") as { title: string }[]
    ).map((item) => item.title);

    expect(titles).toEqual(["Alan Turing"]);

    wrapper.unmount();
  });

  it("annotates a candidate who already belongs to another team", async () => {
    const wrapper = mountPicker();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [makeUser({ team: { id: 4, name: "Ops" } })],
      total: 1,
    });

    await search(wrapper, "gr");

    const [item] = autocompleteOf(wrapper).props("items") as {
      subtitle: string;
    }[];

    expect(item.subtitle).toContain("Currently in Ops");

    wrapper.unmount();
  });

  it("does not search on a single character", async () => {
    const wrapper = mountPicker();
    const userStore = useUserStore();

    autocompleteOf(wrapper).vm.$emit("update:search", "a");
    await flushPromises();

    expect(userStore.fetchUsers).not.toHaveBeenCalled();

    wrapper.unmount();
  });
});

describe("the empty-state copy", () => {
  /**
   * `no-filter` means the menu lists exactly what the search returned, so it is
   * empty before anything is searched. Saying "no matching users" there would
   * report a failure for a search that never ran.
   */
  it("prompts for more characters before anything has been searched", () => {
    const wrapper = mountPicker();

    expect(autocompleteOf(wrapper).props("noDataText")).toBe(
      "Type at least 2 characters to search",
    );

    wrapper.unmount();
  });

  it("still prompts on a single character, and searches nothing", async () => {
    const wrapper = mountPicker();
    const userStore = useUserStore();

    autocompleteOf(wrapper).vm.$emit("update:search", "a");
    await flushPromises();

    expect(autocompleteOf(wrapper).props("noDataText")).toBe(
      "Type at least 2 characters to search",
    );
    expect(userStore.fetchUsers).not.toHaveBeenCalled();

    wrapper.unmount();
  });

  it("says it is searching while the request is in flight", async () => {
    const wrapper = mountPicker();
    // Held open so the pending state can be observed rather than raced past.
    let release: (page: { items: []; total: number }) => void;
    vi.mocked(useUserStore().fetchUsers).mockReturnValue(
      new Promise((resolve) => {
        release = resolve;
      }),
    );

    await search(wrapper, "an");

    expect(autocompleteOf(wrapper).props("noDataText")).toBe("Searching…");

    release!({ items: [], total: 0 });
    await flushPromises();

    wrapper.unmount();
  });

  it("reports no matches only once a real search came back empty", async () => {
    const wrapper = mountPicker();
    vi.mocked(useUserStore().fetchUsers).mockResolvedValue({
      items: [],
      total: 0,
    });

    await search(wrapper, "zz");

    expect(autocompleteOf(wrapper).props("noDataText")).toBe(
      "No matching users",
    );

    wrapper.unmount();
  });
});
