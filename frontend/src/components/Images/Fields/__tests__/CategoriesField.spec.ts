import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import CategoriesField from "@/components/Images/Fields/CategoriesField.vue";
import i18n from "@/plugins/i18n";

const categories = [
  { id: 1, name_en: "Books", name_ar: "كتب" },
  { id: 2, name_en: "Art", name_ar: "فن" },
];

function mountField(props: Record<string, unknown> = {}) {
  return mountWithPlugins(CategoriesField, {
    props: { items: categories, editable: true, ...props },
  });
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("editable mode caption", () => {
  it("renders exactly one caption line, not a hint and an error stacked", () => {
    // Regression: the hint and the error were previously two separate <p>s
    // showing the same sentence twice after a failed submit.
    const wrapper = mountField({ error: "Select at least one category" });
    const captions = wrapper.findAll("p.text-caption");

    expect(captions).toHaveLength(1);
  });

  it("shows the neutral hint, muted, when there is no error", () => {
    const caption = mountField().find("p.text-caption");

    expect(caption.text()).toBe(i18n.global.t("gallery.categoriesHint"));
    expect(caption.classes()).toContain("opacity-70");
    expect(caption.classes()).not.toContain("text-error");
  });

  it("swaps to the error text and error colour when an error is set", () => {
    const caption = mountField({ error: "Select at least one category" })
      .find("p.text-caption");

    expect(caption.text()).toBe("Select at least one category");
    expect(caption.classes()).toContain("text-error");
    expect(caption.classes()).not.toContain("opacity-70");
  });
});

describe("read-only mode", () => {
  it("does not render the editable hint", () => {
    const wrapper = mountWithPlugins(CategoriesField, {
      props: { items: categories, editable: false },
    });

    expect(wrapper.text()).not.toContain(i18n.global.t("gallery.categoriesHint"));
  });

  it("falls back to an empty-state message when there are no categories", () => {
    const wrapper = mountWithPlugins(CategoriesField, {
      props: { items: [], editable: false },
    });

    expect(wrapper.text()).toContain(i18n.global.t("gallery.noCategories"));
  });
});
