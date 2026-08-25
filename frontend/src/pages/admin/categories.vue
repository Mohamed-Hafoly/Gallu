<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useCategoryStore } from "@/stores/category";
  import { useNotifierStore } from "@/stores/notifier";

  const { t } = useI18n();
  const { formatDateTime } = useDateFormat();
  const categoryStore = useCategoryStore();

  const categories = ref<Category[]>([]);
  const loading = ref(false);
  const search = ref("");
  const restoringId = ref<number | null>(null);

  // The category behind whichever dialog is open. Held rather than passed
  // inline so the dialogs keep rendering their content while closing.
  const editing = ref<Category | null>(null);
  const createOpen = ref(false);
  const editOpen = ref(false);
  const deleting = ref<Category | null>(null);
  const deleteOpen = ref(false);
  const deletingInFlight = ref(false);

  // Selection is held per table; the model carries ids because item-value
  // defaults to "id" and return-object is off.
  const selectedLive = ref<number[]>([]);
  const selectedTrashed = ref<number[]>([]);
  const bulkDeleteOpen = ref(false);
  const bulkInFlight = ref(false);

  const notifier = useNotifierStore();

  // One fetch feeds both tables; they are just two views of the same array,
  // which is why restoring only needs a single refetch.
  const liveCategories = computed(() =>
    categories.value.filter((category) => !category.deleted_at),
  );
  const trashedCategories = computed(() =>
    categories.value.filter((category) => category.deleted_at),
  );

  // Vuetify renders the sort arrow as a bare VIcon with no colour prop and no
  // slot of its own, so the only way to tint it is to reach it from the class
  // header-props puts on every th (& below).
  //
  // `> div > .v-icon` rather than the icon's own class: that class contains
  // underscores, which Tailwind rewrites to spaces inside an arbitrary variant,
  // and escaping them breaks again because a JS string literal eats the
  // backslashes before they reach the DOM. This path is also narrower than a
  // plain `.v-icon` — the select-all checkbox sits three divs deeper, so it
  // keeps its own colour.
  const headerProps = {
    class: "bg-surface-darken-2 [&>div>.v-icon]:text-tertiary",
  };

  // Computed, not plain arrays: t() would otherwise be evaluated once at setup
  // and the titles would keep the locale that was active then.
  const columns = computed(() => [
    { title: t("admin.categories.id"), key: "id", sortable: true },
    {
      title: t("admin.categories.nameEnglish"),
      key: "name_en",
      sortable: true,
    },
    { title: t("admin.categories.nameArabic"), key: "name_ar", sortable: true },
    { title: t("admin.categories.creator"), key: "creator", sortable: false },
    { title: t("common.createdAt"), key: "created_at", sortable: true },
    { title: t("common.updatedAt"), key: "updated_at", sortable: true },
  ]);

  const headers = computed(() => [
    ...columns.value,
    { title: t("admin.categories.actions"), key: "actions", sortable: false },
  ]);

  const trashedHeaders = computed(() => [
    ...columns.value,
    {
      title: t("admin.categories.deletedAt"),
      key: "deleted_at",
      sortable: true,
    },
    { title: t("admin.categories.actions"), key: "actions", sortable: false },
  ]);

  async function load() {
    loading.value = true;
    try {
      categories.value = await categoryStore.fetchAllCategories();
    } finally {
      loading.value = false;
    }
  }

  function openCreate() {
    createOpen.value = true;
  }

  function openEdit(category: Category) {
    editing.value = category;
    editOpen.value = true;
  }

  function openDelete(category: Category) {
    deleting.value = category;
    deleteOpen.value = true;
  }

  async function onSaved(messageKey: "created" | "updated") {
    await load();
    notifier.notify(t(`admin.categories.${messageKey}`));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await categoryStore.deleteCategory(deleting.value.id);
      await load();
      notifier.notify(t("admin.categories.deleted"));
      deleteOpen.value = false;
    } catch {
      notifier.notify(t("admin.categories.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(category: Category) {
    restoringId.value = category.id;
    try {
      await categoryStore.restoreCategory(category.id);
      await load();
      notifier.notify(t("admin.categories.restored"));
    } catch {
      notifier.notify(t("admin.categories.restoreFailed"), "error");
    } finally {
      restoringId.value = null;
    }
  }

  /**
   * There is no batch endpoint, so each id is its own request. allSettled
   * rather than all: one rejection should not abandon the rest, and the count
   * of failures is what gets reported.
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
      (id) => categoryStore.deleteCategory(id),
      "admin.categories.bulkDeleted",
      "admin.categories.bulkDeleteFailed",
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
      (id) => categoryStore.restoreCategory(id),
      "admin.categories.bulkRestored",
      "admin.categories.bulkRestoreFailed",
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
      :label="t('admin.categories.search')"
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
      :items="liveCategories"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.categories.empty')"
      :search="search"
      show-select
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.categories.title") }}
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
            {{ t("admin.categories.add") }}
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
              t("admin.categories.deleteSelected", {
                count: selectedLive.length,
              })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.created_at="{ item }">
        {{ formatDateTime(item.created_at) }}
      </template>

      <template #item.updated_at="{ item }">
        {{ formatDateTime(item.updated_at) }}
      </template>

      <template #item.creator="{ item }">
        {{ item.creator || t("common.emptyValue") }}
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
      :items="trashedCategories"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.categories.trashedEmpty')"
      :search="search"
      show-select
      :sort-by="[{ key: 'deleted_at', order: 'desc' }]"
    >
      <template #top>
        <div class="bg-primary-darken-1 p-4 text-center">
          <h2 class="text-xl tracking-wider">
            {{ t("admin.categories.trashedTitle") }}
          </h2>

          <p class="mt-2">
            <span class="text-tertiary opacity-100">* </span>

            <span class="opacity-80">{{
              t("admin.categories.trashedTitleNote")
            }}</span>
          </p>
        </div>

        <!-- Restoring is not destructive, so it fires straight away where the
             bulk delete above asks for confirmation first. -->
        <div v-if="selectedTrashed.length > 0" class="p-3">
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
              t("admin.categories.restoreSelected", {
                count: selectedTrashed.length,
              })
            }}
          </v-btn>
        </div>
      </template>

      <template #item.creator="{ item }">
        {{ item.creator || t("common.emptyValue") }}
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
          :title="t('admin.categories.restore')"
          variant="text"
          @click="restore(item)"
        />
      </template>
    </v-data-table>

    <CategoryCreateDialog v-model="createOpen" @created="onSaved('created')" />

    <CategoryEditDialog
      v-if="editing"
      v-model="editOpen"
      :category="editing"
      @updated="onSaved('updated')"
    />

    <ConfirmDialog
      v-model="deleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="deletingInFlight"
      :message="t('admin.categories.deleteConfirm')"
      @confirm="destroy"
    />

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.categories.bulkDeleteConfirm', { count: selectedLive.length })
      "
      @confirm="bulkDestroy"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-categories"
}
</route>
