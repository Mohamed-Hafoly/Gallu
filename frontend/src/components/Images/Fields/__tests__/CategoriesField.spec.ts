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

// Categories are optional, so the editable branch is the picker and nothing
// else: no rule, no v-input enrolling it in the surrounding v-form, and no
// caption. Matched by element rather than by a styling class, because Vuetify's
// utility classes emit no CSS here (styles/settings.scss sets `$utilities:
// false`) — CategoryPicker itself renders only chips.
describe("editable mode", () => {
  it("renders the picker", () => {
    const wrapper = mountField();

    expect(wrapper.findComponent({ name: "CategoryPicker" }).exists()).toBe(true);
  });

  it("says nothing when nothing is selected", () => {
    // Regression: an empty picker used to be a validation error, and the field
    // carried a "select at least one category" caption to announce it.
    const wrapper = mountField({ modelValue: [] });

    expect(wrapper.findAll("p")).toHaveLength(0);
  });

  it("stays out of the surrounding form", () => {
    const wrapper = mountField({ modelValue: [] });

    expect(wrapper.findComponent({ name: "VInput" }).exists()).toBe(false);
  });
});

describe("read-only mode", () => {
  it("falls back to an empty-state message when there are no categories", () => {
    const wrapper = mountWithPlugins(CategoriesField, {
      props: { items: [], editable: false },
    });

    expect(wrapper.text()).toContain(i18n.global.t("gallery.noCategories"));
  });

  it("renders the chips instead when there are some", () => {
    const wrapper = mountWithPlugins(CategoriesField, {
      props: { items: categories, editable: false },
    });

    expect(wrapper.findComponent({ name: "CategoryChips" }).exists()).toBe(true);
    expect(wrapper.text()).not.toContain(i18n.global.t("gallery.noCategories"));
  });
});
