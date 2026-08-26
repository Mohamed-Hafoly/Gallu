<script setup lang="ts">
  import type { Category } from "@/types/category";
  import type { AxiosError } from "axios";
  import type { VForm } from "vuetify/components";
  import { computed, onMounted, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useCategoryStore } from "@/stores/category";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  /**
   * The document to upload into, taken from the route by the caller rather than
   * chosen here: every image belongs to exactly one document, and the only way
   * to reach this dialog is from inside that document's page.
   */
  const props = defineProps<{ documentId: number }>();

  const emit = defineEmits<{
    created: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const categoryStore = useCategoryStore();
  const notifier = useNotifierStore();
  const imageStore = useImageStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);

  const form = reactive({
    title: "",
    selectedCategoryIds: [] as number[],
    description: "",
  });

  const categories = ref<Category[]>([]);
  const selectedFile = ref<File | null>(null);
  const categoryError = ref("");
  const fileError = ref("");
  // Server-side only: the title must be unique within the document, which the
  // browser has no cheap way to know. Cleared as soon as the title is edited,
  // so the message never outlives the value it was about.
  const titleError = ref("");
  const submitting = ref(false);

  onMounted(async () => {
    categories.value = await categoryStore.fetchPickerCategories();
  });

  const canSubmit = computed(
    () =>
      formValid.value === true &&
      selectedFile.value !== null &&
      form.selectedCategoryIds.length > 0,
  );

  function reset() {
    form.title = "";
    form.selectedCategoryIds = [];
    form.description = "";
    selectedFile.value = null;
    categoryError.value = "";
    fileError.value = "";
    titleError.value = "";
    formRef.value?.resetValidation();
  }

  function close() {
    open.value = false;
  }

  // Start fresh next time the dialog opens, whether closed via Cancel or Create.
  watch(open, (isOpen) => {
    if (!isOpen) reset();
  });

  async function submit() {
    const { valid } = await formRef.value!.validate();
    categoryError.value =
      form.selectedCategoryIds.length > 0
        ? ""
        : t("gallery.categoriesRequired");
    fileError.value = selectedFile.value ? "" : t("gallery.imageRequired");

    if (!valid || form.selectedCategoryIds.length === 0 || !selectedFile.value)
      return;

    submitting.value = true;
    try {
      // Trimmed at submit rather than with v-model.trim, which strips the
      // space as it is typed.
      await imageStore.createImage({
        title: form.title.trim(),
        document_id: props.documentId,
        description: form.description.trim() || undefined,
        selected_category_ids: form.selectedCategoryIds,
        image: selectedFile.value,
      });
      emit("created");
      close();
    } catch (error) {
      titleError.value = (error as AxiosError).fieldErrors?.title?.[0] ?? "";
      notifier.notify(t("gallery.uploadFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <ImageDialog v-model="open">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <ImagePicker
        v-model="selectedFile"
        :alt="form.title || t('gallery.newImage')"
        editable
      />

      <p v-if="fileError" class="ms-4 mt-1 text-error">
        {{ fileError }}
      </p>

      <TitleField
        v-model="form.title"
        editable
        :error="titleError"
        @update:model-value="titleError = ''"
      />

      <v-card-text>
        <CategoriesField
          v-model="form.selectedCategoryIds"
          editable
          :error="categoryError"
          :items="categories"
        />

        <DescriptionField v-model="form.description" editable />
      </v-card-text>

      <!--
        [direction:ltr] with Cancel before the primary action, matching
        ImageDetailDialog's action row. Without it the flex main axis follows
        the UI direction, so the fixed DOM order rendered Create on the right in
        Arabic but on the left in English — the buttons visibly swapped places
        between locales. justify-end resolves against the forced ltr here.
      -->
      <v-card-actions class="gap-5 flex justify-end [direction:ltr]">
        <v-btn :disabled="submitting" variant="flat" @click="close">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          color="primary"
          :disabled="!canSubmit"
          :loading="submitting"
          prepend-icon="mdi-plus"
          type="submit"
          variant="elevated"
        >
          {{ t("common.create") }}
          <template #loader>
            <v-progress-circular indeterminate />
          </template>
        </v-btn>
      </v-card-actions>
    </v-form>
  </ImageDialog>
</template>
