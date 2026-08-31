<script setup lang="ts">
  import type { ImageListParams } from "@/stores/image";
  import type { Image } from "@/types/image";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  /**
   * Follows admin/users.vue, not admin/teams.vue: an image library outgrows a
   * single unpaginated fetch, so page, sort and search all arrive as query
   * parameters and the response carries the meta.total the footers need.
   *
   * The live and pending-deletion tables are therefore two independent
   * listings, one request each, differing only in the `trashed` parameter.
   * They keep separate page and sort state — and both reload after any
   * mutation, because a delete or restore moves a row from one to the other.
   */

  /** What v-data-table-server hands back on @update:options. */
  interface TableOptions {
    page: number;
    itemsPerPage: number;
    sortBy: { key: string; order?: "asc" | "desc" }[];
  }

  /** Everything one of the two tables needs to fetch and render itself. */
  interface TableState {
    items: Image[];
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
  const { formatDateTime } = useDateFormat();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();

  const live = ref<TableState>(tableState());
  const trash = ref<TableState>(tableState());
  const search = ref("");
  const restoringId = ref<number | null>(null);

  // The image behind whichever dialog is open. Held rather than passed inline
  // so the dialogs keep rendering their content while closing.
  const detailing = ref<Image | null>(null);
  const detailOpen = ref(false);
  const deleting = ref<Image | null>(null);
  const deleteOpen = ref(false);
  const deletingInFlight = ref(false);
  const bulkDeleteOpen = ref(false);
  const bulkInFlight = ref(false);

  const headerProps = {
    class: "bg-surface-darken-2 [&>div>.v-icon]:text-tertiary",
  };

  // Computed, not plain arrays: t() would otherwise be evaluated once at setup
  // and the titles would keep the locale that was active then.
  const columns = computed(() => [
    { title: t("admin.images.id"), key: "id", sortable: true },
    // Not sortable — there is nothing to order a picture by.
    {
      title: t("admin.images.thumb"),
      key: "thumb",
      sortable: false,
      width: 72,
    },
    {
      title: t("admin.images.imageTitle"),
      key: "title",
      sortable: true,
      // Capped for the same reason as the description below, and it matters
      // more here: title is otherwise the only elastic column, so one long
      // value stretches it, wraps the cell and makes every row in *that* table
      // taller than the other's. Narrower than description's 320 because real
      // titles run to about 28 characters.
      maxWidth: 240,
      nowrap: true,
      // Only the hover title is local now: which side the ellipsis falls on is
      // handled once for every truncating element in styles/main.scss.
      cellProps: ({ item }: { item: Image }) => ({ title: item.title }),
    },
    {
      title: t("admin.images.description"),
      key: "description",
      sortable: false,
      // Capped so a long description cannot stretch the column and squeeze
      // every other one; nowrap truncates to one line with an ellipsis, which
      // keeps row heights uniform.
      maxWidth: 320,
      nowrap: true,
      // Empty string rather than the placeholder, so a description-less row
      // gets no tooltip at all instead of one reading "-".
      cellProps: ({ item }: { item: Image }) => ({ title: item.description ?? "" }),
    },
    // Sortable, even though `creator` is not a column on `images` — the backend
    // maps it to a correlated subselect against users.name.
    // Capped and nowrapped like the title above — this is the column that
    // actually drives row height: a long name such as "Prof. Elmore Smitham III"
    // wraps to three or four lines in a ~100px column, and wraps to a different
    // number in each table, since the trashed one has an extra column competing
    // for width.
    {
      title: t("admin.images.creator"),
      key: "creator",
      sortable: true,
      maxWidth: 160,
      nowrap: true,
      cellProps: ({ item }: { item: Image }) => ({ title: item.creator }),
    },
    { title: t("admin.images.document"), key: "document_id", sortable: true },
    { title: t("common.createdAt"), key: "created_at", sortable: true },
    { title: t("common.updatedAt"), key: "updated_at", sortable: true },
  ]);

  const headers = computed(() => [
    ...columns.value,
    { title: t("admin.images.actions"), key: "actions", sortable: false },
  ]);

  const trashedHeaders = computed(() => [
    ...columns.value,
    { title: t("admin.images.deletedAt"), key: "deleted_at", sortable: true },
    { title: t("admin.images.actions"), key: "actions", sortable: false },
  ]);

  function params(state: TableState, trashed?: "only"): ImageListParams {
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
    // Every page, sort and search change routes through here, so the selection
    // can never hold rows that are no longer on screen.
    live.value.selected = [];
    live.value.loading = true;
    try {
      const result = await imageStore.fetchImagePage(params(live.value));
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
      const result = await imageStore.fetchImagePage(
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
   * Editing reuses ImageDetailDialog, the gallery's own detail view — it
   * already edits title, description, categories and the file itself against
   * the same endpoint, and reports its own failures. No admin-only dialog is
   * needed, and a second one would be a second place for the rules to drift.
   */
  function openDetail(image: Image) {
    detailing.value = image;
    detailOpen.value = true;
  }

  function openDelete(image: Image) {
    deleting.value = image;
    deleteOpen.value = true;
  }

  async function onUpdated() {
    await loadBoth();
    notifier.notify(t("admin.images.updated"));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await imageStore.deleteImage(deleting.value.id);
      await loadBoth();
      notifier.notify(t("admin.images.deleted"));
      deleteOpen.value = false;
    } catch {
      notifier.notify(t("admin.images.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(image: Image) {
    restoringId.value = image.id;
    try {
      await imageStore.restoreImage(image.id);
      await loadBoth();
      notifier.notify(t("admin.images.restored"));
    } catch {
      notifier.notify(t("admin.images.restoreFailed"), "error");
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
      (id) => imageStore.deleteImage(id),
      "admin.images.bulkDeleted",
      "admin.images.bulkDeleteFailed",
    );

    bulkDeleteOpen.value = false;
  }

  async function bulkRestore() {
    await runBulk(
      [...trash.value.selected],
      (id) => imageStore.restoreImage(id),
      "admin.images.bulkRestored",
      "admin.images.bulkRestoreFailed",
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
      :label="t('admin.images.search')"
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
         params instead. One term, two requests — a half-remembered title should
         find the image whether or not it has been deleted. -->
    <v-data-table-server
      v-model="live.selected"
      :header-props="headerProps"
      :headers="headers"
      :items="live.items"
      :items-length="live.total"
      :items-per-page="live.itemsPerPage"
      :loading="live.loading"
      :no-data-text="t('admin.images.empty')"
      :page="live.page"
      show-select
      @update:options="onLiveOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">{{ t("admin.images.title") }}</h2>
        </div>

        <!-- No "add" button, unlike the teams and categories screens: an image
             cannot exist without a file, and uploading belongs to the document
             it lands in. Creation stays on /documents/{id}. -->
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
              t("admin.images.deleteSelected", { count: live.selected.length })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.thumb="{ item }">
        <v-avatar class="my-1" rounded size="40">
          <v-img :alt="item.title" cover :src="item.thumb_url" />
        </v-avatar>
      </template>

      <template #item.description="{ item }">
        {{ item.description || t("common.emptyValue") }}
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
            icon="mdi-pencil"
            size="small"
            :title="t('common.edit')"
            variant="text"
            @click="openDetail(item)"
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
    </v-data-table-server>

    <v-data-table-server
      v-model="trash.selected"
      class="mt-8"
      :header-props="headerProps"
      :headers="trashedHeaders"
      :items="trash.items"
      :items-length="trash.total"
      :items-per-page="trash.itemsPerPage"
      :loading="trash.loading"
      :no-data-text="t('admin.images.trashedEmpty')"
      :page="trash.page"
      show-select
      :sort-by="[{ key: 'deleted_at', order: 'desc' }]"
      @update:options="onTrashOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.images.trashedTitle") }}
          </h2>

          <p class="mt-2">
            <span class="text-tertiary opacity-100">* </span>

            <span class="opacity-80">{{
              t("admin.images.trashedTitleNote")
            }}</span>
          </p>
        </div>

        <!-- Restoring is not destructive, so it fires straight away where the
             bulk delete above asks for confirmation first. -->
        <div v-if="trash.selected.length > 0" class="p-3">
          <!--
            The loader colour rides on `loading` rather than a #loader slot:
            VBtn forwards that prop as the spinner's colour only when it is a
            string, so a bare boolean would let the spinner inherit the global
            tertiary default and vanish against this button's tertiary fill.
          -->
          <v-btn
            block
            color="tertiary"
            :loading="bulkInFlight ? 'on-tertiary' : false"
            prepend-icon="mdi-restore"
            variant="elevated"
            @click="bulkRestore"
          >
            {{
              t("admin.images.restoreSelected", {
                count: trash.selected.length,
              })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.thumb="{ item }">
        <v-avatar class="my-1" rounded size="40">
          <v-img :alt="item.title" cover :src="item.thumb_url" />
        </v-avatar>
      </template>

      <template #item.description="{ item }">
        {{ item.description || t("common.emptyValue") }}
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

      <!-- No edit here: ImageDetailDialog writes through /api/images/{id},
           which the backend refuses for a trashed row. Restore first. -->
      <template #item.actions="{ item }">
        <v-btn
          color="tertiary"
          icon="mdi-restore"
          :loading="restoringId === item.id"
          size="small"
          :title="t('admin.images.restore')"
          variant="text"
          @click="restore(item)"
        />
      </template>
    </v-data-table-server>

    <ImageDetailDialog
      v-model="detailOpen"
      :image="detailing"
      @deleted="loadBoth"
      @updated="onUpdated"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="deletingInFlight"
      :message="
        t('admin.images.deleteConfirm', { title: deleting?.title ?? '' })
      "
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.images.bulkDeleteConfirm', { count: live.selected.length })
      "
      @confirm="bulkDestroy"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-images"
}
</route>
