import { useI18n } from "vue-i18n";
import { createVuetify } from "vuetify";
import { createVueI18nAdapter } from "vuetify/locale/adapters/vue-i18n";
import i18n from "./i18n";
import "@mdi/font/css/materialdesignicons.css";
import "../styles/layers.css";
import "vuetify/styles";

export default createVuetify({
  locale: {
    adapter: createVueI18nAdapter({ i18n, useI18n }),
    rtl: { ar: true },
  },
  theme: {
    // utilities: false,
    defaultTheme: "dark",

    variations: {
      colors: ["primary", "surface", "tertiary"],
      lighten: 0,
      darken: 4,
    },
    themes: {
      dark: {
        colors: {
          background: "#040404",
          primary: "#549596",
          surface: "#155354",
          tertiary: "#EDC1DE",
          warning: "#FFA93F",
          error: "#F87171",
        },
      },
    },
  },
  defaults: {
    VTextField: {
      variant: "outlined",
      color: "tertiary",
    },

    VSelect: {
      variant: "outlined",
      color: "tertiary",
    },

    // The two progress components deliberately differ: bars are primary,
    // reading as page-level chrome, while spinners are tertiary to match the
    // form-control accent above — most of them sit inside buttons.
    //
    // Set here so every call site resolves from one place. A prop still wins
    // where an instance must differ: see the on-tertiary spinners on the two
    // buttons that themselves have a tertiary background.
    VProgressLinear: {
      color: "primary",
    },

    VProgressCircular: {
      color: "tertiary",
      // Was repeated on all ten button loaders; defaulting it also normalises
      // the v-img placeholders, which were on Vuetify's thicker default of 4.
      width: 3,
    },
  },
  display: {
    mobileBreakpoint: "md",
    thresholds: {
      xs: 0,
      sm: 600,
      md: 840,
      lg: 1145,
      xl: 1545,
      xxl: 2138,
    },
  },
});
