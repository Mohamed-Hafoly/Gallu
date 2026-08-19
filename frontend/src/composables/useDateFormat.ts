import { useI18n } from "vue-i18n";

export function useDateFormat() {
  const { locale, t } = useI18n();

  /**
   * Locale-aware date and time, falling back to the empty-value dash when the
   * timestamp is absent. Shared by the admin tables and the user edit dialog so
   * a created-at reads the same wherever it appears.
   */
  function formatDateTime(value?: string | null) {
    if (!value) return t("common.emptyValue");

    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(value));
  }

  return { formatDateTime };
}
