import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import router from "@/plugins/router";
import { useAuthStore } from "@/stores/auth";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));

// The real router pulls in vue-router/auto-routes and a browser history;
// the store only ever calls `replace`, so a stub is enough.
vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const mockedApi = vi.mocked(api);
const mockedRouter = vi.mocked(router);

const user = { id: 1, name: "John Doe", email: "johndoe@example.com" };

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("login", () => {
  it("requests the CSRF cookie before posting credentials", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: user } });
    mockedApi.post.mockResolvedValue({});

    await useAuthStore().login({ email: user.email, password: "12345678" });

    // Ordering is load-bearing: Sanctum rejects the POST without the cookie first.
    const csrfOrder = mockedApi.get.mock.invocationCallOrder[0];
    const loginOrder = mockedApi.post.mock.invocationCallOrder[0];

    expect(mockedApi.get).toHaveBeenCalledWith("/sanctum/csrf-cookie");
    expect(mockedApi.post).toHaveBeenCalledWith("/api/login", {
      email: user.email,
      password: "12345678",
    });
    expect(csrfOrder).toBeLessThan(loginOrder);
  });

  it("populates the user and redirects home on success", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: user } });
    mockedApi.post.mockResolvedValue({});

    const store = useAuthStore();
    await store.login({ email: user.email, password: "12345678" });

    expect(store.user).toEqual(user);
    expect(mockedRouter.replace).toHaveBeenCalledWith({ name: "home" });
  });

  it("rethrows on bad credentials and leaves the session empty", async () => {
    mockedApi.get.mockResolvedValue({});
    mockedApi.post.mockRejectedValue(new Error("422"));

    const store = useAuthStore();
    await expect(
      store.login({ email: user.email, password: "wrong" }),
    ).rejects.toThrow();

    expect(store.user).toBeNull();
    expect(mockedRouter.replace).not.toHaveBeenCalled();
  });
});

describe("fetchUser", () => {
  it("stores the unwrapped user from the data envelope", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: user } });

    const store = useAuthStore();
    await store.fetchUser();

    expect(store.user).toEqual(user);
  });

  it("clears the session instead of throwing when the request fails", async () => {
    mockedApi.get.mockRejectedValue(new Error("500"));

    const store = useAuthStore();
    await expect(store.fetchUser()).resolves.toBeUndefined();
    expect(store.user).toBeNull();
  });
});

describe("logout", () => {
  it("clears the session and returns to login", async () => {
    mockedApi.get.mockResolvedValue({ data: { data: user } });
    mockedApi.post.mockResolvedValue({});

    const store = useAuthStore();
    await store.fetchUser();
    expect(store.user).not.toBeNull();

    await store.logout();

    expect(mockedApi.post).toHaveBeenCalledWith("/api/logout");
    expect(store.user).toBeNull();
    expect(mockedRouter.replace).toHaveBeenCalledWith({ name: "login" });
  });
});
