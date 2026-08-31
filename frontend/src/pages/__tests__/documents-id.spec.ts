import type { Document } from "@/types/document";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentDetail from "@/pages/documents/[id].vue";
import i18n from "@/plugins/i18n";
import { useNotifierStore } from "@/stores/notifier";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// The page reads the route param, and the auth store imports the real router
// module — which would build a router against a partially mocked vue-router.
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock("vue-router", () => ({
  useRoute: () => ({ params: { id: "1" }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace }),
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const { fetchDocument, fetchPickerTeams, deleteDocument } = vi.hoisted(() => ({
  fetchDocument: vi.fn(),
  fetchPickerTeams: vi.fn(),
  deleteDocument: vi.fn(),
}));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({ fetchDocument, deleteDocument }),
}));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
}));

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

function makeDocument(overrides: Partial<Document> = {}): Document {
  return {
    id: 1,
    title: "Quarterly report",
    description: "Numbers",
    images: [],
    images_count: 0,
    creator: "Ada Lovelace",
    team: OWN_TEAM,
    created_at: "2026-08-24T10:00:00.000000Z",
    updated_at: "2026-08-24T10:00:00.000000Z",
    deleted_at: null,
    ...overrides,
  };
}

/**
 * ImageGallery is stubbed: it owns its own paging, chips and dialogs, and has
 * a spec of its own. Rendering it here would mean mocking the image and
 * category stores just to reach a header none of that touches.
 */
async function mountDetail(user?: User, document_ = makeDocument()) {
  fetchDocument.mockResolvedValue(document_);

  const wrapper = mountWithPlugins(
    DocumentDetail,
    { global: { stubs: { ImageGallery: true } } },
    user ? { auth: { user } } : undefined,
  );
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountDetail>>;

function editButton(wrapper: Wrapper) {
  return wrapper
    .findAllComponents({ name: "VBtn" })
    .find((button) => button.props("icon") === "mdi-pencil");
}

function deleteButton(wrapper: Wrapper) {
  return wrapper
    .findAllComponents({ name: "VBtn" })
    .find((button) => button.props("icon") === "mdi-delete");
}

function confirmDialog(wrapper: Wrapper) {
  return wrapper.findComponent({ name: "ConfirmDialog" });
}

beforeEach(() => {
  vi.clearAllMocks();
  i18n.global.locale.value = "en";
  fetchPickerTeams.mockResolvedValue([OWN_TEAM]);
  deleteDocument.mockResolvedValue(undefined);
});

describe("document header", () => {
  it("renders the document once loaded", async () => {
    const wrapper = await mountDetail(makeUser("member"));

    expect(wrapper.find("h1").text()).toBe("Quarterly report");
    expect(fetchDocument).toHaveBeenCalledWith(1);
  });

  // A deep link to another team's document is a 403 from DocumentPolicy.
  it("reports a forbidden document instead of an empty header", async () => {
    fetchDocument.mockRejectedValue(new Error("403"));

    const wrapper = mountWithPlugins(
      DocumentDetail,
      { global: { stubs: { ImageGallery: true } } },
      { auth: { user: makeUser("admin") } },
    );
    await flushPromises();

    expect(wrapper.text()).toContain("You do not have access to this document.");
    expect(wrapper.find("h1").exists()).toBe(false);
    expect(editButton(wrapper)).toBeUndefined();
  });
});

/**
 * The same rule the documents list applies to its cards, on the page you
 * actually land on after clicking one. Cosmetic — DocumentPolicy is what
 * denies — but a button that always 403s is worse than no button.
 */
describe("edit affordance", () => {
  it("offers a member no edit button", async () => {
    const wrapper = await mountDetail(makeUser("member"));

    expect(editButton(wrapper)).toBeUndefined();
  });

  it("offers an admin an edit button", async () => {
    const wrapper = await mountDetail(makeUser("admin"));

    expect(editButton(wrapper)).toBeDefined();
  });

  it("offers a super admin an edit button", async () => {
    const wrapper = await mountDetail(makeUser("super-admin"));

    expect(editButton(wrapper)).toBeDefined();
  });

  it("hides it on another teams document", async () => {
    const theirs = makeDocument({ team: { id: 99, name: "Other" } });
    const wrapper = await mountDetail(makeUser("admin"), theirs);

    expect(editButton(wrapper)).toBeUndefined();
  });

  // Nothing to lock the dialog to, and this page never offers the team picker,
  // so the save would 422 on a field the form does not render.
  it("hides it on a team less document, even for a super admin", async () => {
    const orphan = makeDocument({ team: null });
    const wrapper = await mountDetail(makeUser("super-admin"), orphan);

    expect(editButton(wrapper)).toBeUndefined();
  });

  // Primary, unlike its neighbours: upload is tertiary and delete is error, so
  // the three actions in the row are told apart by colour. Size is deliberately
  // not asserted — it is a taste knob, and pinning it here would turn every
  // visual tweak into a failing test.
  it("is the primary action in the row", async () => {
    const wrapper = await mountDetail(makeUser("admin"));
    const button = editButton(wrapper)!;

    expect(button.props("color")).toBe("primary");
    expect(button.props("variant")).toBe("flat");
  });
});

describe("edit dialog", () => {
  it("locks the dialog to the documents own team", async () => {
    const wrapper = await mountDetail(makeUser("admin"));

    const dialog = wrapper.findComponent({ name: "DocumentEditDialog" });

    expect(dialog.exists()).toBe(true);
    expect(dialog.props("document")).toMatchObject({ id: 1 });
    expect(dialog.props("lockedTeamId")).toBe(1);
  });

  it("opens on click", async () => {
    const wrapper = await mountDetail(makeUser("admin"));

    await editButton(wrapper)!.trigger("click");

    expect(
      wrapper.findComponent({ name: "DocumentEditDialog" }).props("modelValue"),
    ).toBe(true);
  });

  /**
   * Refetched rather than patched: the header must show what was stored, and
   * the dialog's watcher only refills on a new object identity.
   */
  it("refetches and notifies after a save", async () => {
    const wrapper = await mountDetail(makeUser("admin"));
    const notifier = useNotifierStore();

    fetchDocument.mockClear();
    fetchDocument.mockResolvedValue(makeDocument({ title: "Renamed" }));

    wrapper.findComponent({ name: "DocumentEditDialog" }).vm.$emit("updated");
    await flushPromises();

    expect(fetchDocument).toHaveBeenCalledTimes(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document updated");
    expect(wrapper.find("h1").text()).toBe("Renamed");
  });
});

/**
 * Deletion is the same permission as editing — DocumentPolicy::delete
 * delegates to ::update — and the same guard against a team-less document.
 */
describe("delete affordance", () => {
  it("offers a member no delete button", async () => {
    const wrapper = await mountDetail(makeUser("member"));

    expect(deleteButton(wrapper)).toBeUndefined();
  });

  it("offers an admin and a super admin a delete button", async () => {
    expect(deleteButton(await mountDetail(makeUser("admin")))).toBeDefined();
    expect(deleteButton(await mountDetail(makeUser("super-admin")))).toBeDefined();
  });

  it("hides it on another teams document", async () => {
    const theirs = makeDocument({ team: { id: 99, name: "Other" } });

    expect(deleteButton(await mountDetail(makeUser("admin"), theirs))).toBeUndefined();
  });

  it("is error coloured and sits beside the edit button", async () => {
    const wrapper = await mountDetail(makeUser("admin"));
    const button = deleteButton(wrapper)!;

    expect(button.props("color")).toBe("error");
    expect(button.props("variant")).toBe("flat");
    // Sized like its neighbour, whatever that size currently is.
    expect(button.props("size")).toBe(editButton(wrapper)!.props("size"));
    expect(button.element.parentElement).toBe(
      editButton(wrapper)!.element.parentElement,
    );
  });
});

describe("delete confirmation", () => {
  it("does not delete without confirming", async () => {
    const wrapper = await mountDetail(makeUser("admin"));

    await deleteButton(wrapper)!.trigger("click");

    expect(confirmDialog(wrapper).props("modelValue")).toBe(true);
    expect(deleteDocument).not.toHaveBeenCalled();
  });

  // The cascade is the whole reason this needs confirming, so the message has
  // to say the images go too — and that both can come back.
  it("warns that the images go with it", async () => {
    const wrapper = await mountDetail(makeUser("admin"));

    const message = confirmDialog(wrapper).props("message") as string;

    expect(message).toContain("Quarterly report");
    expect(message).toContain("images");
    expect(message).toContain("restored");
  });

  it("deletes and leaves for the list once confirmed", async () => {
    const wrapper = await mountDetail(makeUser("admin"));
    const notifier = useNotifierStore();

    await deleteButton(wrapper)!.trigger("click");
    confirmDialog(wrapper).vm.$emit("confirm");
    await flushPromises();

    expect(deleteDocument).toHaveBeenCalledWith(1);
    expect(notifier.notify).toHaveBeenCalledWith("Document deleted");
    // replace, not push: the document is gone, so Back must not return to it.
    expect(replace).toHaveBeenCalledWith({ name: "documents" });
  });

  it("stays put and reports a failed delete", async () => {
    deleteDocument.mockRejectedValue(new Error("403"));

    const wrapper = await mountDetail(makeUser("admin"));
    const notifier = useNotifierStore();

    await deleteButton(wrapper)!.trigger("click");
    confirmDialog(wrapper).vm.$emit("confirm");
    await flushPromises();

    expect(notifier.notify).toHaveBeenCalledWith(
      "Could not delete the document",
      "error",
    );
    expect(replace).not.toHaveBeenCalled();
    expect(wrapper.find("h1").exists()).toBe(true);
  });
});

/**
 * Upload lives inside ImageGallery as a block button, alongside its dialog and
 * the reload that follows one — so this page hands the gallery a document id
 * and reaches for nothing on it. ImageGallery.spec covers the button itself.
 */
describe("the gallery", () => {
  it("is handed the document id and nothing else", async () => {
    const wrapper = await mountDetail(makeUser("member"));
    const gallery = wrapper.findComponent({ name: "ImageGallery" });

    expect(gallery.exists()).toBe(true);
    // The route param, coerced — it is the single source of the id.
    expect(gallery.props("documentId")).toBe(1);
  });
});
