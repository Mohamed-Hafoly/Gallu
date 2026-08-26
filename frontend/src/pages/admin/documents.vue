<script setup lang="ts">
  import type { DocumentListParams } from "@/stores/document";
  import type { Document } from "@/types/document";
  import type { Image } from "@/types/image";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentStore } from "@/stores/document";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  /** What v-data-table-server hands back on @update:options. */
  interface TableOptions {
    page: number;
    itemsPerPage: number;
    sortBy: { key: string; order?: "asc" | "desc" }[];
  }

  /** Everything one of the two tables needs to fetch and render itself. */
  interface TableState {
    items: Document[];
    total: number;
    loading: boolean;
    page: number;
    itemsPerPage: number;
    sort: { key: string; order?: "asc" | "desc" }[];
    selected: number[];
  }

  function tableState(): TableState {
    return {
      items: [],
      total: 0,
      loading: false,
      page: 1,
      itemsPerPage: 10,
      sort: [],
      selected: [],
    };
  }

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatDateTime } = useDateFormat();
  const documentStore = useDocumentStore();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();

  /**
   * The live and pending-deletion tables are two independent listings, one
   * request each, differing only in the `trashed` parameter — the same split
   * admin/images.vue uses. They keep separate page and sort state, and both
   * reload after any mutation, because a delete or restore moves a row from one
   * to the other.
   */
  const live = ref<TableState>(tableState());
  const trash = ref<TableState>(tableState());
  const search = ref("");

  // Typed string[] to satisfy v-data-table's declared `readonly string[]`, even
  // though at runtime it writes the raw item value — a number — straight in.
  // The expand watcher below coerces each entry with Number() rather than
  // trusting the declared type. Only the live table expands.
  const expanded = ref<string[]>([]);

  /**
   * A document's images, fetched when its row is first expanded.
   *
   * The listing itself carries none — it omits the `cover` flag, so
   * DocumentResource returns no `images` key at all — which keeps the table a
   * plain one instead of serialising four images, their media and their
   * categories per row. Keyed by document id and kept after collapse, so
   * re-expanding the same row costs nothing; loadLive() clears it.
   */
  const images = ref<Record<number, Image[]>>({});
  const imagesLoading = ref<Set<number>>(new Set());

  // The document behind whichever dialog is open. Held rather than passed
  // inline so the dialogs keep rendering their content while closing.
  const editing = ref<Document | null>(null);
  const editOpen = ref(false);
  const deleting = ref<Document | null>(null);
  const deleteOpen = ref(false);
  const deletingInFlight = ref(false);
  const bulkDeleteOpen = ref(false);
  const bulkInFlight = ref(false);
  const restoringId = ref<number | null>(null);
  const createOpen = ref(false);

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
      // Capped for the same reason as the description below: title is otherwise
      // the only elastic column, so one long value stretches it, wraps the cell
      // and makes every row in that table taller than the other's. Narrower than
      // description's 320 because real titles are short.
      maxWidth: 240,
      nowrap: true,
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
    // Not sortable, and deliberately absent from DocumentController::SORTABLE:
    // ordering by the `team_id` column would sort by insertion order rather
    // than by the name the cell shows, which reads as a broken sort.
    {
      title: t("admin.documents.team"),
      key: "team",
      sortable: false,
      // Team names are free text and can be long; same cap as creator.
      maxWidth: 160,
      nowrap: true,
    },
    // The *document's* creator — who made the folder. Each image carries its
    // own, shown in the expanded sub-table, and the two often differ.
    // Capped and nowrapped like the title above — this is the column that
    // actually drives row height: a long name such as "Prof. Elmore Smitham III"
    // wraps to three or four lines in a ~100px column, and wraps to a different
    // number in each table, since the trashed one has an extra column competing
    // for width.
    {
      title: t("admin.documents.creator"),
      key: "creator",
      sortable: true,
      maxWidth: 160,
      nowrap: true,
    },
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
    // Capped like the outer table's title: these are image titles, so the same
    // long value can turn up here and stretch the sub-table.
    {
      title: t("admin.documents.images.title"),
      key: "title",
      sortable: false,
      maxWidth: 240,
      nowrap: true,
    },
    {
      title: t("admin.documents.images.creator"),
      key: "creator",
      sortable: false,
      maxWidth: 160,
      nowrap: true,
    },
    {
      title: t("admin.documents.images.createdAt"),
      key: "created_at",
      sortable: false,
    },
  ]);

  const trashedHeaders = computed(() => [
    ...headers.value.filter((header) => header.key !== "actions"),
    { title: t("admin.documents.deletedAt"), key: "deleted_at", sortable: true },
    { title: t("admin.documents.actions"), key: "actions", sortable: false },
  ]);

  function params(state: TableState, trashed?: "only"): DocumentListParams {
    const [sort] = state.sort;

    return {
      page: state.page,
      per_page: state.itemsPerPage,
      sort_by: sort?.key,
      sort_order: sort?.order,
      search: search.value || undefined,
      trashed,
    };
  }

  async function loadLive() {
    // Every page, sort and search change routes through here, so neither the
    // selection nor the expansion can hold rows that are no longer on screen.
    // The image cache goes with them: keyed by document id, it would otherwise
    // survive into a listing those ids are no longer part of.
    live.value.selected = [];
    expanded.value = [];
    images.value = {};
    live.value.loading = true;
    try {
      const result = await documentStore.fetchDocumentPage(params(live.value));
      live.value.items = result.items;
      live.value.total = result.total;
    } finally {
      live.value.loading = false;
    }
  }

  async function loadTrash() {
    trash.value.selected = [];
    trash.value.loading = true;
    try {
      const result = await documentStore.fetchDocumentPage(
        params(trash.value, "only"),
      );
      trash.value.items = result.items;
      trash.value.total = result.total;
    } finally {
      trash.value.loading = false;
    }
  }

  /**
   * Both tables, for anything that moves a row between them. Reloading only the
   * table that was acted on would leave the other showing a row it no longer
   * holds — which is why every mutation below calls this rather than one loader.
   */
  function loadBoth() {
    return Promise.all([loadLive(), loadTrash()]);
  }

  /**
   * Each table fires this once on mount as well as on every page/sort change,
   * so there is no onMounted(load) — adding one would double-fetch.
   */
  function onLiveOptions(options: TableOptions) {
    live.value.page = options.page;
    live.value.itemsPerPage = options.itemsPerPage;
    live.value.sort = options.sortBy;

    loadLive();
  }

  function onTrashOptions(options: TableOptions) {
    trash.value.page = options.page;
    trash.value.itemsPerPage = options.itemsPerPage;
    trash.value.sort = options.sortBy;

    loadTrash();
  }

  // Debounced so a typed word is two requests rather than two per keystroke.
  // Both tables reset to page one: searching from page 4 would otherwise land
  // on an empty page of a much shorter result set.
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      live.value.page = 1;
      trash.value.page = 1;
      loadBoth();
    }, 300);
  });

  /**
   * Fetch the images of every row that has just been expanded.
   *
   * Guarded on the cache and the in-flight set, so collapsing and re-expanding
   * a row does not re-request it, and a double-toggle cannot fire two calls for
   * the same id. The filter is applied server-side after the team scope, so an
   * id from another team comes back empty rather than leaking.
   */
  watch(expanded, async (ids) => {
    const pending = ids
      .map(Number)
      .filter((id) => !(id in images.value) && !imagesLoading.value.has(id));

    await Promise.all(
      pending.map(async (id) => {
        imagesLoading.value.add(id);
        try {
          images.value[id] = await imageStore.fetchImages(id);
        } catch {
          notifier.notify(t("admin.documents.imagesLoadFailed"), "error");
        } finally {
          imagesLoading.value.delete(id);
          // Set mutations are not reactive on their own; reassigning is what
          // re-renders the row's loader.
          imagesLoading.value = new Set(imagesLoading.value);
        }
      }),
    );
  });

  function openCreate() {
    createOpen.value = true;
  }

  function openEdit(document_: Document) {
    editing.value = document_;
    editOpen.value = true;
  }

  function openDelete(document_: Document) {
    deleting.value = document_;
    deleteOpen.value = true;
  }

  async function onCreated() {
    await loadBoth();
    notifier.notify(t("admin.documents.created"));
  }

  async function onUpdated() {
    await loadBoth();
    notifier.notify(t("admin.documents.updated"));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await documentStore.deleteDocument(deleting.value.id);
      await loadBoth();
      notifier.notify(t("admin.documents.deleted"));
      deleteOpen.value = false;
    } catch {
      notifier.notify(t("admin.documents.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(document_: Document) {
    restoringId.value = document_.id;
    try {
      await documentStore.restoreDocument(document_.id);
      await loadBoth();
      notifier.notify(t("admin.documents.restored"));
    } catch {
      notifier.notify(t("admin.documents.restoreFailed"), "error");
    } finally {
      restoringId.value = null;
    }
  }

  /**
   * There is no batch endpoint, so each id is its own request. allSettled rather
   * than all: one rejection should not abandon the rest, and the count of
   * failures is what gets reported.
   */
  async function runBulk(
    ids: number[],
    action: (id: number) => Promise<unknown>,
    successKey: string,
    failureKey: string,
  ) {
    bulkInFlight.value = true;
    try {
      const results = await Promise.allSettled(ids.map((id) => action(id)));
      const failed = results.filter((r) => r.status === "rejected").length;

      // Also clears both selections, since each loader resets its own.
      await loadBoth();

      if (failed > 0) {
        notifier.notify(t(failureKey, { count: failed }), "error");
      } else {
        notifier.notify(t(successKey, { count: ids.length }));
      }
    } finally {
      bulkInFlight.value = false;
    }
  }

  async function bulkDestroy() {
    await runBulk(
      [...live.value.selected],
      (id) => documentStore.deleteDocument(id),
      "admin.documents.bulkDeleted",
      "admin.documents.bulkDeleteFailed",
    );

    bulkDeleteOpen.value = false;
  }

  // No confirmation, unlike bulk delete: restoring is not destructive.
  async function bulkRestore() {
    await runBulk(
      [...trash.value.selected],
      (id) => documentStore.restoreDocument(id),
      "admin.documents.bulkRestored",
      "admin.documents.bulkRestoreFailed",
    );
  }
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
      v-model="live.selected"
      v-model:expanded="expanded"
      :header-props="headerProps"
      :headers="headers"
      :items="live.items"
      :items-length="live.total"
      :items-per-page="live.itemsPerPage"
      :loading="live.loading"
      :no-data-text="t('admin.documents.empty')"
      :page="live.page"
      show-expand
      show-select
      @update:options="onLiveOptions"
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

        <div v-if="live.selected.length > 0" class="p-3">
          <v-btn
            block
            color="error"
            :loading="bulkInFlight"
            prepend-icon="mdi-delete"
            variant="elevated"
            @click="bulkDeleteOpen = true"
          >
            {{
              t("admin.documents.deleteSelected", {
                count: live.selected.length,
              })
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
            @click="openEdit(item)"
          />

          <v-btn
            color="error"
            icon="mdi-delete"
            size="small"
            :title="t('common.delete')"
            variant="text"
            @click="openDelete(item)"
          />
        </div>
      </template>

      <!-- Vuetify hands this slot a raw table row rather than a container, so
           the tr/td colspan wrapper is required, not decorative. -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td class="p-0" :colspan="columns.length">
            <!-- The listing carries no images, so the row fetches its own on
                 first expand. Three states, in order: still loading, loaded
                 and empty, loaded with rows. -->
            <v-progress-linear
              v-if="imagesLoading.has(item.id)"
              class="my-4"
              indeterminate
            />

            <p
              v-else-if="(images[item.id]?.length ?? 0) === 0"
              class="py-4 text-center opacity-60"
            >
              {{ t("admin.documents.noImages") }}
            </p>

            <!-- Client-side v-data-table, not the -server variant: the fetch
                 above returns a document's whole set, so there is nothing left
                 to page. -->
            <!-- No density and no background of its own: it inherits the
                 parent table's surface so the two read as one table, and rows
                 stay the same height as the documents above them. -->
            <v-data-table
              v-else
              :header-props="headerProps"
              :headers="imageHeaders"
              hide-default-footer
              :items="images[item.id]"
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

    <!-- No show-expand: a trashed document's images are trashed with it, so
         there is nothing live to list underneath. -->
    <v-data-table-server
      v-model="trash.selected"
      class="mt-8"
      :header-props="headerProps"
      :headers="trashedHeaders"
      :items="trash.items"
      :items-length="trash.total"
      :items-per-page="trash.itemsPerPage"
      :loading="trash.loading"
      :no-data-text="t('admin.documents.trashedEmpty')"
      :page="trash.page"
      show-select
      @update:options="onTrashOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.documents.trashedTitle") }}
          </h2>

          <p class="mt-2">
            <span class="text-tertiary opacity-100">* </span>

            <span class="opacity-80">{{
              t("admin.documents.trashedTitleNote")
            }}</span>
          </p>
        </div>

        <!-- No confirmation, unlike the bulk delete above: restoring is not
             destructive. -->
        <div v-if="trash.selected.length > 0" class="p-3">
          <v-btn
            block
            color="tertiary"
            :loading="bulkInFlight"
            prepend-icon="mdi-restore"
            variant="elevated"
            @click="bulkRestore"
          >
            {{
              t("admin.documents.restoreSelected", {
                count: trash.selected.length,
              })
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

      <template #item.deleted_at="{ item }">
        {{ formatDateTime(item.deleted_at) }}
      </template>

      <!-- Restore only: the edit endpoint refuses a trashed row, and there is
           no permanent delete. -->
      <template #item.actions="{ item }">
        <v-btn
          color="tertiary"
          icon="mdi-restore"
          :loading="restoringId === item.id"
          size="small"
          :title="t('admin.documents.restore')"
          variant="text"
          @click="restore(item)"
        />
      </template>
    </v-data-table-server>

    <ConfirmDialog
      v-model="deleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="deletingInFlight"
      :message="
        t('admin.documents.deleteConfirm', { title: deleting?.title ?? '' })
      "
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.documents.bulkDeleteConfirm', { count: live.selected.length })
      "
      @confirm="bulkDestroy"
    />

    <!-- Success is notified here, failure inside the dialog — the same split
         the teams screen uses. -->
    <DocumentCreateDialog v-model="createOpen" @created="onCreated" />

    <!-- v-if, so the required non-null prop typechecks; the separate editOpen
         boolean is what keeps content rendered during the close transition. -->
    <DocumentEditDialog
      v-if="editing"
      v-model="editOpen"
      :document="editing"
      @updated="onUpdated"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-documents"
}
</route>
