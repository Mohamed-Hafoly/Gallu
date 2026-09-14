import { afterEach, describe, expect, it } from "vitest";
import i18n from "@/plugins/i18n";

function t (count: number) {
  return i18n.global.t("documents.imageCount", count, { named: { count } })
}

afterEach(() => {
  i18n.global.locale.value = "ar";
});

describe("Arabic pluralization", () => {
  // Regression: vue-i18n's default rule knows three forms and sends every count
  // above one to index 2, so a document with four images rendered "صورتان"
  // ("two images"). Arabic has six CLDR categories.
  it.each([
    [0, "لا توجد صور"],
    [1, "صورة واحدة"],
    [2, "صورتان"],
    [3, "3 صور"],
    [4, "4 صور"],
    [10, "10 صور"],
    [11, "11 صورة"],
    [99, "99 صورة"],
    [100, "100 صورة"],
  ])("renders %i as %s", (count, expected) => {
    i18n.global.locale.value = "ar";
    expect(t(count)).toBe(expected);
  });
});

describe("English pluralization", () => {
  it.each([
    [0, "no images"],
    [1, "1 image"],
    [2, "2 images"],
    [4, "4 images"],
  ])("renders %i as %s", (count, expected) => {
    i18n.global.locale.value = "en";
    expect(t(count)).toBe(expected);
  });
});
