import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import i18n from "./i18n";
import router from "./router";

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
    return Promise.reject(error);
  },
);
export default api;
