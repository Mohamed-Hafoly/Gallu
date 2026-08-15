import type { AxiosAdapter } from "axios";
import { AxiosError } from "axios";
import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import i18n from "@/plugins/i18n";
import router from "@/plugins/router";
import { useAuthStore } from "@/stores/auth";

vi.mock("@/plugins/router", () => ({
  default: {
    replace: vi.fn(),
    currentRoute: { value: { name: "home" } },
  },
}));

const mockedRouter = vi.mocked(router);

/**
 * Stubs axios at the adapter layer rather than mocking `@/plugins/axios`.
 * The interceptor under test only runs if the real instance is used, so
 * mocking the module would test nothing.
 */
function respondWith(status: number, data: unknown) {
  api.defaults.adapter = ((config) =>
    Promise.reject(
      new AxiosError(
        `Request failed with status code ${status}`,
        String(status),
        config as never,
        {},
        { status, statusText: "", headers: {}, config: config as never, data },
      ),
    )) as AxiosAdapter;
}

/** A transport-level failure: rejects with no `response` at all. */
function failWithoutResponse() {
  api.defaults.adapter = ((config) =>
    Promise.reject(
      new AxiosError("Network Error", "ERR_NETWORK", config as never, {}),
    )) as AxiosAdapter;
}

async function captureError(): Promise<AxiosError> {
  try {
    await api.get("/api/anything");
    throw new Error("expected the request to reject");
  } catch (error) {
    return error as AxiosError;
  }
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
});

describe("client errors keep the server's own message", () => {
  it("passes a 422 validation message straight through", async () => {
    respondWith(422, { message: "هذا الحساب غير موجود" });

    expect((await captureError()).userMessage).toBe("هذا الحساب غير موجود");
  });

  it("passes a 429 throttle message through", async () => {
    respondWith(429, { message: "Too many login attempts." });

    expect((await captureError()).userMessage).toBe("Too many login attempts.");
  });
});

describe("server errors are never surfaced verbatim", () => {
  it("replaces a 500 body with the generic translated message", async () => {
    const leak =
      'SQLSTATE[HY000]: General error: 1030 ... SQL: select * from `sessions`';
    respondWith(500, { message: leak, exception: "QueryException", trace: [] });

    const error = await captureError();

    expect(error.userMessage).toBe(i18n.global.t("common.serverError"));
    expect(error.userMessage).not.toContain("SQLSTATE");
  });

  it("still exposes the raw body for debugging, just not as userMessage", async () => {
    respondWith(500, { message: "raw internals" });

    const error = await captureError();

    expect(error.response?.data).toEqual({ message: "raw internals" });
    expect(error.userMessage).not.toBe("raw internals");
  });

  it("uses the active locale for the generic message", async () => {
    i18n.global.locale.value = "ar";
    respondWith(500, { message: "leak" });

    expect((await captureError()).userMessage).toBe(
      i18n.global.t("common.serverError"),
    );
  });
});

describe("transport failures with no response", () => {
  it("falls back to the generic message without throwing a TypeError", async () => {
    // Reading `error.response.data.message` unguarded used to blow up here.
    failWithoutResponse();

    const error = await captureError();

    expect(error.response).toBeUndefined();
    expect(error.userMessage).toBe(i18n.global.t("common.serverError"));
  });
});

describe("401 handling", () => {
  it("clears the session and redirects to login", async () => {
    const store = useAuthStore();
    const clearSession = vi.spyOn(store, "clearSession");
    respondWith(401, { message: "Unauthenticated." });

    await captureError();

    expect(clearSession).toHaveBeenCalled();
    expect(mockedRouter.replace).toHaveBeenCalledWith({ name: "login" });
  });

  it("does not redirect when already on the login page", async () => {
    mockedRouter.currentRoute.value.name = "login";
    respondWith(401, { message: "Unauthenticated." });

    await captureError();

    expect(mockedRouter.replace).not.toHaveBeenCalled();

    mockedRouter.currentRoute.value.name = "home";
  });
});
