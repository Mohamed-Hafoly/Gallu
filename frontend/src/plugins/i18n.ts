import { createI18n } from "vue-i18n";
import { en, ar } from "vuetify/locale"; 

const messages = {
  ar: {
    $vuetify: { ...ar },
    message: {
      hello: "مرحبا",
      Vuetify: "لصث",
    },
  },
  en: {
    $vuetify: { ...en },
    message: {
      hello: "hello world",
      Vuetify: "Vuetify",
    },
  },
};

export default createI18n({
  legacy: false,
  locale: "ar",
  fallbackLocale: "en",
  messages,
});
