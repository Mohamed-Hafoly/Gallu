import type { Document } from "@/types/document";
import type { Image } from "@/types/image";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentsIndex from "@/pages/documents/index.vue";
import i18n from "@/plugins/i18n";
import { useNotifierStore } from "@/stores/notifier";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({ useRouter: () => ({ push }) }));

// The page reads the auth store, which imports the real router module and
// would build a router against a partially mocked vue-router. Stubbed the same
// way the dialog specs do it.
vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const { fetchDocuments, fetchPickerTeams } = vi.hoisted(() => ({
  fetchDocuments: vi.fn(),
  fetchPickerTeams: vi.fn(),
}));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({ fetchDocuments }),
}));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
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
    user_id: 1,
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
    team: { id: 1, name: "Design" },
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
  };
}

/** A signed-in user of the given shape; `undefined` seeds no session at all. */
const OWN_TEAM = { id: 1, name: "Design" };

function makeUser(
  role: "super-admin" | "admin" | "member",
  team: { id: number; name: string } | null = OWN_TEAM,
): User {
  return {
    id: 7,
    name: "Grace Hopper",
    email: "grace@example.com",
    avatar_url: "/avatar.jpg",
    avatar_thumb_url: "/avatar.jpg",
    has_avatar: false,
    default_avatar_url: "/avatar.jpg",
    created_at: "2026-08-01T10:00:00Z",
    updated_at: "2026-08-15T10:00:00Z",
    is_super_admin: role === "super-admin",
    role,
    team: role === "super-admin" ? null : team,
  };
}

async function mountIndex(document: Document, user?: User) {
  fetchDocuments.mockResolvedValue([document]);
  const wrapper = mountWithPlugins(
    DocumentsIndex,
    {},
    user ? { auth: { user } } : undefined,
  );
  await flushPromises();
  return wrapper;
}

function coverGrid(wrapper: Awaited<ReturnType<typeof mountIndex>>) {
  return wrapper.find(".grid-cols-2");
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
  fetchPickerTeams.mockResolvedValue([{ id: 1, name: "Design" }]);
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

/**
 * The create button and the per-card edit icon mirror DocumentPolicy: a member
 * may do neither, a team admin may do both within their own team, and a
 * super-admin may do both anywhere. Cosmetic only — the policy is what denies —
 * but a button that always 403s is worse than no button.
 */
describe("document affordances", () => {
  function createButton(wrapper: Awaited<ReturnType<typeof mountIndex>>) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.text().includes("Create document"));
  }

  function editButtons(wrapper: Awaited<ReturnType<typeof mountIndex>>) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .filter((button) => button.props("icon") === "mdi-pencil");
  }

  it("offers a member neither create nor edit", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("member"));

    expect(createButton(wrapper)).toBeUndefined();
    expect(editButtons(wrapper)).toHaveLength(0);
  });

  it("offers an admin both", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(createButton(wrapper)).toBeDefined();
    expect(editButtons(wrapper)).toHaveLength(1);
  });

  it("offers a super admin both", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("super-admin"));

    expect(createButton(wrapper)).toBeDefined();
    expect(editButtons(wrapper)).toHaveLength(1);
  });

  // An admin only ever sees their own team's documents, so this cannot happen
  // through the listing — but the check is what keeps that true.
  it("hides edit on another teams document", async () => {
    const theirs = { ...doc([], 0), team: { id: 99, name: "Other" } };
    const wrapper = await mountIndex(theirs, makeUser("admin"));

    expect(editButtons(wrapper)).toHaveLength(0);
  });

  // Nothing to lock the dialog to, and this page never offers the picker, so
  // the edit would 422 on a field the form does not render.
  it("hides edit on a team less document, even for a super admin", async () => {
    const orphan = { ...doc([], 0), team: null };
    const wrapper = await mountIndex(orphan, makeUser("super-admin"));

    expect(editButtons(wrapper)).toHaveLength(0);
  });

  // The button sits above the loading/empty/grid branches, so it survives the
  // empty state — which is exactly when creating a document matters most.
  it("still offers create when there are no documents", async () => {
    fetchDocuments.mockResolvedValue([]);
    const wrapper = mountWithPlugins(
      DocumentsIndex,
      {},
      { auth: { user: makeUser("admin") } },
    );
    await flushPromises();

    expect(wrapper.text()).toContain("No documents yet.");
    expect(createButton(wrapper)).toBeDefined();
  });
});

describe("dialog wiring", () => {
  function editButton(wrapper: Awaited<ReturnType<typeof mountIndex>>) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.props("icon") === "mdi-pencil")!;
  }

  // An admin files under their own team and never sees the field; a super-admin
  // belongs to no team, so there is nothing to default them to and the picker
  // has to stay.
  it("locks the create dialog to an admins own team", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(
      wrapper.findComponent({ name: "DocumentCreateDialog" }).props("lockedTeamId"),
    ).toBe(1);
  });

  it("leaves the create dialog unlocked for a super admin", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("super-admin"));

    expect(
      wrapper.findComponent({ name: "DocumentCreateDialog" }).props("lockedTeamId"),
    ).toBeUndefined();
  });

  /**
   * .stop on the icon is load-bearing: the whole card carries @click="open",
   * so without it editing would also navigate into the document.
   */
  it("opens the edit dialog without navigating", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    await editButton(wrapper).trigger("click");
    await flushPromises();

    const dialog = wrapper.findComponent({ name: "DocumentEditDialog" });

    expect(dialog.exists()).toBe(true);
    expect(dialog.props("document")).toMatchObject({ id: 1 });
    // Editing must never move a document between teams from this page.
    expect(dialog.props("lockedTeamId")).toBe(1);
    expect(push).not.toHaveBeenCalled();
  });

  it("still navigates when the card itself is clicked", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    await wrapper.findComponent({ name: "VCard" }).trigger("click");

    expect(push).toHaveBeenCalledWith("/documents/1");
  });

  it("refetches and notifies after a create", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));
    const notifier = useNotifierStore();

    fetchDocuments.mockClear();
    wrapper.findComponent({ name: "DocumentCreateDialog" }).vm.$emit("created");
    await flushPromises();

    expect(fetchDocuments).toHaveBeenCalledTimes(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document created");
  });

  it("refetches and notifies after an edit", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));
    const notifier = useNotifierStore();

    await editButton(wrapper).trigger("click");
    fetchDocuments.mockClear();
    wrapper.findComponent({ name: "DocumentEditDialog" }).vm.$emit("updated");
    await flushPromises();

    expect(fetchDocuments).toHaveBeenCalledTimes(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document updated");
  });
});
