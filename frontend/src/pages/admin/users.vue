<script setup lang="ts">
  import type { UserListParams } from "@/stores/user";
  import type { User } from "@/types/user";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useAuthStore } from "@/stores/auth";
  import { useNotifierStore } from "@/stores/notifier";
  import { useUserStore } from "@/stores/user";

  /** What v-data-table-server hands back on @update:options. */
  interface TableOptions {
    page: number;
    itemsPerPage: number;
    sortBy: { key: string; order?: "asc" | "desc" }[];
  }

  /**
   * One table's worth of state. Two of these rather than a pile of parallel
   * refs, because the live and pending-deletion tables page and sort
   * independently — the same shape the documents screen uses.
   */
  interface TableState {
    items: User[];
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
  const userStore = useUserStore();
  const authStore = useAuthStore();
  const notifier = useNotifierStore();

  const live = ref<TableState>(tableState());
  const trash = ref<TableState>(tableState());

  // Shared by both tables: one term, two listings.
  const search = ref("");

  // The user behind whichever dialog is open. Held rather than passed inline so
  // the dialogs keep rendering their content while closing.
  const editing = ref<User | null>(null);
  const editOpen = ref(false);
  const createOpen = ref(false);
  const deleting = ref<User | null>(null);
  const deleteOpen = ref(false);
  const deletingInFlight = ref(false);

  const bulkDeleteOpen = ref(false);
  const bulkInFlight = ref(false);
  const restoringId = ref<number | null>(null);

  // Vuetify renders the sort arrow as a bare VIcon with no colour prop and no
  // slot of its own, so the only way to tint it is to reach it from the class
  // header-props puts on every th (& below).

  const headerProps = {
    class: "bg-surface-darken-2 [&>div>.v-icon]:text-tertiary",
  };

  // Computed, not a plain array: t() would otherwise be evaluated once at setup
  // and the titles would keep the locale that was active then.
  const headers = computed(() => [
    { title: t("admin.users.id"), key: "id", sortable: true },
    { title: t("admin.users.avatar"), key: "avatar", sortable: false },
    // Capped and nowrapped like the other admin tables' free-text columns: a
    // long value would otherwise stretch its column, wrap the cell and make
    // that row taller than the rest. 160 is the same cap the creator and team
    // columns take elsewhere, since these hold the same kind of value.
    //
    // The ellipsis side is not this file's problem — styles/main.scss handles
    // it for every nowrap cell in the app. Only the hover title is local.
    {
      title: t("admin.users.name"),
      key: "name",
      sortable: true,
      maxWidth: 160,
      nowrap: true,
      cellProps: ({ item }: { item: User }) => ({ title: item.name }),
    },
    // Wider than the rest: an address is long by nature, and truncateEmail()
    // exists precisely because clipping one eats the identifying half. Here the
    // cap is a last resort and the title makes the whole address recoverable.
    {
      title: t("admin.users.email"),
      key: "email",
      sortable: true,
      maxWidth: 240,
      nowrap: true,
      cellProps: ({ item }: { item: User }) => ({ title: item.email }),
    },
    // The API's name for the is_super_admin column, which is what the backend
    // actually sorts on.
    { title: t("admin.users.role"), key: "role", sortable: true },
    // The team lives in the role pivot rather than on `users`, but the listing
    // already selects its name through withTeamAssignment() - so the backend
    // sorts on that alias, and this orders by the name the cell shows.
    {
      title: t("admin.users.team"),
      key: "team",
      sortable: true,
      // Team names are free text and can be long; same cap as the name above.
      maxWidth: 160,
      nowrap: true,
      cellProps: ({ item }: { item: User }) => ({ title: item.team?.name ?? "" }),
    },
    { title: t("common.createdAt"), key: "created_at", sortable: true },
    { title: t("common.updatedAt"), key: "updated_at", sortable: true },
    { title: t("admin.users.actions"), key: "actions", sortable: false },
  ]);

  // The live columns, minus actions, plus when it was binned, then actions back
  // at the end — the same arrangement the documents screen uses.
  const trashedHeaders = computed(() => [
    ...headers.value.filter((header) => header.key !== "actions"),
    { title: t("admin.users.deletedAt"), key: "deleted_at", sortable: true },
    { title: t("admin.users.actions"), key: "actions", sortable: false },
  ]);

  function params(state: TableState, trashed?: "only"): UserListParams {
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
    // can never hold rows that are no longer on screen. Categories does not
    // need this — it loads every row at once.
    live.value.selected = [];
    live.value.loading = true;
    try {
      const result = await userStore.fetchUsers(params(live.value));
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
      const result = await userStore.fetchUsers(params(trash.value, "only"));
      trash.value.items = result.items;
      trash.value.total = result.total;
    } finally {
      trash.value.loading = false;
    }
  }

  /** A delete or a restore moves a row between the tables, so both refetch. */
  function loadBoth() {
    return Promise.all([loadLive(), loadTrash()]);
  }

  /**
   * The tables fire these once on mount as well as on every page/sort change, so
   * there is no onMounted(load) — adding one would double-fetch.
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

  // Debounced so a typed word is one request rather than one per keystroke. The
  // reset to page one matters: searching from page 4 would otherwise land on an
  // empty page of a much shorter result set — and it applies to both tables,
  // since they share the term.
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      live.value.page = 1;
      trash.value.page = 1;
      loadBoth();
    }, 300);
  });

  // The backend refuses to delete the account you are signed in as, so the
  // button that would 403 is not offered.
  function isSelf(user: User) {
    return user.id === authStore.user?.id;
  }

  function rowProps({ item }: { item: User }) {
    return item.is_super_admin
      ? { class: "bg-primary-darken-1 text-on-primary" }
      : {};
  }

  function openCreate() {
    createOpen.value = true;
  }

  function openEdit(user: User) {
    editing.value = user;
    editOpen.value = true;
  }

  function openDelete(user: User) {
    deleting.value = user;
    deleteOpen.value = true;
  }

  async function onCreated() {
    await loadLive();
    notifier.notify(t("admin.users.created"));
  }

  async function onUpdated() {
    await loadLive();
    notifier.notify(t("admin.users.updated"));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await userStore.deleteUser(deleting.value.id);
      await loadBoth();
      notifier.notify(t("admin.users.deleted"));
      deleteOpen.value = false;
    } catch {
      notifier.notify(t("admin.users.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(user: User) {
    restoringId.value = user.id;
    try {
      await userStore.restoreUser(user.id);
      await loadBoth();
      notifier.notify(t("admin.users.restored"));
    } catch {
      notifier.notify(t("admin.users.restoreFailed"), "error");
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

      // Also clears both selections, since the loads reset them.
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
      (id) => userStore.deleteUser(id),
      "admin.users.bulkDeleted",
      "admin.users.bulkDeleteFailed",
    );

    bulkDeleteOpen.value = false;
  }

  // No confirm dialog, unlike bulk delete: restoring is not destructive.
  async function bulkRestore() {
    await runBulk(
      [...trash.value.selected],
      (id) => userStore.restoreUser(id),
      "admin.users.bulkRestored",
      "admin.users.bulkRestoreFailed",
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
      :label="t('admin.users.search')"
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
      :header-props="headerProps"
      :headers="headers"
      :item-selectable="(user: User) => !user.is_super_admin"
      :items="live.items"
      :items-length="live.total"
      :items-per-page="live.itemsPerPage"
      :loading="live.loading"
      :no-data-text="t('admin.users.empty')"
      :page="live.page"
      :row-props="rowProps"
      show-select
      @update:options="onLiveOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">{{ t("admin.users.title") }}</h2>
        </div>

        <div class="p-3">
          <v-btn
            block
            color="tertiary"
            prepend-icon="mdi-plus"
            variant="elevated"
            @click="openCreate"
          >
            {{ t("admin.users.add") }}
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
            {{ t("admin.users.deleteSelected", { count: live.selected.length }) }}
          </v-btn>
        </div>
      </template>

      <template #item.avatar="{ item }">
        <v-avatar class="my-1" rounded size="40">
          <v-img
            :alt="t('admin.users.avatar')"
            cover
            :src="item.avatar_thumb_url"
          />
        </v-avatar>
      </template>

      <template #item.role="{ item }">
        {{ t(`admin.users.roles.${item.role}`) }}
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
            icon="mdi-pencil"
            size="small"
            :title="t('common.edit')"
            variant="text"
            @click="openEdit(item)"
          />

          <v-btn
            v-if="!isSelf(item)"
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

    <!-- Restore-only: the update endpoint refuses a trashed row, and there is no
         permanent delete. -->
    <v-data-table-server
      v-model="trash.selected"
      class="mt-8"
      :header-props="headerProps"
      :headers="trashedHeaders"
      :items="trash.items"
      :items-length="trash.total"
      :items-per-page="trash.itemsPerPage"
      :loading="trash.loading"
      :no-data-text="t('admin.users.trashedEmpty')"
      :page="trash.page"
      :row-props="rowProps"
      show-select
      @update:options="onTrashOptions"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.users.trashedTitle") }}
          </h2>

          <p class="mt-2">
            <span class="text-tertiary opacity-100">* </span>

            <span class="opacity-80">{{
              t("admin.users.trashedTitleNote")
            }}</span>
          </p>
        </div>

        <div v-if="trash.selected.length > 0" class="p-3">
          <v-btn
            block
            color="tertiary"
            :loading="bulkInFlight ? 'on-tertiary' : false"
            prepend-icon="mdi-restore"
            variant="elevated"
            @click="bulkRestore"
          >
            {{ t("admin.users.restoreSelected", { count: trash.selected.length }) }}
          </v-btn>
        </div>
      </template>

      <template #item.avatar="{ item }">
        <v-avatar class="my-1" rounded size="40">
          <v-img
            :alt="t('admin.users.avatar')"
            cover
            :src="item.avatar_thumb_url"
          />
        </v-avatar>
      </template>

      <template #item.role="{ item }">
        {{ t(`admin.users.roles.${item.role}`) }}
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
        {{ item.deleted_at ? formatDateTime(item.deleted_at) : "" }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          color="tertiary"
          icon="mdi-restore"
          :loading="restoringId === item.id"
          size="small"
          :title="t('admin.users.restore')"
          variant="text"
          @click="restore(item)"
        />
      </template>
    </v-data-table-server>

    <UserCreateDialog v-model="createOpen" @created="onCreated" />

    <UserEditDialog
      v-if="editing"
      v-model="editOpen"
      :user="editing"
      @updated="onUpdated"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="deletingInFlight"
      :message="t('admin.users.deleteConfirm', { name: deleting?.name ?? '' })"
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.users.bulkDeleteConfirm', { count: live.selected.length })
      "
      @confirm="bulkDestroy"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-users",
  "alias": "/admin"
}
</route>
