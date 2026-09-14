import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import NotFoundPage from "@/pages/[...path].vue";
import i18n from "@/plugins/i18n";

/**
 * The catch-all. It reads no store and fetches nothing, so there is no axios or
 * router to stub — unlike every other page spec here.
 */
function mountPage() {
  return mountWithPlugins(NotFoundPage);
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("the 404 page", () => {
  it("shows the code above the explanation", () => {
    const wrapper = mountPage();
    const [code, explanation] = wrapper.findAll("p");

    // A literal, not a translation key: the numeral is the same either way.
    expect(code!.text()).toBe("404");
    expect(explanation!.text()).toContain("This page doesn't exist");
  });

  /**
   * Cheap, and it is what catches a key added to en.json and forgotten in
   * ar.json — the two files are maintained by hand and the app defaults to ar.
   */
  it("translates the explanation", () => {
    i18n.global.locale.value = "ar";

    expect(mountPage().text()).toContain("هذه الصفحة غير موجودة");
  });
});
