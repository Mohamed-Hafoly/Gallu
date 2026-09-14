import { afterEach, describe, expect, it } from "vitest";
import { withSetup } from "@/__tests__/helpers/withSetup";
import { useLocalizedName } from "@/composables/useLocalizedName";
import i18n from "@/plugins/i18n";

const category = { name_en: "Furniture", name_ar: "أثاث" };

afterEach(() => {
  i18n.global.locale.value = "ar";
});

describe("useLocalizedName", () => {
  it("returns the Arabic name under the ar locale", () => {
    i18n.global.locale.value = "ar";
    expect(withSetup(() => useLocalizedName())(category)).toBe("أثاث");
  });

  it("returns the English name under the en locale", () => {
    i18n.global.locale.value = "en";
    expect(withSetup(() => useLocalizedName())(category)).toBe("Furniture");
  });

  it("follows a locale change without being re-created", () => {
    i18n.global.locale.value = "ar";
    const localizedName = withSetup(() => useLocalizedName());

    expect(localizedName(category)).toBe("أثاث");

    i18n.global.locale.value = "en";
    expect(localizedName(category)).toBe("Furniture");
  });
});
