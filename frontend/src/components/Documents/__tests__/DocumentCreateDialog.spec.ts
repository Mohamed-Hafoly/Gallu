import type { Team } from "@/types/team";
import type { User } from "@/types/user";
import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentCreateDialog from "@/components/Documents/DocumentCreateDialog.vue";
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

const { createDocument, fetchPickerTeams } = vi.hoisted(() => ({
  createDocument: vi.fn(),
  fetchPickerTeams: vi.fn(),
}));

vi.mock("@/stores/document", () => ({
  useDocumentStore: () => ({ createDocument }),
}));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
}));

const TEAMS = [
  { id: 1, name: "Design" },
  { id: 2, name: "Engineering" },
] as Team[];

function makeUser(): User {
  return {
    id: 7,
    name: "Grace Hopper",
    email: "grace@example.com",
    avatar_url: "http://localhost/images/default-avatar.jpg",
    avatar_thumb_url: "http://localhost/images/default-avatar.jpg",
    has_avatar: false,
    default_avatar_url: "http://localhost/images/default-avatar.jpg",
    created_at: "2026-08-01T10:00:00Z",
    updated_at: "2026-08-15T10:00:00Z",
    is_super_admin: true,
    role: "super-admin",
    team: null,
  };
}

async function mountDialog() {
  const wrapper = mountWithPlugins(
    DocumentCreateDialog,
    { props: { modelValue: true } },
    { auth: { user: makeUser() } },
  );
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountDialog>>;

/** The dialog teleports to document.body, so fields are found by component. */
function fields(wrapper: Wrapper) {
  return wrapper.findComponent({ name: "DocumentFields" });
}

async function fillIn(
  wrapper: Wrapper,
  { title, teamId }: { title: string; teamId: number | null },
) {
  fields(wrapper).vm.$emit("update:title", title);
  fields(wrapper).vm.$emit("update:teamId", teamId);
  await flushPromises();
}

function submitButton(wrapper: Wrapper) {
  return wrapper
    .findAllComponents({ name: "VBtn" })
    .find((button) =>
      button.text().includes(i18n.global.t("common.create") as string),
    )!;
}

beforeEach(() => {
  i18n.global.locale.value = "en";
  createDocument.mockReset();
  fetchPickerTeams.mockReset();
  fetchPickerTeams.mockResolvedValue(TEAMS);
  createDocument.mockResolvedValue({ id: 1 });
});

describe("DocumentCreateDialog", () => {
  it("loads the team options from the picker endpoint", async () => {
    const wrapper = await mountDialog();

    expect(fetchPickerTeams).toHaveBeenCalled();

    const autocomplete = wrapper.findComponent({ name: "VAutocomplete" });
    expect(autocomplete.props("items")).toEqual([
      { title: "Design", value: 1 },
      { title: "Engineering", value: 2 },
    ]);
  });

  // A document must belong to a team, so unlike the user dialog's team select
  // there is no "no team" entry to fall back on.
  it("offers no null team option", async () => {
    const wrapper = await mountDialog();

    const items = wrapper.findComponent({ name: "VAutocomplete" }).props("items") as {
      value: number | null;
    }[];

    expect(items.some((item) => item.value === null)).toBe(false);
  });

  it("does not submit while the team is unset", async () => {
    const wrapper = await mountDialog();
    await fillIn(wrapper, { title: "Quarterly report", teamId: null });

    await submitButton(wrapper).trigger("click");
    await flushPromises();

    expect(createDocument).not.toHaveBeenCalled();
  });

  it("creates the document with a trimmed title and the picked team, then closes", async () => {
    const wrapper = await mountDialog();
    await fillIn(wrapper, { title: "  Quarterly report  ", teamId: 2 });

    await submitButton(wrapper).trigger("click");
    await flushPromises();

    expect(createDocument).toHaveBeenCalledWith({
      title: "Quarterly report",
      // An empty description is omitted rather than sent as "".
      description: undefined,
      team_id: 2,
    });
    expect(wrapper.emitted("created")).toHaveLength(1);
    expect(wrapper.emitted("update:modelValue")!.at(-1)).toEqual([false]);
  });

  it("notifies and stays open when the request fails", async () => {
    createDocument.mockRejectedValue(new Error("500"));
    const wrapper = await mountDialog();
    const notifier = useNotifierStore();
    await fillIn(wrapper, { title: "Quarterly report", teamId: 1 });

    await submitButton(wrapper).trigger("click");
    await flushPromises();

    expect(notifier.notify).toHaveBeenCalledWith(
      i18n.global.t("admin.documents.createFailed"),
      "error",
    );
    expect(wrapper.emitted("created")).toBeUndefined();
    expect(wrapper.emitted("update:modelValue")).toBeUndefined();
  });
});
