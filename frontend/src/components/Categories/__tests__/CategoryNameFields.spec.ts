import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import CategoryNameFields from "@/components/Categories/CategoryNameFields.vue";
import i18n from "@/plugins/i18n";

function mountFields(props: Record<string, unknown> = {}) {
  return mountWithPlugins(CategoryNameFields, {
    props: { nameEn: "", nameAr: "", ...props },
  });
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("CategoryNameFields", () => {
  it("renders both name inputs, labelled per locale", () => {
    const wrapper = mountFields();

    expect(wrapper.findAll("input")).toHaveLength(2);
    expect(wrapper.text()).toContain(
      i18n.global.t("admin.categories.nameEnglish"),
    );
    expect(wrapper.text()).toContain(
      i18n.global.t("admin.categories.nameArabic"),
    );
  });

  it("emits each name on its own model channel", async () => {
    const wrapper = mountFields();
    const [english, arabic] = wrapper.findAll("input");

    await english.setValue("Sports");
    await arabic.setValue("رياضة");

    expect(wrapper.emitted("update:nameEn")?.at(-1)).toEqual(["Sports"]);
    expect(wrapper.emitted("update:nameAr")?.at(-1)).toEqual(["رياضة"]);
  });

  it("reports a required error once a name is cleared", async () => {
    const wrapper = mountFields();
    const english = wrapper.findAll("input")[0];

    // Typed first, then cleared: Vuetify validates on input, so a field that
    // was only ever populated by its prop has not validated yet.
    await english.setValue("Sports");
    await english.setValue("");
    await flushPromises();

    expect(
      wrapper.findAll(".v-messages__message").map((m) => m.text()),
    ).toContain(i18n.global.t("validation.required"));
  });

  it("reports a max-length error one char past the 40 limit", async () => {
    const wrapper = mountFields();

    await wrapper.findAll("input")[0].setValue("a".repeat(41));
    await flushPromises();

    expect(
      wrapper.findAll(".v-messages__message").map((m) => m.text()),
    ).toContain(i18n.global.t("validation.maxLength", { max: 40 }));
  });
});
