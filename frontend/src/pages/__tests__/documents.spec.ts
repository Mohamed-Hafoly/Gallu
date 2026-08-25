import type { Document } from "@/types/document";
import type { Image } from "@/types/image";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentsIndex from "@/pages/documents/index.vue";
import i18n from "@/plugins/i18n";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("vue-router", () => ({ useRouter: () => ({ push: vi.fn() }) }));

const { fetchDocuments } = vi.hoisted(() => ({ fetchDocuments: vi.fn() }));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({ fetchDocuments }),
}));

function image(id: number): Image {
  return {
    id,
    title: `Image ${id}`,
    description: null,
    url: `/i/${id}.jpg`,
    thumb_url: `/i/${id}-thumb.jpg`,
    categories: [],
    document_id: 1,
    creator: "Ada Lovelace",
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
  };
}

function doc(images: Image[], imagesCount: number): Document {
  return {
    id: 1,
    title: "Trip",
    description: null,
    images,
    images_count: imagesCount,
    creator: "Ada Lovelace",
    created_at: "2026-08-24T10:00:00.000000Z",
  };
}

async function mountIndex(document: Document) {
  fetchDocuments.mockResolvedValue([document]);
  const wrapper = mountWithPlugins(DocumentsIndex);
  await flushPromises();
  return wrapper;
}

function coverGrid(wrapper: Awaited<ReturnType<typeof mountIndex>>) {
  return wrapper.find(".grid-cols-2");
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
});

describe("document cover grid", () => {
  // The 2x2 shape is fixed: fewer than four images must leave empty cells
  // rather than letting the grid collapse.
  it("renders four cells for a document with only two images", async () => {
    const wrapper = await mountIndex(doc([image(1), image(2)], 2));
    const grid = coverGrid(wrapper);

    expect(grid.exists()).toBe(true);
    expect(grid.element.children).toHaveLength(4);
    expect(grid.findAllComponents({ name: "VImg" })).toHaveLength(2);
  });

  // Guards the classes only. jsdom does not apply Tailwind's stylesheet, so this
  // cannot prove the visual result — it catches the realistic regression, which
  // is someone dropping these or reaching for `opacity` again. Opacity makes the
  // grid translucent and lets the card's teal surface through, washing the
  // images out; a filter darkens the pixels regardless of what is behind.
  it("dims each image, not the grid, so the divider lines stay full strength", async () => {
    const wrapper = await mountIndex(doc([image(1)], 1));
    const grid = coverGrid(wrapper);
    const img = grid.findComponent({ name: "VImg" });

    expect(img.classes()).toContain("brightness-65");
    expect(img.classes()).toContain("group-hover:brightness-100");

    // A filter on the container would also dim its background, which is what
    // draws the lines — so the grid itself must carry no brightness class.
    expect(grid.classes().some((c) => c.startsWith("brightness-"))).toBe(false);
    expect(grid.classes()).not.toContain("opacity-10");
  });

  it("draws primary divider lines through the gap", async () => {
    const grid = coverGrid(await mountIndex(doc([image(1), image(2)], 2)));

    expect(grid.classes()).toContain("bg-primary");
    // Any gap will do — the thickness is a taste knob (gap-0.5, gap-px,
    // gap-[3px]). What must hold is that a gap exists at all, since the lines
    // are the container's primary background showing through it.
    expect(grid.classes().some((c) => c.startsWith("gap-"))).toBe(true);
  });

  // The container background is primary, so any cell that is not opaque — empty,
  // or an image still loading — would show a solid primary block. Both branches
  // therefore carry bg-black. `:not(.v-img)` is needed because VImg renders a
  // div too, so a bare `div.bg-black` would also match the image cells.
  it("gives empty cells and loading images their own background", async () => {
    const grid = coverGrid(await mountIndex(doc([image(1)], 1)));

    expect(grid.findAll("div.bg-black:not(.v-img)")).toHaveLength(3);
    expect(grid.findComponent({ name: "VImg" }).classes()).toContain("bg-black");
  });

  it("renders four images when there are four", async () => {
    const wrapper = await mountIndex(
      doc([image(1), image(2), image(3), image(4)], 4),
    );

    expect(coverGrid(wrapper).findAllComponents({ name: "VImg" })).toHaveLength(4);
  });

  // An image-less document keeps the folder icon rather than four blanks,
  // which would read as a broken thumbnail.
  it("falls back to the folder icon when there are no images", async () => {
    const wrapper = await mountIndex(doc([], 0));

    expect(coverGrid(wrapper).exists()).toBe(false);
    expect(wrapper.html()).toContain("mdi-folder-outline");
  });
});

describe("image count label", () => {
  // Reads images_count, not images.length: the relation is truncated to four,
  // so a six-image document would otherwise under-report as "4 images".
  it("reports the true total rather than the truncated array length", async () => {
    const wrapper = await mountIndex(
      doc([image(1), image(2), image(3), image(4)], 6),
    );

    expect(wrapper.text()).toContain("6 images");
    expect(wrapper.text()).not.toContain("4 images");
  });
});
