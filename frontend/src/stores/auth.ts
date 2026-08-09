import type { User } from "@/types/user";
import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/plugins/axios";
import router from "@/plugins/router";

export const useAuthStore = defineStore("auth", () => {
  const user = ref<User | null>(null);

  function clearSession() {
    user.value = null;
  }

  async function fetchUser() {
    try {
      const { data } = await api.get("/api/user");
        user.value = data.data;
    } catch {
      clearSession();
    }
  }

  async function login(credentials: { email: string; password: string }) {
    await api.get("/sanctum/csrf-cookie");
    await api.post("/api/login", credentials);
    await fetchUser();

    router.replace({ name: "home" });
  }

  async function register(payload: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) {
    await api.get("/sanctum/csrf-cookie");
    await api.post("/api/register", payload);
    await fetchUser();

    router.replace({ name: "home" });
  }

  async function logout() {
    await api.post("/api/logout");
    clearSession();
    router.replace({ name: "login" });
  }

  async function updateProfile(payload: { name: string; email: string }) {
    await api.put("/api/user/profile-information", payload);
    await fetchUser();
  }

  return {
    user,
    fetchUser,
    clearSession,
    login,
    register,
    logout,
    updateProfile,
  };
});
