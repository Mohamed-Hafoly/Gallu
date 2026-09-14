import { useI18n } from "vue-i18n";

export function useLocalizedName() {
  const { locale } = useI18n();
  return (item: { name_en: string; name_ar: string }) =>
    locale.value === "ar" ? item.name_ar : item.name_en;
}
