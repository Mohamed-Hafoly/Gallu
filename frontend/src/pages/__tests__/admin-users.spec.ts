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

const { fetchUsers, createUser, updateUser, deleteUser } = vi.hoisted(() => ({
  fetchUsers: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  deleteUser: vi.fn(),
}));

vi.mock("@/stores/user", () => ({
  useUserStore: () => ({ fetchUsers, createUser, updateUser, deleteUser }),
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

function table(wrapper: Wrapper) {
  return wrapper.findComponent({ name: "VDataTableServer" });
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
