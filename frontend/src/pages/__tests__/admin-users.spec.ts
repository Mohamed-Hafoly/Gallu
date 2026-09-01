import type { UserListParams } from "@/stores/user";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import AdminUsers from "@/pages/admin/users.vue";
import i18n from "@/plugins/i18n";

// The stores import the axios client, and the router's HMR hook throws under
// vitest — stubbed the same way the other page and dialog specs do it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const { fetchUsers, createUser, updateUser, deleteUser, restoreUser } =
  vi.hoisted(() => ({
    fetchUsers: vi.fn(),
    createUser: vi.fn(),
    updateUser: vi.fn(),
    deleteUser: vi.fn(),
    restoreUser: vi.fn(),
  }));

vi.mock("@/stores/user", () => ({
  useUserStore: () => ({
    fetchUsers,
    createUser,
    updateUser,
    deleteUser,
    restoreUser,
  }),
}));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams: vi.fn().mockResolvedValue([]) }),
}));

function user(id: number, overrides: Partial<User> = {}): User {
  return {
    id,
    name: `User ${id}`,
    email: `user${id}@example.com`,
    avatar_url: "/avatar.jpg",
    avatar_thumb_url: "/avatar.jpg",
    has_avatar: false,
    default_avatar_url: "/avatar.jpg",
    created_at: "2026-08-01T10:00:00Z",
    updated_at: "2026-08-15T10:00:00Z",
    is_super_admin: false,
    deleted_at: null,
    role: "member",
    team: { id: 1, name: "Design" },
    ...overrides,
  };
}

async function mountPage() {
  const wrapper = mountWithPlugins(AdminUsers);
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>;

/** The live listing: the first of the page's two tables. */
function table(wrapper: Wrapper) {
  return wrapper.findComponent({ name: "VDataTableServer" });
}

/** The pending-deletion listing: the second. */
function trashTable(wrapper: Wrapper) {
  return wrapper.findAllComponents({ name: "VDataTableServer" })[1];
}

/**
 * The two tables are told apart by the param, not by call order - they are
 * fetched concurrently through loadBoth().
 */
function callsFor(trashed: "only" | undefined): UserListParams[] {
  return fetchUsers.mock.calls
    .map((call) => call[0] as UserListParams)
    .filter((params) => params.trashed === trashed);
}

/** The listing is server-side, so this asserts the params the page sends. */
function lastParams(): UserListParams {
  return fetchUsers.mock.calls.at(-1)![0] as UserListParams;
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
  fetchUsers.mockResolvedValue({ items: [user(1)], total: 1 });
});

/**
 * The team column sorts on the team's name. The team lives in the role pivot
 * rather than on `users`, which is why it was sortable: false — but the listing
 * already selects the name through withTeamAssignment(), so the backend orders
 * by that alias.
 */
describe("team column", () => {
  function teamHeader(headers: unknown) {
    return (headers as { key: string; sortable?: boolean }[]).find(
      (header) => header.key === "team",
    )!;
  }

  it("is sortable", async () => {
    const wrapper = await mountPage();

    expect(teamHeader(table(wrapper).props("headers")).sortable).toBe(true);
  });

  /**
   * Asserted alongside the header flag rather than instead of it: this emits a
   * synthetic event, so it would pass whatever the header said.
   */
  it("sends the team sort to the endpoint", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 1,
      itemsPerPage: 10,
      sortBy: [{ key: "team", order: "asc" }],
    });
    await flushPromises();

    expect(lastParams()).toMatchObject({ sort_by: "team", sort_order: "asc" });
  });
});

/**
 * Users were hard-deleted until they joined the rest of the soft-deleted
 * resources, so this whole area is new coverage - the page previously had none
 * for delete at all.
 */
describe("pending deletion table", () => {
  const binned = { deleted_at: "2026-09-01T10:00:00Z" };

  function bothSides() {
    fetchUsers.mockImplementation((params: UserListParams) =>
      Promise.resolve(
        params.trashed === "only"
          ? { items: [user(2, binned)], total: 1 }
          : { items: [user(1)], total: 1 },
      ),
    );
  }

  it("asks for both sides of the soft delete on mount", async () => {
    bothSides();

    await mountPage();

    expect(callsFor(undefined)).toHaveLength(1);
    expect(callsFor("only")).toHaveLength(1);
  });

  it("reports when each binned user was deleted", async () => {
    bothSides();

    const wrapper = await mountPage();
    const keys = (
      trashTable(wrapper).props("headers") as { key: string }[]
    ).map((header) => header.key);

    expect(keys).toContain("deleted_at");
  });

  it("pages the two tables independently", async () => {
    bothSides();

    const wrapper = await mountPage();

    trashTable(wrapper).vm.$emit("update:options", {
      page: 3,
      itemsPerPage: 10,
      sortBy: [],
    });
    await flushPromises();

    expect(callsFor("only").at(-1)).toMatchObject({ page: 3 });
    // The live side is untouched by the other table's paging.
    expect(callsFor(undefined).at(-1)).toMatchObject({ page: 1 });
  });

  it("restores a user and refetches both tables", async () => {
    bothSides();
    restoreUser.mockResolvedValue(user(2));

    const wrapper = await mountPage();
    fetchUsers.mockClear();

    await trashTable(wrapper).find(".mdi-restore").trigger("click");
    await flushPromises();

    expect(restoreUser).toHaveBeenCalledWith(2);
    // A restore moves the row between the tables, so both are refetched.
    expect(callsFor(undefined)).toHaveLength(1);
    expect(callsFor("only")).toHaveLength(1);
  });

  it("soft deletes from the live table and refetches both", async () => {
    bothSides();
    deleteUser.mockResolvedValue(undefined);

    const wrapper = await mountPage();
    await table(wrapper).find(".mdi-delete").trigger("click");
    await flushPromises();

    fetchUsers.mockClear();
    wrapper.findComponent({ name: "ConfirmDialog" }).vm.$emit("confirm");
    await flushPromises();

    expect(deleteUser).toHaveBeenCalledWith(1);
    expect(callsFor(undefined)).toHaveLength(1);
    expect(callsFor("only")).toHaveLength(1);
  });
});
