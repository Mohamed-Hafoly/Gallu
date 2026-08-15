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

/** The guard returns a redirect target, or undefined to allow the navigation. */
const guard = (name: string) => authGuard({ name });

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
