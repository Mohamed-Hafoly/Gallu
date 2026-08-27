<script setup lang="ts">
  import type { Category } from "@/types/category";
  import type { Image } from "@/types/image";
  import type { AxiosError } from "axios";
  import type { VForm } from "vuetify/components";
  import { computed, onMounted, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useImagePermissions } from "@/composables/useImagePermissions";
  import { useAuthStore } from "@/stores/auth";
  import { useCategoryStore } from "@/stores/category";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  const props = defineProps<{
    image: Image | null;
  }>();

  const emit = defineEmits<{
    deleted: [id: number];
    updated: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const { formatDateTime } = useDateFormat();
  const { canEdit } = useImagePermissions();
  const authStore = useAuthStore();
  const categoryStore = useCategoryStore();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();

  const confirmingDelete = ref(false);

  /** A deleted image is read-only here — ImageResource serves `deleted_at`. */
  const isTrashed = computed(() => Boolean(props.image?.deleted_at));

  /**
   * Gates the id row, and cosmetically: the id is in the payload every caller
   * already receives, so this hides a field rather than protecting anything —
   * the same kind of check useNavLinks and the router guard make.
   */
  const isSuperAdmin = computed(() => Boolean(authStore.user?.is_super_admin));
  const deleting = ref(false);
  const pickedFile = ref<File | null>(null);
  const isEditing = ref(false);
  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const allCategories = ref<Category[]>([]);
  // Server-side only: the title must be unique within the document, which the
  // browser has no cheap way to know. Cleared as soon as the title is edited,
  // so the message never outlives the value it was about.
  const titleError = ref("");
  const submitting = ref(false);

  const form = reactive({
    title: "",
    description: "",
    selectedCategoryIds: [] as number[],
  });
  const original = reactive({
    title: "",
    description: "",
    selectedCategoryIds: [] as number[],
  });

  onMounted(async () => {
    allCategories.value = await categoryStore.fetchPickerCategories();
  });

  const isDirty = computed(() => {
    if (!isEditing.value) return false;
    // Compared trimmed, because the backend trims before storing — otherwise
    // a stray trailing space would enable Save for a no-op edit.
    if (form.title.trim() !== original.title.trim()) return true;
    if (form.description.trim() !== original.description.trim()) return true;
    if (pickedFile.value) return true;

    // Category order is click order from the chip group, so compare as sets.
    // The length check covers removals, which `some` alone can't detect.
    if (form.selectedCategoryIds.length !== original.selectedCategoryIds.length)
      return true;

    const originalIds = new Set(original.selectedCategoryIds);
    return form.selectedCategoryIds.some((id) => !originalIds.has(id));
  });

  const canSave = computed(
    () => isDirty.value && formValid.value === true,
  );

  function resetEditState() {
    if (!props.image) return;

    form.title = props.image.title;
    form.description = props.image.description ?? "";
    form.selectedCategoryIds = props.image.categories.map((c) => c.id);
    original.title = form.title;
    original.description = form.description;
    original.selectedCategoryIds = [...form.selectedCategoryIds];

    isEditing.value = false;
    pickedFile.value = null;
    titleError.value = "";
    formRef.value?.resetValidation();
  }

  // Reset edit state whenever a (possibly different) image is shown.
  watch(() => props.image, resetEditState, { immediate: true });

  // Closing the dialog should always drop back to a clean, read-only state —
  // never leave the confirm step or an in-progress edit armed for next time.
  watch(open, (isOpen) => {
    if (!isOpen) {
      confirmingDelete.value = false;
      resetEditState();
    }
  });

  function close() {
    open.value = false;
  }

  async function destroy() {
    if (!props.image) return;

    deleting.value = true;
    try {
      await imageStore.deleteImage(props.image.id);
      emit("deleted", props.image.id);
      close();
    } catch {
      notifier.notify(t("gallery.deleteFailed"), "error");
    } finally {
      deleting.value = false;
      confirmingDelete.value = false;
    }
  }

  function cancelEdit() {
    form.title = original.title;
    form.description = original.description;
    form.selectedCategoryIds = [...original.selectedCategoryIds];
    pickedFile.value = null;
    titleError.value = "";
    formRef.value?.resetValidation();
    isEditing.value = false;
  }

  async function onSubmitEdit() {
    if (!isEditing.value || !props.image) return;

    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    submitting.value = true;
    try {
      // Trimmed at submit rather than with v-model.trim, which strips the
      // space as it is typed.
      const title = form.title.trim();
      const description = form.description.trim();

      await imageStore.updateImage(props.image.id, {
        title,
        description: description || undefined,
        selected_category_ids: form.selectedCategoryIds,
        image: pickedFile.value ?? undefined,
      });

      // Closing is what resets the form: the `open` watcher runs
      // resetEditState(), so there is no state to tidy up here.
      emit("updated");
      close();
    } catch (error) {
      titleError.value = (error as AxiosError).fieldErrors?.title?.[0] ?? "";
      // Distinct from the delete message above — the page previously reported
      // both through one `failed` event, so an edit failure said "delete failed".
      notifier.notify(t("gallery.updateFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <ImageDialog v-if="image" v-model="open">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="onSubmitEdit">
      <ImagePicker
        v-model="pickedFile"
        :alt="image.title"
        :editable="isEditing"
        :initial-src="image.url"
      />

      <TitleField
        v-model="form.title"
        class="mt-4 font-bold"
        :editable="isEditing"
        :error="titleError"
        @update:model-value="titleError = ''"
      />

      <v-card-text class="text-base">
        <!--
          Every ReadOnlyField here sits outside the edit flow — they touch
          neither `form` nor `original`, so none of them can make isDirty report
          a change or enable Save.

          They still take `:editable`, which is what makes them mirror the rest
          of the form: plain text in view mode, a disabled input while editing,
          so the layout does not jump when the mode changes.
        -->
        <ReadOnlyField
          v-if="isSuperAdmin"
          :editable="isEditing"
          :label="t('admin.images.id')"
          :value="String(image.id)"
        />

        <ReadOnlyField
          :editable="isEditing"
          :label="t('gallery.creator')"
          :value="image.creator"
        />

        <CategoriesField
          v-model="form.selectedCategoryIds"
          class="mt-5"
          :editable="isEditing"
            :items="isEditing ? allCategories : image.categories"
        />

        <DescriptionField v-model="form.description" :editable="isEditing" />

        <ReadOnlyField
          :editable="isEditing"
          :label="t('common.createdAt')"
          :value="formatDateTime(image.created_at)"
        />

        <ReadOnlyField
          :editable="isEditing"
          :label="t('common.updatedAt')"
          :value="formatDateTime(image.updated_at)"
        />

        <!--
          Only for a trashed image, where it is the timestamp that matters.
          formatDateTime returns the empty-value dash for a null, so this could
          render harmlessly on a live image — it is hidden because an empty
          "Deleted" row is noise, not because it would break.
        -->
        <ReadOnlyField
          v-if="isTrashed"
          :editable="isEditing"
          :label="t('admin.images.deletedAt')"
          :value="formatDateTime(image.deleted_at)"
        />
      </v-card-text>

      <!--
        Hidden entirely for a deleted image: it can only be restored, which the
        gallery's trash chip offers on the card. Editing or deleting one again
        would be meaningless, and the second delete would 404 anyway.

        Hidden too for anyone who may not change this image — a member looking
        at a teammate's. One check covers both buttons because ImagePolicy's
        delete delegates to update, so canDelete *is* canEdit; and it gates the
        container rather than the two buttons so nobody is left staring at an
        empty action bar. The ConfirmDialog below sits outside this block but is
        only ever opened from the Delete button, so it needs no gate of its own.
      -->
      <v-card-actions
        v-if="!isTrashed && canEdit(image)"
        class="flex-row items-center justify-between [direction:ltr]"
      >
        <v-btn
          color="error"
          prepend-icon="mdi-delete"
          variant="elevated"
          @click="confirmingDelete = true"
        >
          {{ t("common.delete") }}
        </v-btn>

        <v-btn
          v-if="!isEditing"
          prepend-icon="mdi-pencil"
          variant="elevated"
          @click="isEditing = true"
        >
          {{ t("common.edit") }}
        </v-btn>

        <div v-else class="gap-5 flex [justify-content:right]">
          <v-btn :disabled="submitting" variant="flat" @click="cancelEdit">
            {{ t("common.cancel") }}
          </v-btn>

          <v-btn
            color="primary"
            :disabled="!canSave"
            :loading="submitting"
            prepend-icon="mdi-content-save-edit"
            type="submit"
            variant="elevated"
          >
            {{ t("common.save") }}
          </v-btn>
        </div>
      </v-card-actions>
    </v-form>
  </ImageDialog>

  <ConfirmDialog
    v-model="confirmingDelete"
    confirm-icon="mdi-delete"
    :confirm-label="deleting ? t('common.deleting') : t('common.delete')"
    :loading="deleting"
    :message="t('gallery.deleteConfirm')"
    @confirm="destroy"
  />
</template>
