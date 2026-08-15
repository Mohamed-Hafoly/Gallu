<script setup lang="ts">
  import type { Category } from "@/types/category";
  import type { Image } from "@/types/image";
  import type { VForm } from "vuetify/components";
  import { computed, onMounted, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useCategoryStore } from "@/stores/category";
  import { useImageStore } from "@/stores/image";

  const props = defineProps<{
    image: Image | null;
  }>();

  const emit = defineEmits<{
    deleted: [id: number];
    updated: [];
    failed: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const categoryStore = useCategoryStore();
  const imageStore = useImageStore();

  const confirmingDelete = ref(false);
  const deleting = ref(false);
  const pickedFile = ref<File | null>(null);
  const isEditing = ref(false);
  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const allCategories = ref<Category[]>([]);
  const categoryError = ref("");
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
    allCategories.value = await categoryStore.fetchCategories();
  });

  const isDirty = computed(() => {
    if (!isEditing.value) return false;
    if (form.title !== original.title) return true;
    if (form.description !== original.description) return true;
    if (pickedFile.value) return true;

   const a = [...form.selectedCategoryIds].sort((x, y) => x - y);
    const b = [...original.selectedCategoryIds].sort((x, y) => x - y);
    return (
      a.length !== b.length || a.some((value, index) => value !== b[index])
    );
  });

  const canSave = computed(
    () =>
      isDirty.value &&
      formValid.value === true &&
      form.selectedCategoryIds.length > 0,
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
    categoryError.value = "";
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
      emit("failed");
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
    formRef.value?.resetValidation();
    isEditing.value = false;
  }

  async function onSubmitEdit() {
    if (!isEditing.value || !props.image) return;

    const { valid } = await formRef.value!.validate();
    categoryError.value =
      form.selectedCategoryIds.length > 0 ? "" : t("gallery.categoriesRequired");

    if (!valid || form.selectedCategoryIds.length === 0) return;

    submitting.value = true;
    try {
      await imageStore.updateImage(props.image.id, {
        title: form.title,
        description: form.description || undefined,
        selected_category_ids: form.selectedCategoryIds,
        image: pickedFile.value ?? undefined,
      });

      original.title = form.title;
      original.description = form.description;
      original.selectedCategoryIds = [...form.selectedCategoryIds];
      pickedFile.value = null;
      isEditing.value = false;
      emit("updated");
    } catch {
      emit("failed");
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
        :disabled="!isEditing"
        :initial-src="image.url"
      />

      <TitleField v-model="form.title" :editable="isEditing" />

      <v-card-text>
        <CategoriesField
          v-model="form.selectedCategoryIds"
          class="mt-5"
          :editable="isEditing"
          :error="categoryError"
          :items="isEditing ? allCategories : image.categories"
        />

        <DescriptionField v-model="form.description" :editable="isEditing" />
      </v-card-text>

      <v-card-actions
        class="flex-row align-center justify-between [direction:ltr]"
      >
        <v-btn
          color="error"
          prepend-icon="mdi-delete"
          variant="elevated"
          @click="confirmingDelete = true"
        >
          {{ t("gallery.delete") }}
        </v-btn>

        <v-btn
          v-if="!isEditing"
          prepend-icon="mdi-pencil"
          variant="elevated"
          @click="isEditing = true"
        >
          {{ t("gallery.edit") }}
        </v-btn>

        <div v-else class="gap-5 flex [justify-content:right]">
          <v-btn :disabled="submitting" variant="flat" @click="cancelEdit">
            {{ t("gallery.cancel") }}
          </v-btn>

          <v-btn
            color="primary"
            :disabled="!canSave"
            :loading="submitting"
            prepend-icon="mdi-content-save"
            type="submit"
            variant="elevated"
          >
            {{ t("gallery.save") }}
          </v-btn>

        </div>
      </v-card-actions>
    </v-form>
  </ImageDialog>

  <ConfirmDialog
    v-model="confirmingDelete"
    confirm-icon="mdi-delete"
    :confirm-label="deleting ? t('gallery.deleting') : t('gallery.delete')"
    :loading="deleting"
    :message="t('gallery.deleteConfirm')"
    @confirm="destroy"
  />
</template>
