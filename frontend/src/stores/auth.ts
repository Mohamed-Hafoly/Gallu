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
    avatar?: File | null;
  }) {
    // Sent as multipart so the optional avatar can ride along. No `_method`
    // spoofing here — unlike the profile update, this route is already POST.
    const formData = new FormData();
    formData.append("name", payload.name);
    formData.append("email", payload.email);
    formData.append("password", payload.password);
    formData.append("password_confirmation", payload.password_confirmation);
    if (payload.avatar) formData.append("avatar", payload.avatar);

    await api.get("/sanctum/csrf-cookie");
    await api.post("/api/register", formData);
    await fetchUser();

    router.replace({ name: "home" });
  }

  async function logout() {
    await api.post("/api/logout");
    clearSession();
    router.replace({ name: "login" });
  }

  async function updateProfile(payload: {
    name: string;
    email: string;
    avatar?: File | null;
    removeAvatar?: boolean;
  }) {
    // Sent as multipart so the avatar can ride along; Fortify's route is PUT,
    // hence the method spoofing (same trick as stores/image.ts).
    const formData = new FormData();
    formData.append("_method", "PUT");
    formData.append("name", payload.name);
    formData.append("email", payload.email);
    if (payload.avatar) formData.append("avatar", payload.avatar);
    if (payload.removeAvatar) formData.append("remove_avatar", "1");

    await api.post("/api/user/profile-information", formData);
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
