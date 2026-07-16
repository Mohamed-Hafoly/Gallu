import { createI18n, type I18nOptions } from "vue-i18n";
import { ar as vuetifyAr, en as vuetifyEn } from "vuetify/locale";
import ar from "@/locales/ar.json";
import en from "@/locales/en.json";

const options: I18nOptions = {
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages: {
    en: { ...en, $vuetify: vuetifyEn },
    ar: { ...ar, $vuetify: vuetifyAr },
  },
};

const i18n = createI18n<false, typeof options>(options);

export default i18n;
