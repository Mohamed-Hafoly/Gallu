import type * as AutoRoutes from "vue-router/auto-routes";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { authGuard } from "@/plugins/router";
import { useAuthStore } from "@/stores/auth";

// The guard only reads `authStore.user`, but importing the store pulls in axios
// (and therefore the real interceptor). Stub the transport away.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

// `import.meta.hot` is truthy under Vitest, so router.ts calls handleHotUpdate()
// on import and it throws. Keep the real generated routes, no-op the HMR hook.
vi.mock("vue-router/auto-routes", async (importOriginal) => ({
  ...(await importOriginal<typeof AutoRoutes>()),
  handleHotUpdate: () => {},
}));

const user = { id: 1, name: "John Doe", email: "johndoe@example.com" };
const superAdmin = { ...user, is_super_admin: true };

/** The guard returns a redirect target, or undefined to allow the navigation. */
const guard = (name: string, path?: string) => authGuard({ name, path });

beforeEach(() => {
  setActivePinia(createPinia());
});

describe("signed-out visitors", () => {
  it.each(["gallery", "profile", "settings", "home"])(
    "are redirected from %s to login",
    (name) => {
      expect(guard(name)).toEqual({ name: "login" });
    },
  );

  it.each(["login", "register"])("may reach the public route %s", (name) => {
    expect(guard(name)).toBeUndefined();
  });
});

describe("signed-in visitors", () => {
  beforeEach(() => {
    useAuthStore().user = user as never;
  });

  it.each(["login", "register"])("are bounced off %s to home", (name) => {
    expect(guard(name)).toEqual({ name: "home" });
  });

  it.each(["gallery", "profile", "settings", "home"])(
    "may reach the protected route %s",
    (name) => {
      expect(guard(name)).toBeUndefined();
    },
  );
});

describe("unknown routes", () => {
  it("still gate behind auth when signed out", () => {
    expect(guard("does-not-exist")).toEqual({ name: "login" });
  });
});

// The /admin paths are in the bundle regardless, so this only keeps the UI
// honest; the backend policies are what actually deny a non-super-admin.
describe("the admin section", () => {
  const adminRoutes: [string, string][] = [
    ["admin-categories", "/admin/categories"],
    ["admin-teams", "/admin/teams"],
    ["admin-users", "/admin/users"],
  ];

  it.each(adminRoutes)("sends a signed-in non-admin off %s", (name, path) => {
    useAuthStore().user = user as never;

    expect(guard(name, path)).toEqual({ name: "home" });
  });

  it.each(adminRoutes)("lets a super admin reach %s", (name, path) => {
    useAuthStore().user = superAdmin as never;

    expect(guard(name, path)).toBeUndefined();
  });

  it("still sends a signed-out visitor to login, not home", () => {
    expect(guard("admin-users", "/admin/users")).toEqual({ name: "login" });
  });

  // /admin redirects to the users page, and vue-router resolves that before the
  // guard runs — so in practice the guard sees /admin/users. Kept anyway: the
  // guard reads to.path, and it must gate the bare path on its own merits
  // rather than relying on the redirect having fired first.
  it("gates the bare /admin path for a signed-in non-admin", () => {
    useAuthStore().user = user as never;

    expect(guard("admin-users", "/admin")).toEqual({ name: "home" });
  });

  it("lets a super admin reach the bare /admin path", () => {
    useAuthStore().user = superAdmin as never;

    expect(guard("admin-users", "/admin")).toBeUndefined();
  });
});
