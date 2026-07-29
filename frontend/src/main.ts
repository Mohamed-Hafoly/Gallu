/**
 * main.ts
 *
 * Bootstraps Vuetify and other plugins then mounts the App`
 */

// Composables
import { createApp } from "vue";

// Plugins
import { registerPlugins } from "@/plugins";
import router from "@/plugins/router";
import { useAuthStore } from "@/stores/auth";

// Components
import App from "./App.vue";
// Styles
import "unfonts.css";
import "./styles/tailwind.css";
import "./styles/main.scss";

const app = createApp(App);

await registerPlugins(app);

const authStore = useAuthStore();

Promise.all([authStore.fetchUser(), router.isReady()]).then(() => {
  app.mount("#app");
});
