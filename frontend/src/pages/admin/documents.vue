<script setup lang="ts">
  import type { Document } from "@/types/document";
  import type { Image } from "@/types/image";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";

  /** What v-data-table-server hands back on @update:options. */
  interface TableOptions {
    page: number;
    itemsPerPage: number;
    sortBy: { key: string; order?: "asc" | "desc" }[];
  }

  /** The same shape stores/user.ts's UserListParams has, so the eventual store
   *  call takes this object unchanged. */
  interface DocumentListParams {
    page: number;
    per_page: number;
    sort_by?: string;
    sort_order?: "asc" | "desc";
    search?: string;
  }

  /**
   * The admin listing's row. Deliberately declared here rather than widening
   * types/document.ts: that interface documents the real DocumentResource
   * contract — including that its `images` is only the four most recent, for
   * the card grid's cover — and admin-only fields there would make it lie
   * about the API.
   *
   * `images` is the document's whole set, since the expanded sub-row lists all
   * of them. `team` is nullable the way User["team"] is: a document created by
   * a super-admin belongs to no team.
   */
  interface AdminDocument extends Omit<Document, "images"> {
    images: Image[];
    team: { id: number; name: string } | null;
    updated_at: string;
  }

  // ---------------------------------------------------------------------------
  // Mock data. TODO: delete this block and swap fetchPage() for
  // useDocumentStore().fetchDocuments(params) once /api/documents grows an
  // admin index with page/sort/search params and a meta.total.
  // ---------------------------------------------------------------------------

  const CREATORS = [
    "Layla Haddad",
    "Omar Nasser",
    "Sofia Rossi",
    "Tarek Mansour",
    "Hana Yusuf",
    "Daniel Meyer",
  ];

  const TEAMS: AdminDocument["team"][] = [
    { id: 1, name: "Design" },
    { id: 2, name: "Marketing" },
    { id: 3, name: "Field Ops" },
    null,
  ];

  const TITLES = [
    "Site survey — north wing",
    "Brand refresh moodboard",
    "Q3 campaign assets",
    "Warehouse inspection",
    "Product photography raw",
    "Onboarding screenshots",
    "Trade show booth",
    "Roof inspection 2026",
    "Packaging concepts",
    "Team offsite",
    "Fleet condition report",
    "Storefront signage",
  ];

  const DESCRIPTIONS = [
    "Everything captured on the first walkthrough, before any of the remedial work was scheduled or approved.",
    "Reference shots collected from the agency's second round.",
    null,
    "Uploaded straight off the camera — not colour-graded yet.",
    null,
    "Kept for the insurance claim; do not delete before the case closes.",
  ];

  /** Deterministic pseudo-random so the fixture is stable across reloads. */
  function pick<T>(list: T[], seed: number): T {
    return list[(seed * 7 + 3) % list.length];
  }

  function mockImages(docSeed: number, count: number, owner: string): Image[] {
    return Array.from({ length: count }, (_, index) => {
      const seed = docSeed * 100 + index;

      return {
        id: seed,
        // Exactly one Arabic title in the whole fixture — the first image of
        // document 1 — so an RTL string sitting among LTR ones is visible in
        // the nested table without hunting for a real one.
        title:
          docSeed === 1 && index === 0
            ? "بوتفليقة ه"
            : `IMG_${String(4000 + seed)}`,
        description: null,
        url: `https://picsum.photos/seed/${seed}/1200/800`,
        thumb_url: `https://picsum.photos/seed/${seed}/400/400`,
        categories: [],
        document_id: docSeed,
        // Every third image is uploaded by someone other than the document's
        // own creator — the case the nested table exists to make visible.
        creator: index % 3 === 2 ? pick(CREATORS, seed) : owner,
        created_at: new Date(
          Date.UTC(2026, 1 + (seed % 6), 1 + (seed % 27), 9, seed % 60),
        ).toISOString(),
        updated_at: new Date(
          Date.UTC(2026, 1 + (seed % 6), 1 + (seed % 27), 9, seed % 60),
        ).toISOString(),
      };
    });
  }

  const MOCK_DOCUMENTS: AdminDocument[] = Array.from(
    { length: 35 },
    (_, index) => {
      const id = index + 1;
      const creator = pick(CREATORS, id);
      // Roughly every seventh document is empty, so the "no images" branch is
      // reachable without editing the fixture.
      const imageCount = id % 7 === 0 ? 0 : 1 + ((id * 3) % 6);
      const created = new Date(
        Date.UTC(2026, id % 8, 1 + (id % 28), 8, (id * 13) % 60),
      );

      return {
        id,
        title: `${pick(TITLES, id)} #${id}`,
        description: pick(DESCRIPTIONS, id),
        images: mockImages(id, imageCount, creator),
        images_count: imageCount,
        creator,
        team: pick(TEAMS, id),
        created_at: created.toISOString(),
        updated_at: new Date(
          created.getTime() + ((id * 37) % 90) * 86_400_000,
        ).toISOString(),
      };
    },
  );

  /**
   * Stands in for the server: filters, sorts and slices the fixture, returning
   * the same `{ items, total }` that stores/user.ts's fetchUsers does. The
   * total is the *filtered* length, not the fixture's, or the footer would keep
   * offering pages a search has emptied.
   */
  async function fetchPage(
    p: DocumentListParams,
  ): Promise<{ items: AdminDocument[]; total: number }> {
    await new Promise((resolve) => setTimeout(resolve, 250));

    let rows = [...MOCK_DOCUMENTS];

    if (p.search) {
      const term = p.search.toLowerCase();
      rows = rows.filter((row) =>
        [row.title, row.description, row.creator, row.team?.name].some(
          (field) => field?.toLowerCase().includes(term),
        ),
      );
    }

    if (p.sort_by) {
      const direction = p.sort_order === "desc" ? -1 : 1;
      const key = p.sort_by as keyof AdminDocument;

      rows.sort((a, b) => {
        const left = a[key];
        const right = b[key];

        if (typeof left === "number" && typeof right === "number") {
          return (left - right) * direction;
        }

        return (
          String(left ?? "").localeCompare(String(right ?? "")) * direction
        );
      });
    }

    const total = rows.length;
    const start = (p.page - 1) * p.per_page;

    return { items: rows.slice(start, start + p.per_page), total };
  }

  // ---------------------------------------------------------------------------

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatDateTime } = useDateFormat();

  const documents = ref<AdminDocument[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const search = ref("");

  // The page controls this so the search watcher can force a jump back to page
  // one; the table reads it back through :page.
  const page = ref(1);
  const itemsPerPage = ref(10);

  // The last options the table emitted, replayed on every reload so the server
  // stays the source of truth for whatever page is on screen.
  const lastSort = ref<{ key: string; order?: "asc" | "desc" }[]>([]);

  // Ids, because item-value defaults to "id" and return-object is off — same as
  // the users and categories screens.
  const selected = ref<number[]>([]);

  // Typed string[] to satisfy v-data-table's declared `readonly string[]`, even
  // though at runtime it writes the raw item value — a number — straight in.
  // Nothing here reads the contents, so the mismatch stays harmless; only reset
  // it in load(), and do not compare against it.
  const expanded = ref<string[]>([]);

  // Vuetify renders the sort arrow as a bare VIcon with no colour prop and no
  // slot of its own, so the only way to tint it is to reach it from the class
  // header-props puts on every th (& below).
  const headerProps = {
    class: "bg-surface-darken-2 [&>div>.v-icon]:text-tertiary",
  };

  // Computed, not a plain array: t() would otherwise be evaluated once at setup
  // and the titles would keep the locale that was active then.
  const headers = computed(() => [
    { title: t("admin.documents.id"), key: "id", sortable: true },
    {
      title: t("admin.documents.documentTitle"),
      key: "title",
      sortable: true,
      cellProps: {
        dir: "auto",
        class: isRtl.value ? "text-right" : "text-left",
      },
    },
    {
      title: t("admin.documents.description"),
      key: "description",
      sortable: false,
      // Capped so a long description cannot stretch the column and squeeze
      // every other one; nowrap truncates to one line with an ellipsis, which
      // keeps row heights uniform. Both are Vuetify header props — the ellipsis
      // styling comes from .v-data-table-column--nowrap.
      maxWidth: 320,
      nowrap: true,
      // The ellipsis goes at the cell's *logical* end, so an RTL cell holding
      // LTR text clips the start and shows only the tail. dir="auto" takes the
      // side from the description's own direction; useRtl() then pins the
      // column to the UI edge, or rows would alternate alignment by script.
      cellProps: {
        dir: "auto",
        class: isRtl.value ? "text-right" : "text-left",
      },
    },
    // Not sortable, for the same reason as the users screen's team column: the
    // team will not be a column on `documents`, so there is nothing to order by.
    { title: t("admin.documents.team"), key: "team", sortable: false },
    // The *document's* creator — who made the folder. Each image carries its
    // own, shown in the expanded sub-table, and the two often differ.
    { title: t("admin.documents.creator"), key: "creator", sortable: true },
    { title: t("common.createdAt"), key: "created_at", sortable: true },
    { title: t("common.updatedAt"), key: "updated_at", sortable: true },
    { title: t("admin.documents.actions"), key: "actions", sortable: false },
  ]);

  const imageHeaders = computed(() => [
    {
      title: t("admin.documents.images.thumb"),
      key: "thumb",
      sortable: false,
      width: 96,
    },
    { title: t("admin.documents.images.id"), key: "id", sortable: false },
    { title: t("admin.documents.images.title"), key: "title", sortable: false },
    {
      title: t("admin.documents.images.creator"),
      key: "creator",
      sortable: false,
    },
    {
      title: t("admin.documents.images.createdAt"),
      key: "created_at",
      sortable: false,
    },
  ]);

  function params(): DocumentListParams {
    const [sort] = lastSort.value;

    return {
      page: page.value,
      per_page: itemsPerPage.value,
      sort_by: sort?.key,
      sort_order: sort?.order,
      search: search.value || undefined,
    };
  }

  async function load() {
    // Every page, sort and search change routes through here, so neither the
    // selection nor the expansion can hold rows that are no longer on screen.
    selected.value = [];
    expanded.value = [];
    loading.value = true;
    try {
      const result = await fetchPage(params());
      documents.value = result.items;
      total.value = result.total;
    } finally {
      loading.value = false;
    }
  }

  /**
   * The table fires this once on mount as well as on every page/sort change, so
   * there is no onMounted(load) — adding one would double-fetch.
   */
  function onOptions(options: TableOptions) {
    page.value = options.page;
    itemsPerPage.value = options.itemsPerPage;
    lastSort.value = options.sortBy;

    load();
  }

  // Debounced so a typed word is one request rather than one per keystroke. The
  // reset to page one matters: searching from page 4 would otherwise land on an
  // empty page of a much shorter result set.
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      page.value = 1;
      load();
    }, 300);
  });

  // TODO: wire once /api/documents gains store/update/destroy and a policy. The
  // controls are rendered so the layout is final; nothing mutates the fixture,
  // which is why there is no ConfirmDialog, no FormDialog and no notifier here.
  function openCreate() {}
  function openEdit() {}
  function openDelete() {}
  function bulkDestroy() {}
</script>

<template>
  <v-container fluid>
    <v-text-field
      v-model="search"
      bg-color="surface-darken-2"
      class="mb-4"
      clearable
      density="comfortable"
      hide-details
      :label="t('admin.documents.search')"
      variant="outlined"
    >
      <template #prepend-inner>
        <v-icon class="opacity-100" color="tertiary" icon="mdi-magnify" />
      </template>

      <template #clear="{ props: clearProps }">
        <v-icon v-bind="clearProps" class="opacity-100" color="tertiary" />
      </template>
    </v-text-field>

    <!-- Server variant: no :search prop, the term rides along in the request
         params instead. -->
    <v-data-table-server
      v-model="selected"
      v-model:expanded="expanded"
      :header-props="headerProps"
      :headers="headers"
      :items="documents"
      :items-length="total"
      :items-per-page="itemsPerPage"
      :loading="loading"
      :no-data-text="t('admin.documents.empty')"
      :page="page"
      show-expand
      show-select
      @update:options="onOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.documents.title") }}
          </h2>
        </div>

        <div class="p-3">
          <v-btn
            block
            color="tertiary"
            prepend-icon="mdi-plus"
            variant="elevated"
            @click="openCreate"
          >
            {{ t("admin.documents.add") }}
          </v-btn>
        </div>

        <div v-if="selected.length > 0" class="p-3">
          <v-btn
            block
            color="error"
            prepend-icon="mdi-delete"
            variant="elevated"
            @click="bulkDestroy"
          >
            {{
              t("admin.documents.deleteSelected", { count: selected.length })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.description="{ item }">
        {{ item.description || t("common.emptyValue") }}
      </template>

      <template #item.team="{ item }">
        {{ item.team?.name ?? t("common.emptyValue") }}
      </template>

      <template #item.created_at="{ item }">
        {{ formatDateTime(item.created_at) }}
      </template>

      <template #item.updated_at="{ item }">
        {{ formatDateTime(item.updated_at) }}
      </template>

      <template #item.actions="{ item }">
        <div class="flex gap-1">
          <v-btn
            color="tertiary"
            icon="mdi-open-in-new"
            size="small"
            :title="t('admin.documents.view')"
            :to="{ name: '/documents/[id]', params: { id: item.id } }"
            variant="text"
          />

          <v-btn
            color="tertiary"
            icon="mdi-pencil"
            size="small"
            :title="t('common.edit')"
            variant="text"
            @click="openEdit"
          />

          <v-btn
            color="error"
            icon="mdi-delete"
            size="small"
            :title="t('common.delete')"
            variant="text"
            @click="openDelete"
          />
        </div>
      </template>

      <!-- Vuetify hands this slot a raw table row rather than a container, so
           the tr/td colspan wrapper is required, not decorative. -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td class="p-0" :colspan="columns.length">
            <p
              v-if="item.images.length === 0"
              class="py-4 text-center opacity-60"
            >
              {{ t("admin.documents.noImages") }}
            </p>

            <!-- Client-side v-data-table, not the -server variant: a document's
                 images arrive with the row, so there is nothing to page. -->
            <!-- No density and no background of its own: it inherits the
                 parent table's surface so the two read as one table, and rows
                 stay the same height as the documents above them. -->
            <v-data-table
              v-else
              :header-props="headerProps"
              :headers="imageHeaders"
              hide-default-footer
              :items="item.images"
              :items-per-page="-1"
            >
              <template #top>
                <p class="px-4 pt-3 text-sm opacity-70">
                  {{ t("admin.documents.imagesTitle") }}
                </p>
              </template>

              <template #item.thumb="{ item: image }">
                <v-avatar class="my-2" rounded size="64">
                  <v-img :alt="image.title" cover :src="image.thumb_url" />
                </v-avatar>
              </template>

              <template #item.created_at="{ item: image }">
                {{ formatDateTime(image.created_at) }}
              </template>
            </v-data-table>
          </td>
        </tr>
      </template>
    </v-data-table-server>
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-documents"
}
</route>
