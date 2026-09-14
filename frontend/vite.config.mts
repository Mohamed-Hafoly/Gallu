import { fileURLToPath, URL } from "node:url";
import tailwindcss from "@tailwindcss/vite";
import Vue from "@vitejs/plugin-vue";
import Fonts from "unplugin-fonts/vite";
import Components from "unplugin-vue-components/vite";
import Vuetify, { transformAssetUrls } from "vite-plugin-vuetify";
import { defineConfig } from "vitest/config";
import VueRouter from "vue-router/vite";

export default defineConfig({
  plugins: [
    tailwindcss(),
    VueRouter({}),
    Vue({
      template: { transformAssetUrls },
    }),
    Vuetify({
      autoImport: true,
      styles: {
        configFile: "src/styles/settings.scss",
      },
    }),
    Components(),

    Fonts({
      fontsource: {
        families: [
          {
            name: "Roboto Mono",
            weights: [400, 700],
          },
          {
            name: "Roboto",
            weights: [100, 300, 400, 500, 700, 900],
            styles: ["normal", "italic"],
          },
          {
            name: "Cairo",
            weights: [200, 300, 400, 500, 600, 700, 800, 900],
          },
        ],
      },
    }),
  ],
  define: { "process.env": {} },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("src", import.meta.url)),
    },
    extensions: [".js", ".json", ".jsx", ".mjs", ".ts", ".tsx", ".vue"],
  },
  server: {
    port: 3000,
  },
  test: {
    environment: "jsdom",
    setupFiles: ["./vitest.setup.ts"],
    include: ["src/**/__tests__/**/*.spec.ts"],
    server: {
      deps: {
        // Vuetify components import .css directly; those must be processed by
        // Vite rather than externalised and imported natively by Node.
        inline: ["vuetify"],
      },
    },
  },
});
