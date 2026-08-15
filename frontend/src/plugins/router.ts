/**
 * router/index.ts
 *
 * Manual routes for ./src/pages/*.vue
 */

import type { RouteLocationRaw, RouteRecordNameGeneric } from "vue-router";
import { createRouter, createWebHistory } from "vue-router";
import { handleHotUpdate, routes } from "vue-router/auto-routes";
// Composables
import { useAuthStore } from "@/stores/auth";

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});
if (import.meta.hot) {
  handleHotUpdate(router);
}

const publicRoutes = new Set(["login", "register"]);

/**
 * Sends signed-out visitors to login, and signed-in visitors away from the
 * public auth pages. Exported so it can be unit tested without driving a real
 * navigation (which would lazy-load every page component).
 */
export function authGuard(to: {
  name?: RouteRecordNameGeneric;
}): RouteLocationRaw | undefined {
  const authStore = useAuthStore();

  if (!authStore.user && !publicRoutes.has(to.name as string)) {
    return { name: "login" };
  }

  if (authStore.user && publicRoutes.has(to.name as string)) {
    return { name: "home" };
  }
}

router.beforeEach(authGuard);
export default router;
