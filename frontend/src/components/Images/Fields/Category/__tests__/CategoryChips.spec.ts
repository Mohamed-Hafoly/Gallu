import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import CategoryChips from "@/components/Images/Fields/Category/CategoryChips.vue";
import i18n from "@/plugins/i18n";

const categories = [
  { id: 1, name_en: "Books", name_ar: "كتب" },
  { id: 2, name_en: "Art", name_ar: "فن" },
  { id: 3, name_en: "Sports", name_ar: "رياضة" },
];

function mountChips(props: Record<string, unknown> = {}) {
  return mountWithPlugins(CategoryChips, {
    props: { items: categories, ...props },
  });
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("localisation", () => {
  it("renders English names under the en locale", () => {
    const text = mountChips({ limit: 0 }).text();

    expect(text).toContain("Books");
    expect(text).not.toContain("كتب");
  });

  it("renders Arabic names under the ar locale", () => {
    i18n.global.locale.value = "ar";
    const text = mountChips({ limit: 0 }).text();

    expect(text).toContain("كتب");
    expect(text).not.toContain("Books");
  });
});

describe("overflow behaviour", () => {
  it("shows every chip and no counter when limit is 0", () => {
    const text = mountChips({ limit: 0 }).text();

    expect(text).toContain("Books");
    expect(text).toContain("Art");
    expect(text).toContain("Sports");
    expect(text).not.toContain("+");
  });

  it("caps the visible chips and shows a +N counter for the remainder", () => {
    const text = mountChips({ limit: 2 }).text();

    expect(text).toContain("Books");
    expect(text).toContain("Art");
    expect(text).not.toContain("Sports");
    expect(text).toContain("+1");
  });

  it("shows no counter when the limit exceeds the item count", () => {
    expect(mountChips({ limit: 10 }).text()).not.toContain("+");
  });

  it("renders nothing but stays stable with an empty list", () => {
    expect(mountChips({ items: [], limit: 0 }).text().trim()).toBe("");
  });
});
