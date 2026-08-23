import { useI18n } from "vue-i18n";

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
// Averages, not calendar-exact: these only pick which unit to report, and a
// boundary case rounds to the neighbouring "N months ago" either way.
const MONTH = 30.44 * DAY;
const YEAR = 365.25 * DAY;

/** Largest unit that fits, so 90 minutes reads as "1 hour ago". */
const UNITS: [limit: number, size: number, unit: Intl.RelativeTimeFormatUnit][] =
  [
    [HOUR, MINUTE, "minute"],
    [DAY, HOUR, "hour"],
    [MONTH, DAY, "day"],
    [YEAR, MONTH, "month"],
    [Number.POSITIVE_INFINITY, YEAR, "year"],
  ];

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

  /**
   * How long ago a timestamp was, as "5 minutes ago" / "قبل 5 دقائق".
   *
   * Intl.RelativeTimeFormat rather than i18n plural keys: Arabic has six plural
   * categories plus a dual form, and Intl gets دقيقة/دقائق and يومين/أيام right
   * on its own. `numeric: "always"` is deliberate — the "auto" default returns
   * "yesterday"/"last month" instead of the "1 day ago" form we want.
   *
   * Computed on call, not reactive to the passing of time: the gallery
   * re-renders on every refetch, which is often enough for an upload date.
   */
  function formatRelative(value?: string | null) {
    if (!value) return t("common.emptyValue");

    const elapsed = Date.now() - new Date(value).getTime();

    // Also catches a negative elapsed: clock skew between the server row and
    // the browser can put a fresh upload slightly in the future, and "in 3
    // seconds" would be nonsense.
    if (elapsed < MINUTE) return t("common.lessThanAMinuteAgo");

    const [, size, unit] = UNITS.find(([limit]) => elapsed < limit)!;

    return new Intl.RelativeTimeFormat(locale.value, {
      numeric: "always",
    }).format(-Math.floor(elapsed / size), unit);
  }

  return { formatDateTime, formatRelative };
}
