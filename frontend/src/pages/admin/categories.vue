<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useCategoryStore } from "@/stores/category";

  const { locale, t } = useI18n();
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

  const snackbar = ref(false);
  const message = ref("");
  const messageColor = ref<"success" | "error">("success");

  // One fetch feeds both tables; they are just two views of the same array,
  // which is why restoring only needs a single refetch.
  const liveCategories = computed(() =>
    categories.value.filter((category) => !category.deleted_at),
  );
  const trashedCategories = computed(() =>
    categories.value.filter((category) => category.deleted_at),
  );

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

  function formatDeletedAt(value?: string | null) {
    if (!value) return t("common.emptyValue");

    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(value));
  }

  function notify(text: string, color: "success" | "error" = "success") {
    message.value = text;
    messageColor.value = color;
    snackbar.value = true;
  }

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
    notify(t(`admin.categories.${messageKey}`));
  }

  async function destroy() {
    if (!deleting.value) return;

    deletingInFlight.value = true;
    try {
      await categoryStore.deleteCategory(deleting.value.id);
      await load();
      notify(t("admin.categories.deleted"));
      deleteOpen.value = false;
    } catch {
      notify(t("admin.categories.deleteFailed"), "error");
    } finally {
      deletingInFlight.value = false;
    }
  }

  async function restore(category: Category) {
    restoringId.value = category.id;
    try {
      await categoryStore.restoreCategory(category.id);
      await load();
      notify(t("admin.categories.restored"));
    } catch {
      notify(t("admin.categories.restoreFailed"), "error");
    } finally {
      restoringId.value = null;
    }
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
      :header-props="{ class: 'bg-surface-darken-2' }"
      :headers="headers"
      :items="liveCategories"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.categories.empty')"
      :search="search"
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
        </div>
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
      class="mt-8"
      :header-props="{ class: 'bg-surface-darken-2' }"
      :headers="trashedHeaders"
      :items="trashedCategories"
      :items-per-page="10"
      :loading="loading"
      :no-data-text="t('admin.categories.trashedEmpty')"
      :search="search"
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
      </template>

      <template #item.creator="{ item }">
        {{ item.creator || t("common.emptyValue") }}
      </template>

      <template #item.deleted_at="{ item }">
        {{ formatDeletedAt(item.deleted_at) }}
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

    <CategoryCreateDialog
      v-model="createOpen"
      @created="onSaved('created')"
      @failed="notify(t('admin.categories.createFailed'), 'error')"
    />

    <CategoryEditDialog
      v-if="editing"
      v-model="editOpen"
      :category="editing"
      @failed="notify(t('admin.categories.updateFailed'), 'error')"
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

    <v-snackbar v-model="snackbar" :color="messageColor" :timeout="4000">
      {{ message }}
    </v-snackbar>
  </v-container>
</template>

<route lang="json">
{
  "name": "admin-categories"
}
</route>
