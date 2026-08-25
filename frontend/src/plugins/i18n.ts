import { createI18n, type I18nOptions } from "vue-i18n";
import { ar as vuetifyAr, en as vuetifyEn } from "vuetify/locale";
import ar from "@/locales/ar.json";
import en from "@/locales/en.json";

/**
 * Arabic's CLDR plural categories, in the order a pipe-separated message must
 * list them: "zero | one | two | few | many | other".
 */
const AR_CATEGORIES = ["zero", "one", "two", "few", "many", "other"] as const;

const arabicPlurals = new Intl.PluralRules("ar");

/**
 * Arabic has six plural categories; vue-i18n's default rule only knows three
 * and maps every count above one to index 2. That silently rendered "صورتان"
 * ("two images") for a document holding four.
 *
 * Intl.PluralRules already encodes the real CLDR rules — the same reason
 * useDateFormat leans on Intl.RelativeTimeFormat rather than hand-written keys —
 * so this only translates its category into the message's slot index.
 *
 * A message with fewer than six forms falls back to its last one rather than
 * reading past the end.
 */
function arabicPluralIndex(choice: number, choicesLength: number): number {
  const index = AR_CATEGORIES.indexOf(
    arabicPlurals.select(choice) as (typeof AR_CATEGORIES)[number],
  );

  return index !== -1 && index < choicesLength ? index : choicesLength - 1;
}

const options: I18nOptions = {
  legacy: false,
  locale: "ar",
  fallbackLocale: "en",
  pluralRules: {
    ar: arabicPluralIndex,
  },
  messages: {
    en: { ...en, $vuetify: vuetifyEn },
    ar: { ...ar, $vuetify: vuetifyAr },
  },
};

const i18n = createI18n<false, typeof options>(options);

export default i18n;
