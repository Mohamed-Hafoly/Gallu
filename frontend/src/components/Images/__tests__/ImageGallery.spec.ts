import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ImageGallery from "@/components/Images/ImageGallery.vue";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// The store is stubbed rather than driven through axios: the point of these
// cases is which arguments the component passes, not how the store serialises.
const { fetchImages } = vi.hoisted(() => ({ fetchImages: vi.fn() }));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ fetchImages }),
}));

beforeEach(() => {
  vi.clearAllMocks();
  fetchImages.mockResolvedValue([]);
});

function mountGallery(props: Record<string, unknown> = {}) {
  return mountWithPlugins(ImageGallery, { props });
}

describe("ImageGallery", () => {
  // /gallery spans every document, so there is nothing an upload could attach
  // to — the button is hidden rather than shown and then failing validation.
  it("hides the upload button when no document is given", async () => {
    const wrapper = mountGallery();
    await flushPromises();

    expect(wrapper.findComponent({ name: "VBtn" }).exists()).toBe(false);
    expect(fetchImages).toHaveBeenCalledWith(undefined);
  });

  it("shows the upload button and scopes the fetch inside a document", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    expect(wrapper.findComponent({ name: "VBtn" }).exists()).toBe(true);
    expect(fetchImages).toHaveBeenCalledWith(7);
  });

  it("passes the document id down to the create dialog", async () => {
    const wrapper = mountGallery({ documentId: 7 });
    await flushPromises();

    const dialog = wrapper.findComponent({ name: "ImageCreateDialog" });

    expect(dialog.exists()).toBe(true);
    expect(dialog.props("documentId")).toBe(7);
  });
});
