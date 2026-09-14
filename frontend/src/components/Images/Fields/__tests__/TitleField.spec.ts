import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import TitleField from "@/components/Images/Fields/TitleField.vue";
import i18n from "@/plugins/i18n";

function mountTitle(props: Record<string, unknown> = {}) {
  return mountWithPlugins(TitleField, {
    props: { editable: true, ...props },
  });
}

/**
 * Vuetify renders rule failures and hints into the same messages slot, and
 * validates asynchronously — so the promise queue has to drain before asserting.
 */
async function messagesAfterTyping(value: string) {
  const wrapper = mountTitle();
  await wrapper.find("input").setValue(value);
  await flushPromises();
  return wrapper.findAll(".v-messages__message").map((m) => m.text());
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("editable mode", () => {
  it("always shows the persistent hint", () => {
    expect(mountTitle().text()).toContain(i18n.global.t("gallery.titleHint"));
  });

  it("accepts a title at the 140 char limit", async () => {
    const messages = await messagesAfterTyping("a".repeat(140));

    expect(messages).not.toContain(
      i18n.global.t("validation.maxLength", { max: 140 }),
    );
  });

  it("reports a max-length error one char past the limit", async () => {
    const messages = await messagesAfterTyping("a".repeat(141));

    expect(messages).toContain(
      i18n.global.t("validation.maxLength", { max: 140 }),
    );
  });

  it("reports a required error once the field is cleared", async () => {
    const wrapper = mountTitle();
    const input = wrapper.find("input");

    await input.setValue("something");
    await input.setValue("");
    await flushPromises();

    expect(wrapper.findAll(".v-messages__message").map((m) => m.text()))
      .toContain(i18n.global.t("validation.required"));
  });
});

describe("read-only mode", () => {
  it("renders the title as text with no input", () => {
    const wrapper = mountWithPlugins(TitleField, {
      props: { editable: false, modelValue: "Beach Sunset" },
    });

    expect(wrapper.text()).toContain("Beach Sunset");
    expect(wrapper.find("input").exists()).toBe(false);
  });
});
