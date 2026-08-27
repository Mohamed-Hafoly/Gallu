import type { Document } from "@/types/document";
import type { Team } from "@/types/team";
import { flushPromises } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentEditDialog from "@/components/Documents/DocumentEditDialog.vue";
import i18n from "@/plugins/i18n";
import { useNotifierStore } from "@/stores/notifier";

// The stores import the axios client, and the router's HMR hook throws under
// vitest — stubbed the same way the other dialog specs do it.
vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock("@/plugins/router", () => ({
  default: { replace: vi.fn() },
}));

const { updateDocument, fetchPickerTeams } = vi.hoisted(() => ({
  updateDocument: vi.fn(),
  fetchPickerTeams: vi.fn(),
}));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({ updateDocument }),
}));

// DocumentFields loads the picker onMounted.
vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
}));

const TEAMS = [
  { id: 1, name: "Design" },
  { id: 2, name: "Engineering" },
] as Team[];

const document_: Document = {
  id: 9,
  title: "Quarterly report",
  description: "Numbers",
  images_count: 3,
  creator: "Ada Lovelace",
  team: { id: 1, name: "Design" },
  created_at: "2026-08-01T10:00:00.000000Z",
  updated_at: "2026-08-15T10:00:00.000000Z",
  deleted_at: null,
};

/**
 * The dialog teleports to document.body, so it is queried there. The read-only
 * id, creator and both timestamps come first, so the title is the fifth input.
 */
function titleInput() {
  return [...document.body.querySelectorAll("input")][4] as HTMLInputElement;
}

function descriptionInput() {
  return document.body.querySelector("textarea") as HTMLTextAreaElement;
}

/** Found by role rather than icon, so swapping the icon can't break the spec. */
function saveButton() {
  return document.body.querySelector(
    "button[type='submit']",
  ) as HTMLButtonElement;
}

/** Vue only reacts to input events, not to assigning `.value` directly. */
function type(
  input: HTMLInputElement | HTMLTextAreaElement,
  value: string,
  proto: typeof HTMLInputElement | typeof HTMLTextAreaElement = HTMLInputElement,
) {
  const setter = Object.getOwnPropertyDescriptor(proto.prototype, "value")!.set!;
  setter.call(input, value);
  input.dispatchEvent(new Event("input", { bubbles: true }));
}

function mountDialog(props: Record<string, unknown> = {}) {
  return mountWithPlugins(DocumentEditDialog, {
    props: { document: document_, modelValue: true, ...props },
  });
}

let wrapper: ReturnType<typeof mountDialog>;

beforeEach(() => {
  i18n.global.locale.value = "en";
  updateDocument.mockReset();
  fetchPickerTeams.mockReset();
  fetchPickerTeams.mockResolvedValue(TEAMS);
  updateDocument.mockResolvedValue(document_);
});

afterEach(() => {
  wrapper?.unmount();
  document.body.innerHTML = "";
});

describe("DocumentEditDialog", () => {
  it("prefills the read-only metadata and the editable fields", async () => {
    wrapper = mountDialog();
    await flushPromises();

    const values = [...document.body.querySelectorAll("input")].map(
      (input) => input.value,
    );

    // id and creator, then the two timestamps, then the title.
    expect(values[0]).toBe("9");
    expect(values[1]).toBe("Ada Lovelace");
    expect(titleInput().value).toBe("Quarterly report");
    expect(descriptionInput().value).toBe("Numbers");
    // The team autocomplete shows the document's current team.
    expect(document.body.textContent).toContain("Design");
  });

  it("keeps Save disabled until something actually changes", async () => {
    wrapper = mountDialog();
    await flushPromises();

    expect(saveButton().disabled).toBe(true);

    type(titleInput(), "Annual report");
    await flushPromises();

    expect(saveButton().disabled).toBe(false);
  });

  it("does not count a whitespace-only change as dirty", async () => {
    wrapper = mountDialog();
    await flushPromises();

    type(titleInput(), "Quarterly report  ");
    await flushPromises();

    expect(saveButton().disabled).toBe(true);
  });

  // Regression guard: the dialog stays mounted between openings, and the page
  // passes the same object back, so a watcher on `document` alone never fires.
  it("discards an abandoned edit when reopened", async () => {
    wrapper = mountDialog();
    await flushPromises();

    type(titleInput(), "ABANDONED");
    await flushPromises();

    await wrapper.setProps({ modelValue: false });
    await flushPromises();
    await wrapper.setProps({ modelValue: true });
    await flushPromises();

    expect(titleInput().value).toBe("Quarterly report");
    expect(saveButton().disabled).toBe(true);
  });

  it("saves the trimmed fields with the team, then closes", async () => {
    wrapper = mountDialog();
    await flushPromises();

    type(titleInput(), "  Annual report  ");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(updateDocument).toHaveBeenCalledWith(9, {
      title: "Annual report",
      description: "Numbers",
      team_id: 1,
    });
    expect(wrapper.emitted("updated")).toHaveLength(1);
    expect(wrapper.emitted("update:modelValue")!.at(-1)).toEqual([false]);
  });

  it("notifies and stays open when the request fails", async () => {
    updateDocument.mockRejectedValue(new Error("500"));
    wrapper = mountDialog();
    await flushPromises();
    const notifier = useNotifierStore();

    type(titleInput(), "Annual report");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(notifier.notify).toHaveBeenCalledWith(
      i18n.global.t("admin.documents.updateFailed"),
      "error",
    );
    expect(wrapper.emitted("updated")).toBeUndefined();
    expect(wrapper.emitted("update:modelValue")).toBeUndefined();
  });
});

/**
 * On /documents a document may be renamed but never moved between teams — that
 * stays an /admin/documents action, which is where the picker lives.
 */
describe("locked team", () => {
  it("drops the team field and submits the documents own team", async () => {
    wrapper = mountDialog({ lockedTeamId: 1 });
    await flushPromises();

    expect(
      wrapper.findComponent({ name: "DocumentFields" }).props("hideTeam"),
    ).toBe(true);

    type(titleInput(), "Renamed");
    await flushPromises();

    saveButton().click();
    await flushPromises();

    expect(updateDocument).toHaveBeenCalledWith(
      9,
      expect.objectContaining({ title: "Renamed", team_id: 1 }),
    );
  });

  /**
   * isDirty compares the team as well as the text. With the field locked that
   * half can never change, so Save has to key off the title and description
   * alone — otherwise it would be unreachable here.
   */
  it("still enables save on a title change alone", async () => {
    wrapper = mountDialog({ lockedTeamId: 1 });
    await flushPromises();

    expect(saveButton().disabled).toBe(true);

    type(titleInput(), "Renamed");
    await flushPromises();

    expect(saveButton().disabled).toBe(false);
  });

  it("keeps the picker when no team is locked", async () => {
    wrapper = mountDialog();
    await flushPromises();

    expect(
      wrapper.findComponent({ name: "DocumentFields" }).props("hideTeam"),
    ).toBe(false);
  });
});
