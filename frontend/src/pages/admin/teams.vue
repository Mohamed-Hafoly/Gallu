<script setup lang="ts">
  import type { Team } from "@/types/team";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useNotifierStore } from "@/stores/notifier";
  import { useTeamStore } from "@/stores/team";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatDateTime } = useDateFormat();
  const teamStore = useTeamStore();
  const notifier = useNotifierStore();

  const teams = ref<Team[]>([]);
  const loading = ref(false);
  const search = ref("");
  const restoringId = ref<number | null>(null);

  // The team behind whichever dialog is open. Held rather than passed inline so
  // the dialogs keep rendering their content while closing.
  const editing = ref<Team | null>(null);
  const createOpen = ref(false);
  const editOpen = ref(false);
  const deleting = ref<Team | null>(null);
  const deleteOpen = ref(false);
  const deletingInFlight = ref(false);
  const managing = ref<Team | null>(null);
  const membersOpen = ref(false);

  // Selection is held per table; the model carries ids because item-value
  // defaults to "id" and return-object is off.
  const selectedLive = ref<number[]>([]);
  const selectedTrashed = ref<number[]>([]);
  const bulkDeleteOpen = ref(false);
  const bulkInFlight = ref(false);

  const headerProps = {
    class: "bg-surface-darken-2 [&>div>.v-icon]:text-tertiary",
  };

  // One fetch feeds both tables; they are just two views of the same array,
  // which is why restoring only needs a single refetch.
  const liveTeams = computed(() =>
    teams.value.filter((team) => !team.deleted_at),
  );
  const trashedTeams = computed(() =>
    teams.value.filter((team) => team.deleted_at),
  );

  // Computed, not plain arrays: t() would otherwise be evaluated once at setup
  // and the titles would keep the locale that was active then.
  const columns = computed(() => [
    { title: t("admin.teams.id"), key: "id", sortable: true },
    { title: t("admin.teams.name"), key: "name", sortable: true },
    {
      title: t("admin.teams.description"),
      key: "description",
      sortable: false,
      // Capped so a long description cannot stretch the column and squeeze
      // every other one; nowrap truncates to one line with an ellipsis, which
      // keeps row heights uniform. Both are Vuetify header props — the
      // ellipsis styling comes from .v-data-table-column--nowrap.
      maxWidth: 320,
      nowrap: true,
      // The ellipsis goes at the cell's *logical* end, so an RTL cell holding
      // LTR text clips the start and shows only the tail. dir="auto" takes the
      // side from the description's own direction; useRtl() then pins the
      // column to the UI edge, or rows would alternate alignment by script.
      // Same pairing as the gallery cards.
      cellProps: {
        dir: "auto",
        class: isRtl.value ? "text-right" : "text-left",
      },
    },
    { title: t("admin.teams.members"), key: "members_count", sortable: true },
    { title: t("admin.teams.creator"), key: "creator", sortable: true },
    { title: t("common.createdAt"), key: "created_at", sortable: true },
    { title: t("common.updatedAt"), key: "updated_at", sortable: true },
  ]);

  const headers = computed(() => [
    ...columns.value,
    { title: t("admin.teams.actions"), key: "actions", sortable: false },
  ]);

  const trashedHeaders = computed(() => [
    ...columns.value,
    { title: t("admin.teams.deletedAt"), key: "deleted_at", sortable: true },
    { title: t("admin.teams.actions"), key: "actions", sortable: false },
  ]);

  async function load() {
    loading.value = true;
    try {
      teams.value = await teamStore.fetchAllTeams();
    } finally {
      loading.value = false;
    }
  }

  function openCreate() {
    createOpen.value = true;
  }

  function openEdit(team: Team) {
    editing.value = team;
    editOpen.value = true;
  }

  function openMembers(team: Team) {
    managing.value = team;
    membersOpen.value = true;
  }

  function openDelete(team: Team) {
    deleting.value = team;
    deleteOpen.value = true;
  }

  async function onSaved(messageKey: "created" | "updated") {
    await load();
    notifier.notify(t(`admin.teams.${messageKey}`));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await teamStore.deleteTeam(deleting.value.id);
      await load();
      notifier.notify(t("admin.teams.deleted"));
      deleteOpen.value = false;
    } catch {
      notifier.notify(t("admin.teams.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(team: Team) {
    restoringId.value = team.id;
    try {
      await teamStore.restoreTeam(team.id);
      await load();
      notifier.notify(t("admin.teams.restored"));
    } catch {
      notifier.notify(t("admin.teams.restoreFailed"), "error");
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

      await load();

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
    const ids = [...selectedLive.value];

    await runBulk(
      ids,
      (id) => teamStore.deleteTeam(id),
      "admin.teams.bulkDeleted",
      "admin.teams.bulkDeleteFailed",
    );

    // Cleared explicitly: the rows move to the other table, and ids left in the
    // model would keep a selection alive for rows that are no longer there.
    selectedLive.value = [];
    bulkDeleteOpen.value = false;
  }

  async function bulkRestore() {
    const ids = [...selectedTrashed.value];

    await runBulk(
      ids,
      (id) => teamStore.restoreTeam(id),
      "admin.teams.bulkRestored",
      "admin.teams.bulkRestoreFailed",
    );

    selectedTrashed.value = [];
  }

  onMounted(load);
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
      :label="t('admin.teams.search')"
      variant="outlined"
    >
      <template #prepend-inner>
        <v-icon class="opacity-100" color="tertiary" icon="mdi-magnify" />
      </template>

      <template #clear="{ props: clearProps }">
        <v-icon v-bind="clearProps" class="opacity-100" color="tertiary" />
      </template>
    </v-text-field>

    <v-data-table
      v-model="selectedLive"
      :header-props="headerProps"
      :headers="headers"
      :items="liveTeams"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.teams.empty')"
      :search="search"
      show-select
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">{{ t("admin.teams.title") }}</h2>
        </div>

        <div class="p-3">
          <v-btn
            block
            color="tertiary"
            prepend-icon="mdi-plus"
            variant="elevated"
            @click="openCreate"
          >
            {{ t("admin.teams.add") }}
          </v-btn>

          <v-btn
            v-if="selectedLive.length > 0"
            block
            class="mt-2"
            color="error"
            :loading="bulkInFlight"
            prepend-icon="mdi-delete"
            variant="elevated"
            @click="bulkDeleteOpen = true"
          >
            {{
              t("admin.teams.deleteSelected", { count: selectedLive.length })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.description="{ item }">
        {{ item.description || t("common.emptyValue") }}
      </template>

      <template #item.creator="{ item }">
        {{ item.creator || t("common.emptyValue") }}
      </template>

      <template #item.created_at="{ item }">
        {{ formatDateTime(item.created_at) }}
      </template>

      <template #item.updated_at="{ item }">
        {{ formatDateTime(item.updated_at) }}
      </template>

      <template #item.actions="{ item }">
        <div class="flex gap-1">
          <!-- Live rows only: the backend refuses to manage the membership of a
               trashed team, which already reads as team-less everywhere. -->
          <v-btn
            color="tertiary"
            icon="mdi-account-multiple"
            size="small"
            :title="t('admin.teams.manageMembers')"
            variant="text"
            @click="openMembers(item)"
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
    </v-data-table>

    <v-data-table
      v-model="selectedTrashed"
      class="mt-8"
      :header-props="headerProps"
      :headers="trashedHeaders"
      :items="trashedTeams"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.teams.trashedEmpty')"
      :search="search"
      show-select
      :sort-by="[{ key: 'deleted_at', order: 'desc' }]"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.teams.trashedTitle") }}
          </h2>

          <p class="mt-2">
            <span class="text-tertiary opacity-100">* </span>

            <span class="opacity-80">{{
              t("admin.teams.trashedTitleNote")
            }}</span>
          </p>
        </div>

        <!-- Restoring is not destructive, so it fires straight away where the
             bulk delete above asks for confirmation first. -->
        <div v-if="selectedTrashed.length > 0" class="p-3">
          <v-btn
            block
            color="tertiary"
            :loading="bulkInFlight"
            prepend-icon="mdi-restore"
            variant="elevated"
            @click="bulkRestore"
          >
            {{
              t("admin.teams.restoreSelected", {
                count: selectedTrashed.length,
              })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.description="{ item }">
        {{ item.description || t("common.emptyValue") }}
      </template>

      <template #item.creator="{ item }">
        {{ item.creator || t("common.emptyValue") }}
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

      <template #item.actions="{ item }">
        <v-btn
          color="tertiary"
          icon="mdi-restore"
          :loading="restoringId === item.id"
          size="small"
          :title="t('admin.teams.restore')"
          variant="text"
          @click="restore(item)"
        />
      </template>
    </v-data-table>

    <TeamCreateDialog v-model="createOpen" @created="onSaved('created')" />

    <TeamEditDialog
      v-if="editing"
      v-model="editOpen"
      :team="editing"
      @updated="onSaved('updated')"
    />

    <TeamMembersDialog
      v-if="managing"
      v-model="membersOpen"
      :team="managing"
      @changed="load"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="deletingInFlight"
      :message="t('admin.teams.deleteConfirm', { name: deleting?.name ?? '' })"
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.teams.bulkDeleteConfirm', { count: selectedLive.length })
      "
      @confirm="bulkDestroy"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-teams"
}
</route>
