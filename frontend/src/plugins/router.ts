/**
 * router/index.ts
 *
 * Manual routes for ./src/pages/*.vue
 */

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

router.beforeEach((to) => {
  const authStore = useAuthStore();
  const publicRoutes = new Set(["login", "register"]);
  console.log(authStore.user)
	if (!authStore.user && !publicRoutes.has(to.name as string)) {
    console.log('gwيer')
    console.log(to.name);
    return { name: "login" };
  }
  
	if (authStore.user && publicRoutes.has(to.name as string)) {

    console.log("gwer");

    return { name: "home" };
  }
});
export default router;
