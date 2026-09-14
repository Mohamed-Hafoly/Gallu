import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DescriptionField from "@/components/Images/Fields/DescriptionField.vue";
import i18n from "@/plugins/i18n";

function mountDescription(props: Record<string, unknown> = {}) {
  return mountWithPlugins(DescriptionField, {
    props: { editable: true, ...props },
  });
}

/** Vuetify validates asynchronously, so drain the queue before asserting. */
async function messagesAfterTyping(value: string) {
  const wrapper = mountDescription();
  await wrapper.find("textarea").setValue(value);
  await flushPromises();
  return wrapper.findAll(".v-messages__message").map((m) => m.text());
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("editable mode", () => {
  it("always shows the persistent hint", () => {
    expect(mountDescription().text()).toContain(
      i18n.global.t("gallery.descriptionHint"),
    );
  });

  it("accepts an empty description, since it is nullable server-side", async () => {
    const messages = await messagesAfterTyping("");

    expect(messages).not.toContain(i18n.global.t("validation.required"));
  });

  it("accepts a description at the 400 char limit", async () => {
    const messages = await messagesAfterTyping("a".repeat(400));

    expect(messages).not.toContain(
      i18n.global.t("validation.maxLength", { max: 400 }),
    );
  });

  it("reports a max-length error one char past the limit", async () => {
    const messages = await messagesAfterTyping("a".repeat(401));

    expect(messages).toContain(
      i18n.global.t("validation.maxLength", { max: 400 }),
    );
  });
});

describe("read-only mode", () => {
  it("shows the description text with no textarea", () => {
    const wrapper = mountWithPlugins(DescriptionField, {
      props: { editable: false, modelValue: "A description" },
    });

    expect(wrapper.text()).toContain("A description");
    expect(wrapper.find("textarea").exists()).toBe(false);
  });

  it("falls back to the empty-state message when there is no description", () => {
    const wrapper = mountWithPlugins(DescriptionField, {
      props: { editable: false, modelValue: "" },
    });

    expect(wrapper.text()).toContain(i18n.global.t("gallery.noDescription"));
  });
});
