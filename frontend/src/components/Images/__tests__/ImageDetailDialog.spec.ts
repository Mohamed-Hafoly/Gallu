import type { Image } from "@/types/image";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import ImageDetailDialog from "@/components/Images/ImageDetailDialog.vue";
import i18n from "@/plugins/i18n";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// The dialog reads the auth store, which imports the real router module.
vi.mock("@/plugins/router", () => ({ default: { replace: vi.fn() } }));

vi.mock("@/stores/image", () => ({
  useImageStore: () => ({ updateImage: vi.fn(), deleteImage: vi.fn() }),
}));

vi.mock("@/stores/category", () => ({
  useCategoryStore: () => ({
    fetchPickerCategories: vi.fn().mockResolvedValue([]),
  }),
}));

function makeUser(role: "super-admin" | "admin" | "member"): User {
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
    team: role === "super-admin" ? null : { id: 1, name: "Design" },
  };
}

function makeImage(overrides: Partial<Image> = {}): Image {
  return {
    id: 42,
    title: "Front cover",
    description: "A cover",
    url: "https://example.test/42.jpg",
    thumb_url: "https://example.test/42-thumb.jpg",
    categories: [],
    document_id: 7,
    // Deliberately not makeUser's id 7: "somebody else's image" is the default,
    // so a test that wants ownership has to say so.
    user_id: 99,
    creator: "Ada Lovelace",
    created_at: "2026-08-01T10:00:00.000000Z",
    updated_at: "2026-08-20T14:30:00.000000Z",
    ...overrides,
  };
}

/** The dialog teleports, so its fields are queried through the component tree. */
async function mountDialog(
  role: "super-admin" | "admin" | "member" = "member",
  image = makeImage(),
) {
  const wrapper = mountWithPlugins(
    ImageDetailDialog,
    { props: { image, modelValue: true } },
    { auth: { user: makeUser(role) } },
  );
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountDialog>>;

/** Every read-only row's label, in render order. */
function labels(wrapper: Wrapper) {
  return wrapper
    .findAllComponents({ name: "ReadOnlyField" })
    .map((field) => field.props("label") as string);
}

function fieldFor(wrapper: Wrapper, label: string) {
  return wrapper
    .findAllComponents({ name: "ReadOnlyField" })
    .find((field) => field.props("label") === label);
}

beforeEach(() => {
  vi.clearAllMocks();
  // The app defaults to Arabic, and these assert on label text.
  i18n.global.locale.value = "en";
});

describe("read-only rows", () => {
  it("shows when the image was created and last updated", async () => {
    const wrapper = await mountDialog();

    expect(labels(wrapper)).toContain("Created At");
    expect(labels(wrapper)).toContain("Last updated");
    expect(fieldFor(wrapper, "Last updated")!.props("value")).toContain("2026");
  });

  // Created reads before updated reads before deleted, so the row order tells a
  // chronological story rather than an arbitrary one.
  it("orders them created, updated, deleted", async () => {
    const wrapper = await mountDialog(
      "member",
      makeImage({ deleted_at: "2026-08-25T09:00:00.000000Z" }),
    );

    const order = labels(wrapper);

    expect(order.indexOf("Created At")).toBeLessThan(order.indexOf("Last updated"));
    expect(order.indexOf("Last updated")).toBeLessThan(order.indexOf("Deleted"));
  });

  it("hides the deleted row for a live image", async () => {
    const wrapper = await mountDialog();

    expect(labels(wrapper)).not.toContain("Deleted");
  });

  it("shows the deleted row once the image is trashed", async () => {
    const wrapper = await mountDialog(
      "member",
      makeImage({ deleted_at: "2026-08-25T09:00:00.000000Z" }),
    );

    expect(fieldFor(wrapper, "Deleted")!.props("value")).toContain("2026");
  });
});

/**
 * Cosmetic only — the id is in the payload every caller already receives, so
 * this hides a row rather than protecting anything.
 */
describe("the id row", () => {
  it("is shown to a super admin, between the title and the creator", async () => {
    const wrapper = await mountDialog("super-admin");

    expect(fieldFor(wrapper, "ID")!.props("value")).toBe("42");
    expect(labels(wrapper).indexOf("ID")).toBeLessThan(
      labels(wrapper).indexOf("Creator"),
    );
  });

  it("is hidden from an admin and a member", async () => {
    expect(labels(await mountDialog("admin"))).not.toContain("ID");
    expect(labels(await mountDialog("member"))).not.toContain("ID");
  });
});

/**
 * The rows mirror the rest of the form: plain text in view mode, a disabled
 * input while editing. Asserted through the dialog rather than through
 * ReadOnlyField alone, since what matters is that `:editable` is actually wired
 * to each new row rather than left hard-coded.
 */
describe("edit mode", () => {
  async function startEditing(wrapper: Wrapper) {
    const edit = wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.text().includes("Edit"))!;

    await edit.trigger("click");
    await flushPromises();
  }

  it("renders every row as plain text before editing", async () => {
    const wrapper = await mountDialog("super-admin");

    for (const field of wrapper.findAllComponents({ name: "ReadOnlyField" })) {
      expect(field.props("editable")).toBeFalsy();
    }
  });

  it("turns every row into a disabled input while editing", async () => {
    const wrapper = await mountDialog("super-admin");

    await startEditing(wrapper);

    const fields = wrapper.findAllComponents({ name: "ReadOnlyField" });

    expect(fields.length).toBeGreaterThan(0);

    for (const field of fields) {
      expect(field.props("editable")).toBe(true);
      expect(field.findComponent({ name: "VTextField" }).props("disabled")).toBe(
        true,
      );
    }
  });

  // None of them touches `form` or `original`, so none can enable Save.
  it("leaves save disabled, since no read-only row can dirty the form", async () => {
    const wrapper = await mountDialog("super-admin");

    await startEditing(wrapper);

    const save = wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.text().includes("Save"))!;

    expect(save.props("disabled")).toBe(true);
  });
});

/**
 * Mirrors ImagePolicy::update — the owner, or a team admin. Cosmetic: the
 * server already refuses, so this is about not offering a button that 403s.
 *
 * makeImage defaults to user_id 99 while makeUser is id 7, so "somebody else's
 * image" is the default and ownership has to be opted into.
 */
describe("edit and delete affordances", () => {
  function actions(wrapper: Wrapper) {
    return wrapper.findComponent({ name: "VCardActions" });
  }

  function buttonWith(wrapper: Wrapper, icon: string) {
    return wrapper
      .findAllComponents({ name: "VBtn" })
      .find((button) => button.props("prependIcon") === icon);
  }

  it("offers a member neither on a teammate's image", async () => {
    const wrapper = await mountDialog("member");

    expect(buttonWith(wrapper, "mdi-pencil")).toBeUndefined();
    expect(buttonWith(wrapper, "mdi-delete")).toBeUndefined();
  });

  // Gating the buttons alone would leave the bar itself behind, empty.
  it("leaves no empty action bar behind", async () => {
    const wrapper = await mountDialog("member");

    expect(actions(wrapper).exists()).toBe(false);
  });

  it("offers a member both on their own image", async () => {
    const wrapper = await mountDialog("member", makeImage({ user_id: 7 }));

    expect(buttonWith(wrapper, "mdi-pencil")).toBeDefined();
    expect(buttonWith(wrapper, "mdi-delete")).toBeDefined();
  });

  it("offers an admin both on a teammate's image", async () => {
    const wrapper = await mountDialog("admin");

    expect(buttonWith(wrapper, "mdi-pencil")).toBeDefined();
    expect(buttonWith(wrapper, "mdi-delete")).toBeDefined();
  });

  it("offers a super admin both on anyone's", async () => {
    const wrapper = await mountDialog("super-admin");

    expect(buttonWith(wrapper, "mdi-pencil")).toBeDefined();
    expect(buttonWith(wrapper, "mdi-delete")).toBeDefined();
  });

  // A trashed image can only be restored, whoever is looking at it.
  it("offers the owner neither once the image is trashed", async () => {
    const wrapper = await mountDialog(
      "member",
      makeImage({ user_id: 7, deleted_at: "2026-08-25T09:00:00.000000Z" }),
    );

    expect(actions(wrapper).exists()).toBe(false);
  });
});
