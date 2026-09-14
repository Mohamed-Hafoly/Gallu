import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { withSetup } from "@/__tests__/helpers/withSetup";
import { useDateFormat } from "@/composables/useDateFormat";
import i18n from "@/plugins/i18n";

const NOW = new Date("2026-06-15T12:00:00.000Z");

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const MONTH = 30.44 * DAY;
const YEAR = 365.25 * DAY;

/** An ISO timestamp `elapsed` ms before the frozen clock. */
function ago(elapsed: number) {
  return new Date(NOW.getTime() - elapsed).toISOString();
}

function relative(elapsed: number, locale: "en" | "ar") {
  i18n.global.locale.value = locale;
  return withSetup(() => useDateFormat()).formatRelative(ago(elapsed));
}

beforeAll(() => {
  vi.useFakeTimers();
  vi.setSystemTime(NOW);
});

afterAll(() => {
  vi.useRealTimers();
});

afterEach(() => {
  i18n.global.locale.value = "ar";
});

describe("formatRelative", () => {
  it.each([
    [0, "less than a minute ago"],
    [30 * 1000, "less than a minute ago"],
    [MINUTE, "1 minute ago"],
    [5 * MINUTE, "5 minutes ago"],
    [HOUR, "1 hour ago"],
    [3 * HOUR, "3 hours ago"],
    [DAY, "1 day ago"],
    [4 * DAY, "4 days ago"],
    [MONTH, "1 month ago"],
    [7 * MONTH, "7 months ago"],
    [YEAR, "1 year ago"],
    [2 * YEAR, "2 years ago"],
  ])("formats %i ms ago as %s in English", (elapsed, expected) => {
    expect(relative(elapsed, "en")).toBe(expected);
  });

  // Arabic is the reason this uses Intl.RelativeTimeFormat rather than i18n
  // plural keys: it has a dual form and six plural categories. These cases are
  // what would break if someone replaced Intl with hand-written strings.
  it.each([
    [30 * 1000, "قبل أقل من دقيقة"],
    [MINUTE, "قبل دقيقة واحدة"],
    [5 * MINUTE, "قبل 5 دقائق"],
    [2 * DAY, "قبل يومين"],
    [4 * DAY, "قبل 4 أيام"],
    [2 * YEAR, "قبل سنتين"],
  ])("formats %i ms ago correctly in Arabic", (elapsed, expected) => {
    expect(relative(elapsed, "ar")).toBe(expected);
  });

  it("reports the largest unit that fits, so 90 minutes is an hour", () => {
    expect(relative(90 * MINUTE, "en")).toBe("1 hour ago");
    expect(relative(36 * HOUR, "en")).toBe("1 day ago");
  });

  // Server and browser clocks can disagree by a second or two on a fresh upload;
  // a negative elapsed must not produce "in 3 seconds".
  it("treats a future timestamp as just now", () => {
    const future = new Date(NOW.getTime() + 5000).toISOString();
    i18n.global.locale.value = "en";

    expect(withSetup(() => useDateFormat()).formatRelative(future))
      .toBe("less than a minute ago");
  });

  it("falls back to the empty-value dash when there is no timestamp", () => {
    i18n.global.locale.value = "en";
    const { formatRelative } = withSetup(() => useDateFormat());

    expect(formatRelative(null)).toBe("-");
    expect(formatRelative(undefined)).toBe("-");
  });
});
