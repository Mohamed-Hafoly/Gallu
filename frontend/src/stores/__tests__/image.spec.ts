import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useImageStore } from "@/stores/image";

vi.mock("@/plugins/axios", () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}));

const mockedApi = vi.mocked(api);

/** FormData entries as a plain object; array keys collapse to a list. */
function formEntries(body: FormData) {
  const out: Record<string, unknown[]> = {};
  for (const [key, value] of body.entries()) {
    (out[key] ??= []).push(value);
  }
  return out;
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("fetchImages", () => {
  it("unwraps the resource collection's data envelope", async () => {
    const images = [{ id: 1, title: "One" }];
    mockedApi.get.mockResolvedValue({ data: { data: images } });

    await expect(useImageStore().fetchImages()).resolves.toEqual(images);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/images");
  });
});

describe("createImage", () => {
  it("POSTs multipart fields matching StoreImageRequest", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: { id: 1 } } });

    await useImageStore().createImage({
      title: "Beach Sunset",
      description: "A description",
      selected_category_ids: [3, 7],
      image: new File(["x"], "photo.jpg", { type: "image/jpeg" }),
    });

    const [url, body] = mockedApi.post.mock.calls[0];
    expect(url).toBe("/api/images");

    const fields = formEntries(body as FormData);
    expect(fields.title).toEqual(["Beach Sunset"]);
    expect(fields.description).toEqual(["A description"]);
    expect(fields["selected_category_ids[]"]).toEqual(["3", "7"]);
    expect(fields.image).toHaveLength(1);
  });

  it("omits description entirely when it is blank", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: { id: 1 } } });

    await useImageStore().createImage({
      title: "No description",
      selected_category_ids: [1],
      image: new File(["x"], "photo.jpg", { type: "image/jpeg" }),
    });

    const fields = formEntries(mockedApi.post.mock.calls[0][1] as FormData);
    expect(fields.description).toBeUndefined();
  });
});

describe("updateImage", () => {
  it("POSTs with a _method=PATCH spoof rather than a real PATCH", async () => {
    // PHP does not populate $_FILES on a native PATCH with a multipart body,
    // so the route is PATCH but the request must be sent as POST.
    mockedApi.post.mockResolvedValue({ data: { data: { id: 5 } } });

    await useImageStore().updateImage(5, {
      title: "Updated",
      selected_category_ids: [2],
    });

    const [url, body] = mockedApi.post.mock.calls[0];
    expect(url).toBe("/api/images/5");
    expect(formEntries(body as FormData)._method).toEqual(["PATCH"]);
  });

  it("omits the image field when no new file was picked", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: { id: 5 } } });

    await useImageStore().updateImage(5, {
      title: "Text only edit",
      selected_category_ids: [2],
    });

    expect(formEntries(mockedApi.post.mock.calls[0][1] as FormData).image).toBeUndefined();
  });

  it("includes the image field when a replacement file is supplied", async () => {
    mockedApi.post.mockResolvedValue({ data: { data: { id: 5 } } });

    await useImageStore().updateImage(5, {
      title: "Swapped file",
      selected_category_ids: [2],
      image: new File(["x"], "new.jpg", { type: "image/jpeg" }),
    });

    expect(formEntries(mockedApi.post.mock.calls[0][1] as FormData).image).toHaveLength(1);
  });
});

describe("deleteImage", () => {
  it("calls DELETE against the image's own URL", async () => {
    mockedApi.delete.mockResolvedValue({ status: 204 });

    await useImageStore().deleteImage(9);

    expect(mockedApi.delete).toHaveBeenCalledWith("/api/images/9");
  });
});
