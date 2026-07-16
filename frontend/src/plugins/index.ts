// Types
import type { App } from "vue";
import { createPinia } from "pinia";
import i18n from "./i18n";
/**
 * plugins/index.ts
 *
 * Automatically included in `./src/main.ts`
 */
import router from "./router";
// Plugins
import vuetify from "./vuetify";

export function registerPlugins(app: App) {
  app.use(vuetify);
  app.use(createPinia());
  app.use(i18n);
  app.use(router);
}
