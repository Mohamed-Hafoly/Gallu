import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import DocumentFields from "@/components/Documents/DocumentFields.vue";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const { fetchPickerTeams } = vi.hoisted(() => ({ fetchPickerTeams: vi.fn() }));

vi.mock("@/stores/team", () => ({
  useTeamStore: () => ({ fetchPickerTeams }),
}));

beforeEach(() => {
  fetchPickerTeams.mockReset();
  fetchPickerTeams.mockResolvedValue([{ id: 1, name: "Design" }]);
});

async function mountFields(props: Record<string, unknown> = {}) {
  const wrapper = mountWithPlugins(DocumentFields, { props });
  await flushPromises();
  return wrapper;
}

/** The title input is the first text field; the description is a textarea. */
function titleField(wrapper: Awaited<ReturnType<typeof mountFields>>) {
  return wrapper.findComponent({ name: "VTextField" });
}

describe("DocumentFields", () => {
  /**
   * The per-team uniqueness rule lives only on the server, so a collision
   * arrives as a 422 rather than a local rule. It has to land on the field, not
   * just in a toast.
   */
  it("shows a server-side error on the title input", async () => {
    const wrapper = await mountFields({
      title: "Q3 Report",
      titleError: "This team already has a document with that title.",
    });

    expect(titleField(wrapper).props("errorMessages")).toBe(
      "This team already has a document with that title.",
    );
    expect(wrapper.text()).toContain(
      "This team already has a document with that title.",
    );
  });

  // error-messages must not displace the local rules — Vuetify composes the
  // two, so required/max still fire while a server message is on screen.
  it("keeps the local rules alongside a server error", async () => {
    const wrapper = await mountFields({ title: "", titleError: "Taken" });

    const rules = titleField(wrapper).props("rules");

    expect(Array.isArray(rules)).toBe(true);
    expect(rules).toHaveLength(2);
  });

  // Vuetify normalises an absent error-messages to [], so the assertion is on
  // the rendered state rather than on the prop being undefined.
  it("renders no error state when none is given", async () => {
    const wrapper = await mountFields({ title: "Q3 Report" });

    expect(wrapper.find(".v-input--error").exists()).toBe(false);
  });

  /**
   * The /documents page files a document under the caller's own team and never
   * lets it be moved, so the field has nothing to offer there — and a hidden
   * field must not cost a picker request.
   */
  it("drops the team select and its request under hideTeam", async () => {
    const wrapper = await mountFields({ title: "Q3 Report", hideTeam: true });

    expect(wrapper.findComponent({ name: "VAutocomplete" }).exists()).toBe(false);
    expect(fetchPickerTeams).not.toHaveBeenCalled();
  });

  it("shows the team select and loads the picker by default", async () => {
    const wrapper = await mountFields({ title: "Q3 Report" });

    expect(wrapper.findComponent({ name: "VAutocomplete" }).exists()).toBe(true);
    expect(fetchPickerTeams).toHaveBeenCalled();
  });

  it("emits the title upward so the dialog can clear its error", async () => {
    const wrapper = await mountFields({ title: "Q3 Report" });

    titleField(wrapper).vm.$emit("update:modelValue", "Q3 Report v2");
    await flushPromises();

    expect(wrapper.emitted("update:title")).toBeTruthy();
    expect(wrapper.emitted("update:title")!.at(-1)).toEqual(["Q3 Report v2"]);
  });
});
