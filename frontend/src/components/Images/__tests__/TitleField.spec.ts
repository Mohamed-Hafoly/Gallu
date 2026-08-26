import { describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import TitleField from "@/components/Images/Fields/TitleField.vue";

function mountField(props: Record<string, unknown> = {}) {
  return mountWithPlugins(TitleField, { props });
}

describe("TitleField", () => {
  it("renders the title as text outside edit mode", () => {
    const wrapper = mountField({ modelValue: "Front cover" });

    expect(wrapper.text()).toContain("Front cover");
    expect(wrapper.findComponent({ name: "VTextField" }).exists()).toBe(false);
  });

  /**
   * The per-document uniqueness rule lives only on the server, so a collision
   * arrives as a 422 rather than a local rule. It has to land on the field, not
   * just in a toast, or the user is told "upload failed" with no idea why.
   */
  it("shows a server-side error on the input", async () => {
    const wrapper = mountField({
      modelValue: "Front cover",
      editable: true,
      error: "This document already has an image with that title.",
    });

    const field = wrapper.findComponent({ name: "VTextField" });

    expect(field.props("errorMessages")).toBe(
      "This document already has an image with that title.",
    );
    expect(wrapper.text()).toContain(
      "This document already has an image with that title.",
    );
  });

  // error-messages must not displace the local rules — Vuetify composes the two,
  // so required/max still fire while a server message is on screen.
  it("keeps the local rules alongside a server error", () => {
    const wrapper = mountField({
      modelValue: "",
      editable: true,
      error: "Taken",
    });

    const rules = wrapper.findComponent({ name: "VTextField" }).props("rules");

    expect(Array.isArray(rules)).toBe(true);
    expect(rules).toHaveLength(2);
  });

  // Vuetify normalises an absent error-messages to [], so the assertion is on
  // the rendered state rather than on the prop being undefined.
  it("renders no error state when none is given", () => {
    const wrapper = mountField({ modelValue: "Front cover", editable: true });

    expect(wrapper.find(".v-input--error").exists()).toBe(false);
    expect(wrapper.findComponent({ name: "VTextField" }).exists()).toBe(true);
  });
});
