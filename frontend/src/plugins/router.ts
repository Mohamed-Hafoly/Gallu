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
  routes: [...routes],
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
  path?: string;
}): RouteLocationRaw | undefined {
  const authStore = useAuthStore();

  if (!authStore.user && !publicRoutes.has(to.name as string)) {
    return { name: "login" };
  }

  if (authStore.user && publicRoutes.has(to.name as string)) {
    return { name: "home" };
  }

  // Defence in depth only: the paths are in the bundle and this runs in the
  // visitor's own browser. The backend policies are what actually deny.
  if (to.path?.startsWith("/admin") && !authStore.user?.is_super_admin) {
    return { name: "home" };
  }
}

router.beforeEach(authGuard);
export default router;
