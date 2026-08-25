import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it } from "vitest";
import { mountWithPlugins } from "@/__tests__/helpers/mountWithPlugins";
import AdminDocuments from "@/pages/admin/documents.vue";
import i18n from "@/plugins/i18n";

/**
 * The screen runs on an inline fixture rather than the API, so these cover the
 * simulated server — filter, then sort, then slice, with the total taken from
 * the filtered length — plus the expansion that lists a document's images.
 *
 * They are written against behaviour the real endpoint will have to reproduce,
 * so they should survive the fixture being swapped for a request.
 */

/** The fixture's fake latency (250ms) plus a margin, since flushPromises does
 *  not advance timers. */
const FETCH_DELAY = 400;
/** The search box debounces for 300ms before it even starts fetching. */
const SEARCH_DELAY = FETCH_DELAY + 350;

function settle(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function mountPage() {
  const wrapper = mountWithPlugins(AdminDocuments);
  await settle(FETCH_DELAY);
  await flushPromises();
  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>;

function table(wrapper: Wrapper) {
  return wrapper.findComponent({ name: "VDataTableServer" });
}

/** The footer's "1-10 of 35" line, which is fed by :items-length. */
function footerInfo(wrapper: Wrapper) {
  return wrapper.find(".v-data-table-footer__info").text();
}

function bodyRows(wrapper: Wrapper) {
  return wrapper.findAll("tbody tr");
}

/**
 * Clicks a row's expand toggle. Driving the model directly is not equivalent:
 * v-data-table declares the expansion model as `readonly string[]` but writes
 * the raw item value — a number — into it, so an emitted `["1"]` matches no row.
 *
 * The toggle is the last button in the row, because the expand column is
 * appended after `actions`.
 */
async function expand(wrapper: Wrapper, rowIndex: number) {
  const buttons = bodyRows(wrapper)[rowIndex].findAll("button");
  await buttons.at(-1)!.trigger("click");
  await flushPromises();
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("admin documents listing", () => {
  it("serves the first page and reports the full total", async () => {
    const wrapper = await mountPage();

    expect(bodyRows(wrapper)).toHaveLength(10);
    expect(footerInfo(wrapper)).toContain("35");
    // Default order is the fixture's own, so ids 1..10.
    expect(bodyRows(wrapper)[0].text()).toContain("#1");
  });

  it("sorts server-side on the key the table emits", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 1,
      itemsPerPage: 10,
      sortBy: [{ key: "id", order: "desc" }],
    });
    await settle(FETCH_DELAY);
    await flushPromises();

    expect(bodyRows(wrapper)[0].text()).toContain("#35");
    // Sorting is not filtering: the total must not move.
    expect(footerInfo(wrapper)).toContain("35");
  });

  it("narrows the total when searching, and takes the total from the filtered set", async () => {
    const wrapper = await mountPage();

    await wrapper.findComponent({ name: "VTextField" }).setValue("Design");
    await settle(SEARCH_DELAY);
    await flushPromises();

    const total = Number(footerInfo(wrapper).split("of", 2)[1].trim());
    expect(total).toBeGreaterThan(0);
    expect(total).toBeLessThan(35);
  });

  // Searching from a later page would otherwise land on an empty page of a much
  // shorter result set.
  it("returns to page one when the search changes", async () => {
    const wrapper = await mountPage();

    table(wrapper).vm.$emit("update:options", {
      page: 3,
      itemsPerPage: 10,
      sortBy: [],
    });
    await settle(FETCH_DELAY);
    await flushPromises();
    expect(footerInfo(wrapper)).toContain("21-30");

    await wrapper.findComponent({ name: "VTextField" }).setValue("Design");
    await settle(SEARCH_DELAY);
    await flushPromises();

    expect(footerInfo(wrapper).startsWith("1-")).toBe(true);
  });
});

describe("expanded image sub-rows", () => {
  // The point of the nested table: a document's creator and an image's creator
  // are separate columns, and an image uploaded by a teammate must show theirs.
  it("lists each image with its own creator, not the document's", async () => {
    const wrapper = await mountPage();
    // Row 0 is document 1, which the fixture seeds with four images — one of
    // them uploaded by somebody else.
    await expand(wrapper, 0);

    const nested = wrapper.findComponent({ name: "VDataTable" });
    expect(nested.exists()).toBe(true);

    const images = nested.props("items") as { creator: string }[];
    const documentCreator = (
      table(wrapper).props("items") as { id: number; creator: string }[]
    ).find((row) => row.id === 1)!.creator;

    expect(images.length).toBeGreaterThan(2);
    expect(images.some((image) => image.creator !== documentCreator)).toBe(true);
    // Every creator is rendered, so the divergence is actually visible.
    for (const image of images) {
      expect(nested.text()).toContain(image.creator);
    }
  });

  it("shows a fallback line instead of an empty table for a document with no images", async () => {
    const wrapper = await mountPage();
    // Row 6 is document 7; ids divisible by seven are seeded with no images.
    await expand(wrapper, 6);

    expect(wrapper.findComponent({ name: "VDataTable" }).exists()).toBe(false);
    expect(wrapper.text()).toContain(
      i18n.global.t("admin.documents.noImages") as string,
    );
  });
});
