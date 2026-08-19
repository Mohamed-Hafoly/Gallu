import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useUserStore } from "@/stores/user";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const mockedApi = vi.mocked(api);

const user = {
  id: 1,
  name: "Ada Lovelace",
  email: "ada@example.com",
  avatar_url: "/storage/1/ada.jpg",
  avatar_thumb_url: "/storage/1/conversions/ada-thumb.jpg",
  has_avatar: true,
  default_avatar_url: "/images/default-avatar.jpg",
  created_at: "2026-08-01T10:00:00Z",
  updated_at: "2026-08-15T10:00:00Z",
  is_super_admin: false,
  role: "member" as const,
};

/** Reads a FormData field back as a plain value for assertions. */
function field(body: unknown, key: string) {
  return (body as FormData).get(key);
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("fetchUsers", () => {
  it("returns the rows and the unfiltered total the table's footer needs", async () => {
    mockedApi.get.mockResolvedValue({
      data: { data: [user], meta: { total: 42 } },
    });

    await expect(
      useUserStore().fetchUsers({ page: 1, per_page: 10 }),
    ).resolves.toEqual({ items: [user], total: 42 });
  });

  it("passes paging, sorting and search through as query params", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } });

    await useUserStore().fetchUsers({
      page: 3,
      per_page: 25,
      sort_by: "created_at",
      sort_order: "desc",
      search: "ada",
    });

    expect(mockedApi.get).toHaveBeenCalledWith("/api/users", {
      params: {
        page: 3,
        per_page: 25,
        sort_by: "created_at",
        sort_order: "desc",
        search: "ada",
      },
    });
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.get.mockRejectedValue(new Error("boom"));

    await expect(
      useUserStore().fetchUsers({ page: 1, per_page: 10 }),
    ).rejects.toThrow("boom");
  });
});

describe("createUser", () => {
  const payload = {
    name: "Ada Lovelace",
    email: "ada@example.com",
    password: "password123",
    passwordConfirmation: "password123",
    isSuperAdmin: false,
  };

  // Multipart so the optional avatar can ride along, but with no `_method`
  // spoofing — the create route is already POST.
  it("posts the snake_cased fields the API expects, without _method", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await expect(useUserStore().createUser(payload)).resolves.toEqual(user);

    const [url, body] = mockedApi.post.mock.calls[0];
    expect(url).toBe("/api/users");
    expect(field(body, "name")).toBe("Ada Lovelace");
    expect(field(body, "email")).toBe("ada@example.com");
    expect(field(body, "password")).toBe("password123");
    expect(field(body, "password_confirmation")).toBe("password123");
    expect(field(body, "is_super_admin")).toBe("0");
    expect(field(body, "_method")).toBeNull();
  });

  it("carries the super-admin flag when set", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().createUser({ ...payload, isSuperAdmin: true });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "is_super_admin")).toBe("1");
  });

  it("attaches a picked avatar", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });
    const avatar = new File(["x"], "me.jpg", { type: "image/jpeg" });

    await useUserStore().createUser({ ...payload, avatar });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "avatar")).toBe(avatar);
  });

  it("omits the avatar when none was picked", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().createUser({ ...payload, avatar: null });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "avatar")).toBeNull();
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.post.mockRejectedValue(new Error("boom"));

    await expect(useUserStore().createUser(payload)).rejects.toThrow("boom");
  });
});

describe("updateUser", () => {
  it("posts multipart spoofing PATCH, since the route is PATCH", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await expect(
      useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com" }),
    ).resolves.toEqual(user);

    const [url, body] = mockedApi.post.mock.calls[0];
    expect(url).toBe("/api/users/1");
    expect(field(body, "_method")).toBe("PATCH");
    expect(field(body, "name")).toBe("Ada L.");
    expect(field(body, "email")).toBe("ada@example.com");
  });

  it("omits the avatar fields when neither was touched", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com", avatar: null });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "avatar")).toBeNull();
    expect(field(body, "remove_avatar")).toBeNull();
  });

  // Omitted rather than sent as false: the backend leaves the flag alone when
  // the field is absent, which is what editing your own row relies on.
  it("omits the super-admin flag when it is not passed", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com" });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "is_super_admin")).toBeNull();
  });

  it.each([
    [true, "1"],
    [false, "0"],
  ])("sends isSuperAdmin %s as the string %s", async (value, expected) => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com", isSuperAdmin: value });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "is_super_admin")).toBe(expected);
  });

  it("attaches a picked avatar file", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });
    const avatar = new File(["x"], "me.jpg", { type: "image/jpeg" });

    await useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com", avatar });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "avatar")).toBe(avatar);
  });

  it("sends remove_avatar as the string the backend parses", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: user } });

    await useUserStore().updateUser(1, { name: "Ada L.", email: "ada@example.com", removeAvatar: true });

    const [, body] = mockedApi.post.mock.calls[0];
    expect(field(body, "remove_avatar")).toBe("1");
  });
});

describe("deleteUser", () => {
  it("deletes by id", async () => {
    mockedApi.delete.mockResolvedValue({ status: 204 });

    await useUserStore().deleteUser(3);

    expect(mockedApi.delete).toHaveBeenCalledWith("/api/users/3");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.delete.mockRejectedValue(new Error("boom"));

    await expect(useUserStore().deleteUser(3)).rejects.toThrow("boom");
  });
});
