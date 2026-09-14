import { describe, expect, it } from "vitest";
import { VBtn, VProgressCircular, VProgressLinear } from "vuetify/components";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";

/**
 * These pin configuration wiring, not aesthetics: every progress indicator in
 * the app is now bare and resolves its colour from plugins/vuetify.ts, so a
 * dropped `defaults` entry would silently restore Vuetify's own colours across
 * a dozen call sites with nothing else to catch it.
 */
describe("progress defaults", () => {
  const strokeOf = (wrapper: ReturnType<typeof mountWithPlugins>) =>
    wrapper.find("circle.v-progress-circular__overlay").attributes("stroke-width");

  it("gives a bare circular the tertiary colour", () => {
    const wrapper = mountWithPlugins(VProgressCircular, {
      props: { indeterminate: true },
    });

    expect(wrapper.classes()).toContain("text-tertiary");
  });

  // Compared against an explicit width rather than a literal: Vuetify scales
  // `width` against the SVG viewBox, so `3` renders as a stroke-width of ~4.14
  // and hardcoding that would pin its internal maths instead of our default.
  it("gives a bare circular the same stroke as an explicit width of 3", () => {
    const bare = mountWithPlugins(VProgressCircular, { props: { indeterminate: true } });
    const explicit = mountWithPlugins(VProgressCircular, {
      props: { indeterminate: true, width: 3 },
    });
    const thicker = mountWithPlugins(VProgressCircular, {
      props: { indeterminate: true, width: 4 },
    });

    expect(strokeOf(bare)).toBe(strokeOf(explicit));
    expect(strokeOf(bare)).not.toBe(strokeOf(thicker));
  });

  it("gives a bare linear the primary colour", () => {
    const wrapper = mountWithPlugins(VProgressLinear, {
      props: { indeterminate: true },
    });

    expect(wrapper.find(".v-progress-linear__indeterminate.long").classes())
      .toContain("bg-primary");
  });

  // The escape hatch the two tertiary-background buttons rely on: ImagePicker's
  // "Change Image" and TheProfileMenu's logout would render an invisible
  // spinner if a prop could not beat the default.
  it("lets an explicit colour override the default", () => {
    const wrapper = mountWithPlugins(VProgressCircular, {
      props: { indeterminate: true, color: "on-tertiary" },
    });

    expect(wrapper.classes()).toContain("text-on-tertiary");
    expect(wrapper.classes()).not.toContain("text-tertiary");
  });

  // VBtn only forwards `loading` as the spinner's colour when it is a string
  // (VBtn.js: `typeof props.loading === 'boolean' ? undefined : props.loading`).
  // A bare boolean therefore inherits the tertiary default, which is invisible
  // on the tertiary-filled bulk-restore buttons in the admin screens.
  it("lets a string loading colour a button's built-in spinner", () => {
    const bool = mountWithPlugins(VBtn, { props: { loading: true, color: "tertiary" } });
    const str = mountWithPlugins(VBtn, {
      props: { loading: "on-tertiary", color: "tertiary" },
    });

    const spinner = (w: typeof bool) =>
      w.find(".v-btn__loader .v-progress-circular").classes();

    expect(spinner(bool)).toContain("text-tertiary");
    expect(spinner(str)).toContain("text-on-tertiary");
  });
});
