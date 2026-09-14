import type { AxiosError } from "axios";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ImageCreateDialog from "@/components/Images/ImageCreateDialog.vue";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const { createImage } = vi.hoisted(() => ({ createImage: vi.fn() }));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ createImage }),
}));

vi.mock("@/stores/category", () => ({
  useCategoryStore: () => ({ fetchPickerCategories: vi.fn().mockResolvedValue([]) }),
}));

const DUPLICATE = "This document already has an image with that title.";

/** What the axios interceptor hands a caller back for a 422. */
function validationError(fieldErrors: Record<string, string[]>) {
  return Object.assign(new Error("Request failed"), {
    fieldErrors,
    userMessage: DUPLICATE,
  }) as AxiosError;
}

beforeEach(() => {
  vi.clearAllMocks();
});

function mountDialog() {
  return mountWithPlugins(ImageCreateDialog, {
    props: { documentId: 7, modelValue: true },
  });
}

/**
 * Drives a submit that reaches the store. The local rules are synchronous, so a
 * title and a file both have to be present or submit() returns early and never
 * calls createImage. Categories are deliberately left empty — they are optional,
 * and an empty picker must not block the upload.
 */
async function submitWith(wrapper: ReturnType<typeof mountDialog>, title: string) {
  const vm = wrapper.vm as unknown as {
    form: { title: string; selectedCategoryIds: number[]; description: string };
    selectedFile: File | null;
    submit: () => Promise<void>;
  };

  vm.form.title = title;
  vm.selectedFile = new File(["x"], "x.png", { type: "image/png" });

  await flushPromises();
  await vm.submit();
  await flushPromises();
}

describe("ImageCreateDialog", () => {
  /**
   * Titles are unique per document, and only the server knows the document's
   * other titles. A 422 therefore has to land on the field — a toast alone
   * leaves the user staring at a form with nothing marked wrong.
   */
  it("puts a rejected title on the title field", async () => {
    createImage.mockRejectedValue(validationError({ title: [DUPLICATE] }));

    const wrapper = mountDialog();
    await submitWith(wrapper, "Front cover");

    expect(createImage).toHaveBeenCalled();
    expect(wrapper.findComponent({ name: "TitleField" }).props("error")).toBe(
      DUPLICATE,
    );
  });

  // A failure on some other field must not be mislabelled as a title problem.
  it("leaves the title field clean when another field is rejected", async () => {
    createImage.mockRejectedValue(validationError({ document_id: ["Nope"] }));

    const wrapper = mountDialog();
    await submitWith(wrapper, "Front cover");

    expect(wrapper.findComponent({ name: "TitleField" }).props("error")).toBe("");
  });

  // The message is about a specific value, so it must not survive that value
  // being changed — otherwise the user edits the title and is still told no.
  it("clears the message once the title is edited", async () => {
    createImage.mockRejectedValue(validationError({ title: [DUPLICATE] }));

    const wrapper = mountDialog();
    await submitWith(wrapper, "Front cover");

    const field = wrapper.findComponent({ name: "TitleField" });

    expect(field.props("error")).toBe(DUPLICATE);

    field.vm.$emit("update:modelValue", "Front cover 2");
    await flushPromises();

    expect(wrapper.findComponent({ name: "TitleField" }).props("error")).toBe("");
  });

  it("keeps the dialog open so the title can be fixed", async () => {
    createImage.mockRejectedValue(validationError({ title: [DUPLICATE] }));

    const wrapper = mountDialog();
    await submitWith(wrapper, "Front cover");

    expect(wrapper.emitted("created")).toBeUndefined();
    expect(wrapper.emitted("update:modelValue")).toBeUndefined();
  });
});
