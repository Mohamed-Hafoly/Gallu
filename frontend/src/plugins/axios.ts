import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import i18n from "./i18n";
import router from "./router";

declare module "axios" {
  // T and D are unused here but must mirror axios's own `AxiosError<T, D>`
  // declaration verbatim — renaming or omitting them fails the merge with TS2428.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface AxiosError<T = unknown, D = any> {
    /** Message safe to display to the user; set by the response interceptor. */
    userMessage?: string;
  }
}

  const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL,
    withCredentials: true,
    withXSRFToken: true,
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  });

api.interceptors.request.use((config) => {
  config.headers["Accept-Language"] = i18n.global.locale.value;
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      const authStore = useAuthStore();
      authStore.clearSession();

      if (router.currentRoute.value.name !== "login") {
        router.replace({ name: "login" });
      }
    }

    // Only client-error bodies carry deliberate, localized messages. A 5xx body is
    // an implementation detail (a raw stack trace when APP_DEBUG=true) and a failed
    // request has no body at all, so both collapse to a generic string.
    const status = error.response?.status;
    const message = error.response?.data?.message;
    error.userMessage =
      status && status < 500 && typeof message === "string"
        ? message
        : i18n.global.t("common.serverError");

    return Promise.reject(error);
  },
);
export default api;
