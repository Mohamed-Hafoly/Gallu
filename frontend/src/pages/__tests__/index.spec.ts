import type { Document } from "@/types/document";
import type { Image } from "@/types/image";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentsIndex from "@/pages/index.vue";
import i18n from "@/plugins/i18n";
import { useNotifierStore } from "@/stores/notifier";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

/**
 * The chip, the term and the sort are the query string, so the spec needs a
 * route it can read and a router whose replace() writes back into it — that
 * round trip is exactly what makes a chip click refetch.
 *
 * The route is held behind a mutable box rather than created inline: it has to
 * be `reactive()` for the page's computeds to see a query change, and a
 * vi.hoisted() factory runs before Vue is imported.
 */
const { router, push, replace } = vi.hoisted(() => ({
  router: { route: null as { query: Record<string, string> } | null },
  push: vi.fn(),
  replace: vi.fn(),
}));

vi.mock("vue-router", () => ({
  useRoute: () => router.route,
  useRouter: () => ({ push, replace }),
}));

// The page reads the auth store, which imports the real router module and
// would build a router against a partially mocked vue-router. Stubbed the same
// way the dialog specs do it.
vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const {
  fetchDocumentPage,
  deleteDocument,
  restoreDocument,
  fetchPickerTeams,
} = vi.hoisted(() => ({
  fetchDocumentPage: vi.fn(),
  deleteDocument: vi.fn(),
  restoreDocument: vi.fn(),
  fetchPickerTeams: vi.fn(),
}));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({
    fetchDocumentPage,
    deleteDocument,
    restoreDocument,
  }),
}));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
}));

interface ListParams {
  page: number;
  per_page: number;
  trashed?: string;
  search?: string;
  sort_by?: string;
  sort_order?: string;
  cover?: 1;
}

interface Page {
  items: Document[];
  total: number;
  lastPage: number;
}

/** Pages the grid's own requests are served, in order. */
let feedQueue: Page[] = [];

/** What the idle chip's per_page:1 probe reports. */
let idleTotal = 0;

/**
 * Captures the callback the page's IntersectionObserver is built with, so a
 * spec can say "the sentinel came into view" without a layout engine. The setup
 * file's stub is inert on purpose; this one is only for the paging cases.
 */
let intersect: (() => void) | null = null;

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

function doc(
  images: Image[],
  imagesCount: number,
  overrides: Partial<Document> = {},
): Document {
  return {
    id: 1,
    title: "Trip",
    description: null,
    images,
    images_count: imagesCount,
    creator: "Ada Lovelace",
    team: { id: 1, name: "Design", deleted_at: null },
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
    ...overrides,
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
    deleted_at: null,
    role,
    team: role === "super-admin" ? null : team,
  };
}

function mountPage(user?: User) {
  return mountWithPlugins(
    DocumentsIndex,
    {},
    user ? { auth: { user } } : undefined,
  );
}

type Wrapper = ReturnType<typeof mountPage>;

async function mountWith(documents: Document[], user?: User) {
  feedQueue = [{ items: documents, total: documents.length, lastPage: 1 }];
  const wrapper = mountPage(user);
  await flushPromises();
  return wrapper;
}

/** Mounts with the one document every card-shape case shares. */
async function mountIndex(document: Document, user?: User) {
  return mountWith([document], user);
}

function coverGrid(wrapper: Wrapper) {
  return wrapper.find(".grid-cols-2");
}

function chipTexts(wrapper: Wrapper) {
  return wrapper.findAllComponents({ name: "VChip" }).map((chip) => chip.text());
}

/** The params of the grid's own request — the probes send per_page 1. */
function feedCalls(): ListParams[] {
  return fetchDocumentPage.mock.calls
    .map(([params]) => params as ListParams)
    .filter((params) => params.per_page !== 1);
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
  router.route = reactive({ query: {} as Record<string, string> });
  intersect = null;
  feedQueue = [];
  idleTotal = 0;

  deleteDocument.mockResolvedValue(undefined);
  restoreDocument.mockResolvedValue(undefined);
  fetchPickerTeams.mockResolvedValue([{ id: 1, name: "Design" }]);

  // Dispatched on per_page rather than call order: the chip-count probe and the
  // grid's own page are fired together, so a mockResolvedValueOnce queue would
  // hand the grid whichever the page happened to call first.
  fetchDocumentPage.mockImplementation((params: ListParams) => {
    if (params.per_page === 1) {
      return Promise.resolve({ items: [], total: idleTotal, lastPage: 1 });
    }

    return Promise.resolve(
      feedQueue.shift() ?? { items: [], total: 0, lastPage: 1 },
    );
  });

  replace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    router.route!.query = { ...query };
  });

  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      intersect = () =>
        callback(
          [{ isIntersecting: true } as IntersectionObserverEntry],
          this as never,
        );
    }

    observe() {}
    unobserve() {}
    disconnect() {}
    takeRecords() {
      return [];
    }
  } as never;
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

    expect(coverGrid(wrapper).findAllComponents({ name: "VImg" })).toHaveLength(
      4,
    );
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
  function createButton(wrapper: Wrapper) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.text().includes("Create document"));
  }

  function editButtons(wrapper: Wrapper) {
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
    const theirs = doc([], 0, { team: { id: 99, name: "Other", deleted_at: null } });
    const wrapper = await mountIndex(theirs, makeUser("admin"));

    expect(editButtons(wrapper)).toHaveLength(0);
  });

  // A null team means a *trashed* one - documents.team_id is NOT NULL - reaching
  // the SPA from an endpoint that loaded the relation without withTrashed().
  // Nothing to lock the dialog to, and this page never offers the picker, so
  // the edit would 422 on a field the form does not render.
  it("hides edit when the documents team is trashed, even for a super admin", async () => {
    const orphan = doc([], 0, { team: null });
    const wrapper = await mountIndex(orphan, makeUser("super-admin"));

    expect(editButtons(wrapper)).toHaveLength(0);
  });

  // The button sits above the loading/empty/grid branches, so it survives the
  // empty state — which is exactly when creating a document matters most.
  it("still offers create when there are no documents", async () => {
    const wrapper = await mountWith([], makeUser("admin"));

    expect(wrapper.text()).toContain("No documents yet.");
    expect(createButton(wrapper)).toBeDefined();
  });

  // A different fact from "there are none at all", so it gets its own line.
  it("says nothing matched when a search comes back empty", async () => {
    router.route!.query = { search: "nope" };
    const wrapper = await mountWith([], makeUser("admin"));

    expect(wrapper.text()).toContain("No documents match your search.");
  });
});

/**
 * The chips are the trash axis and nothing else — there is no owner filter,
 * because a document belongs to a team rather than to whoever made it.
 */
describe("filter chips", () => {
  it("offers a member the All chip alone", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("member"));

    expect(chipTexts(wrapper).some((text) => text.includes("All"))).toBe(true);
    expect(
      chipTexts(wrapper).some((text) => text.includes("Recently deleted")),
    ).toBe(false);
  });

  it("offers an admin the trash chip too", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(
      chipTexts(wrapper).some((text) => text.includes("Recently deleted")),
    ).toBe(true);
  });

  // The count comes off meta.total for the active chip and a per_page:1 probe
  // for the idle one, so both are real numbers rather than blanks.
  it("counts the idle chip without selecting it", async () => {
    idleTotal = 4;
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));
    await flushPromises();

    expect(
      chipTexts(wrapper).find((text) => text.includes("Recently deleted")),
    ).toContain("4");
  });

  it("asks for deleted rows when the trash chip is picked", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    feedQueue = [{ items: [], total: 0, lastPage: 1 }];
    wrapper.findComponent({ name: "ListFilterBar" }).vm.$emit("update:modelValue", "trash");
    await flushPromises();

    expect(router.route!.query.trashed).toBe("only");
    expect(feedCalls().at(-1)).toMatchObject({ trashed: "only" });
  });

  // Every live row's deleted_at is null, so sorting by it anywhere else would
  // do nothing — and leaving the chip must not strand the select on it.
  it("offers the deleted at sort only inside the trash", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));
    const bar = wrapper.findComponent({ name: "ListFilterBar" });

    expect(bar.props("sortOptions")).not.toContainEqual(
      expect.objectContaining({ value: "deleted_at" }),
    );

    bar.vm.$emit("update:modelValue", "trash");
    await flushPromises();

    expect(
      wrapper.findComponent({ name: "ListFilterBar" }).props("sortOptions"),
    ).toContainEqual(expect.objectContaining({ value: "deleted_at" }));
  });
});

describe("search and sort", () => {
  it("sends the sort the bar asks for", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    wrapper
      .findComponent({ name: "ListFilterBar" })
      .vm
.$emit("update:sort-by", "images_count");
    await flushPromises();

    expect(router.route!.query.sort_by).toBe("images_count");
    expect(feedCalls().at(-1)).toMatchObject({ sort_by: "images_count" });
  });

  it("flips the direction without touching the column", async () => {
    router.route!.query = { sort_by: "title" };
    const wrapper = await mountWith([doc([], 0)], makeUser("admin"));

    wrapper
      .findComponent({ name: "ListFilterBar" })
      .vm
.$emit("update:sort-order", "asc");
    await flushPromises();

    expect(router.route!.query).toMatchObject({
      sort_by: "title",
      sort_order: "asc",
    });
  });

  // Seeded from the URL, so a refresh or a shared link lands on the same
  // listing the sender was looking at.
  it("sends the term the URL arrived with", async () => {
    router.route!.query = { search: "trip" };
    await mountWith([doc([], 0)], makeUser("admin"));

    expect(feedCalls()[0]).toMatchObject({ search: "trip" });
  });

  // The grid needs the covers; the chip probes deliberately do not.
  it("asks for covers on the grids own request only", async () => {
    await mountIndex(doc([], 0), makeUser("admin"));
    await flushPromises();

    // 1, not true: axios would serialise a boolean as "true", which Laravel's
    // `boolean` rule rejects — a 422 that reads as an empty grid.
    expect(feedCalls()[0]!.cover).toBe(1);
    const probes = fetchDocumentPage.mock.calls
      .map(([params]) => params as ListParams)
      .filter((params) => params.per_page === 1);

    expect(probes.every((params) => params.cover === undefined)).toBe(true);
  });
});

describe("bulk selection", () => {
  function selectToggle(wrapper: Wrapper) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find(
        (button) =>
          button.props("icon") === "mdi-checkbox-multiple-marked-outline",
      );
  }

  async function startSelecting(wrapper: Wrapper) {
    wrapper.findComponent({ name: "ListFilterBar" }).vm.$emit("toggle-selecting");
    await flushPromises();
  }

  async function pickFirst(wrapper: Wrapper) {
    await wrapper.findComponent({ name: "VCard" }).trigger("click");
    await flushPromises();
  }

  // The policy gates delete and restore on the admin role, so a member's
  // selection could act on nothing.
  it("is not offered to a member", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("member"));

    expect(selectToggle(wrapper)).toBeUndefined();
  });

  it("is offered to an admin", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(selectToggle(wrapper)).toBeDefined();
  });

  // In select mode the card *is* the checkbox, so clicking it must pick rather
  // than navigate into the document.
  it("picks instead of navigating while selecting", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    await startSelecting(wrapper);
    await pickFirst(wrapper);

    expect(push).not.toHaveBeenCalled();
    expect(wrapper.findComponent({ name: "BulkActionBar" }).props("count")).toBe(
      1,
    );
  });

  it("deletes every pick and splices them out without refetching", async () => {
    const wrapper = await mountWith(
      [doc([], 0), doc([], 0, { id: 2, title: "Second" })],
      makeUser("admin"),
    );

    await startSelecting(wrapper);
    await pickFirst(wrapper);

    const before = feedCalls().length;
    wrapper.findComponent({ name: "ConfirmDialog" }).vm.$emit("confirm");
    await flushPromises();

    expect(deleteDocument).toHaveBeenCalledExactlyOnceWith(1);
    expect(wrapper.text()).not.toContain("Trip");
    expect(wrapper.text()).toContain("Second");
    // Reloading would throw away every page scrolled so far.
    expect(feedCalls()).toHaveLength(before);
  });

  // Restoring is not destructive, so it fires straight away.
  it("restores every pick from the trash without confirming", async () => {
    router.route!.query = { trashed: "only" };
    const wrapper = await mountWith(
      [doc([], 0, { deleted_at: "2026-08-25T10:00:00.000000Z" })],
      makeUser("admin"),
    );

    await startSelecting(wrapper);
    await pickFirst(wrapper);
    wrapper.findComponent({ name: "BulkActionBar" }).vm.$emit("restore");
    await flushPromises();

    expect(restoreDocument).toHaveBeenCalledExactlyOnceWith(1);
    expect(wrapper.text()).not.toContain("Trip");
  });

  /**
   * A live document can no longer have a trashed team, so a non-null
   * team.deleted_at means this card is waiting for its team to come back — its
   * own restore would be a 409, and the team's restore empties its whole bin.
   */
  describe("waiting on a trashed team", () => {
    const BINNED_TEAM = {
      id: 1,
      name: "Design",
      deleted_at: "2026-08-30T10:00:00.000000Z",
    };

    function inTheTrash(overrides: Partial<Document> = {}) {
      return doc([], 0, {
        deleted_at: "2026-08-25T10:00:00.000000Z",
        ...overrides,
      });
    }

    it("disables the card's restore button and names the team", async () => {
      router.route!.query = { trashed: "only" };
      const wrapper = await mountWith(
        [inTheTrash({ team: BINNED_TEAM })],
        makeUser("admin"),
      );

      const restore = wrapper
        .findAllComponents({ name: "VBtn" })
        .find((button) => button.find(".mdi-restore").exists())!;

      expect(restore.attributes("disabled")).toBeDefined();
      expect(restore.attributes("title")).toContain("Design");
      // The chip is what explains the disabled button on the card itself.
      expect(wrapper.find(".mdi-delete-clock").exists()).toBe(true);
    });

    // canPick() refuses it, so the checkbox never appears and the bulk restore
    // can never be handed an id the endpoint would refuse.
    it("cannot be picked for a bulk restore", async () => {
      router.route!.query = { trashed: "only" };
      const wrapper = await mountWith(
        [inTheTrash({ team: BINNED_TEAM })],
        makeUser("admin"),
      );

      await startSelecting(wrapper);

      // No checkbox at all, which is what canPick() controls — clicking the
      // card in select mode falls through to opening it, as it does for any
      // other unpickable card.
      expect(
        wrapper.findComponent({ name: "VCheckboxBtn" }).exists(),
      ).toBe(false);

      await pickFirst(wrapper);
      wrapper.findComponent({ name: "BulkActionBar" }).vm.$emit("restore");
      await flushPromises();

      expect(restoreDocument).not.toHaveBeenCalled();
    });
  });

  // allSettled, not all: one rejection must not abandon the rest, and only the
  // rows that went through leave the grid.
  it("keeps a failed row and reports how many failed", async () => {
    const wrapper = await mountWith(
      [doc([], 0), doc([], 0, { id: 2, title: "Second" })],
      makeUser("admin"),
    );
    const notifier = useNotifierStore();

    deleteDocument.mockRejectedValueOnce(new Error("nope"));

    await startSelecting(wrapper);
    await wrapper.findAllComponents({ name: "VCard" })[0]!.trigger("click");
    await wrapper.findAllComponents({ name: "VCard" })[1]!.trigger("click");
    await flushPromises();

    wrapper.findComponent({ name: "ConfirmDialog" }).vm.$emit("confirm");
    await flushPromises();

    expect(wrapper.text()).toContain("Trip");
    expect(wrapper.text()).not.toContain("Second");
    expect(notifier.notify).toHaveBeenCalledWith(
      "Some documents could not be deleted (1)",
      "error",
    );
  });

  // A chip change can flip the action from delete to restore, so the mode is
  // dropped with it — unlike a search or sort change.
  it("leaves selection mode when the chip changes", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    await startSelecting(wrapper);
    expect(
      wrapper.findComponent({ name: "ListFilterBar" }).props("selecting"),
    ).toBe(true);

    wrapper
      .findComponent({ name: "ListFilterBar" })
      .vm
.$emit("update:modelValue", "trash");
    await flushPromises();

    expect(
      wrapper.findComponent({ name: "ListFilterBar" }).props("selecting"),
    ).toBe(false);
  });
});

describe("card timestamp", () => {
  // A bare relative time is ambiguous once it can mean two things, so the label
  // travels with it.
  it("labels the live listings time as an update", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(wrapper.text()).toContain("last updated");
  });

  it("shows deleted_at, labelled, inside the trash", async () => {
    router.route!.query = { trashed: "only" };
    const wrapper = await mountWith(
      [doc([], 0, { deleted_at: "2026-08-25T10:00:00.000000Z" })],
      makeUser("admin"),
    );

    expect(wrapper.text()).toContain("deleted");
    expect(wrapper.text()).not.toContain("last updated");
  });
});

describe("paging", () => {
  // Appends rather than replaces, so the pages already scrolled through stay.
  it("appends the next page when the sentinel comes into view", async () => {
    feedQueue = [
      { items: [doc([], 0)], total: 2, lastPage: 2 },
      { items: [doc([], 0, { id: 2, title: "Second" })], total: 2, lastPage: 2 },
    ];
    const wrapper = mountPage(makeUser("admin"));
    await flushPromises();

    intersect!();
    await flushPromises();

    expect(feedCalls().at(-1)).toMatchObject({ page: 2 });
    expect(wrapper.text()).toContain("Trip");
    expect(wrapper.text()).toContain("Second");
  });

  // The sentinel is torn down at the end of the listing rather than left to
  // fire requests that would return nothing.
  it("stops rendering the sentinel on the last page", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));

    expect(wrapper.find("[ref=sentinel]").exists()).toBe(false);
  });
});

describe("dialog wiring", () => {
  function editButton(wrapper: Wrapper) {
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
      wrapper
        .findComponent({ name: "DocumentCreateDialog" })
        .props("lockedTeamId"),
    ).toBe(1);
  });

  it("leaves the create dialog unlocked for a super admin", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("super-admin"));

    expect(
      wrapper
        .findComponent({ name: "DocumentCreateDialog" })
        .props("lockedTeamId"),
    ).toBeUndefined();
  });

  /**
   * .stop on the icon is load-bearing: the whole card carries @click, so
   * without it editing would also navigate into the document.
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

    fetchDocumentPage.mockClear();
    wrapper.findComponent({ name: "DocumentCreateDialog" }).vm.$emit("created");
    await flushPromises();

    expect(feedCalls()).toHaveLength(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document created");
  });

  it("refetches and notifies after an edit", async () => {
    const wrapper = await mountIndex(doc([], 0), makeUser("admin"));
    const notifier = useNotifierStore();

    await editButton(wrapper).trigger("click");
    fetchDocumentPage.mockClear();
    wrapper.findComponent({ name: "DocumentEditDialog" }).vm.$emit("updated");
    await flushPromises();

    expect(feedCalls()).toHaveLength(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document updated");
  });
});
