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
    themes: {
      dark: {
        colors: {
          background: "#040404",
          primary: "#549596",
          surface: "#155354",
          tertiary: "#EDC1DE",
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
