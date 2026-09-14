// Types
import type { App } from "vue";
import { createPinia } from "pinia";
import { useAuthStore } from "@/stores/auth";
import i18n from "./i18n";
/**
 * plugins/index.ts
 *
 * Automatically included in `./src/main.ts`
 */
import router from "./router";
// Plugins
import vuetify from "./vuetify";

export async function registerPlugins(app: App) {
  app.use(vuetify);
  app.use(createPinia());
  app.use(i18n);

  await useAuthStore().fetchUser();

  app.use(router);
}
