import { describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ReadOnlyField from "@/components/Images/Fields/ReadOnlyField.vue";

function mountField(props: { label: string; value: string; editable?: boolean }) {
  return mountWithPlugins(ReadOnlyField, { props });
}

describe("ReadOnlyField", () => {
  it("renders the value under the label in view mode", () => {
    const wrapper = mountField({ label: "Creator", value: "Ada Lovelace" });

    expect(wrapper.text()).toContain("Creator");
    expect(wrapper.text()).toContain("Ada Lovelace");
    expect(wrapper.findComponent({ name: "VTextField" }).exists()).toBe(false);
  });

  // The point of the component: editing must not turn these into something the
  // user can type into, only into something that looks like the fields beside it.
  it("becomes a disabled input carrying the value on edit", () => {
    const wrapper = mountField({
      label: "Created At",
      value: "18 Aug 2026, 14:47",
      editable: true,
    });

    const field = wrapper.findComponent({ name: "VTextField" });

    expect(field.exists()).toBe(true);
    expect(field.props("disabled")).toBe(true);
    expect(field.props("modelValue")).toBe("18 Aug 2026, 14:47");
  });

  // No "missing value" case: images.user_id is NOT NULL and ImageResource serves
  // both creator and created_at unconditionally, so `value` is always a string.
  // Asserts the class, not a dir="auto" attribute: the paragraph direction now
  // comes from `unicode-bidi: plaintext`, which .bidi-auto carries in
  // styles/main.scss. jsdom applies no stylesheet, so the class is the only
  // observable part here — the rendered effect is verified in the browser.
  it("keeps a value in the other script on its own side", () => {
    const wrapper = mountField({ label: "المنشئ", value: "محمد عبد الرحمن" });

    expect(wrapper.find("p").classes()).toContain("bidi-auto");
    expect(wrapper.text()).toContain("محمد عبد الرحمن");
  });
});
