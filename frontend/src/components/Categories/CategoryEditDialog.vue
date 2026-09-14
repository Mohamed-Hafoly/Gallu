<script setup lang="ts">
  import type { Category } from "@/types/category";
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useCategoryStore } from "@/stores/category";
  import { useNotifierStore } from "@/stores/notifier";

  const props = defineProps<{ category: Category }>();


  const emit = defineEmits<{
    updated: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const categoryStore = useCategoryStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({ name_en: "", name_ar: "" });
  const original = reactive({ name_en: "", name_ar: "" });

  // Refill whenever a different category is opened.
  watch(
    () => props.category,
    (category) => {
      form.name_en = category.name_en;
      form.name_ar = category.name_ar;
      original.name_en = form.name_en;
      original.name_ar = form.name_ar;
      formRef.value?.resetValidation();
    },
    { immediate: true },
  );

  // The page keeps this dialog mounted and hands back the same category object
  // each time, so the watcher above would not fire on reopening the same row —
  // an abandoned edit would still be sitting in the form, with Save enabled.
  watch(open, (isOpen) => {
    if (isOpen) return;

    form.name_en = original.name_en;
    form.name_ar = original.name_ar;
    formRef.value?.resetValidation();
  });

  // Compared trimmed, because the backend trims before storing — otherwise a
  // stray trailing space would enable Save for a no-op edit.
  const isDirty = computed(
    () =>
      form.name_en.trim() !== original.name_en.trim() ||
      form.name_ar.trim() !== original.name_ar.trim(),
  );

  const canSave = computed(() => formValid.value === true && isDirty.value);

  function close() {
    open.value = false;
  }

  async function submit() {
    const { valid } = await formRef.value!.validate();
    if (!valid || !isDirty.value) return;

    submitting.value = true;
    try {
      // Only the changed names are sent: the backend accepts a partial update
      // and requires at least one, which isDirty guarantees.
      const payload: { name_en?: string; name_ar?: string } = {};
      if (form.name_en.trim() !== original.name_en.trim()) {
        payload.name_en = form.name_en.trim();
      }
      if (form.name_ar.trim() !== original.name_ar.trim()) {
        payload.name_ar = form.name_ar.trim();
      }

      await categoryStore.updateCategory(props.category.id, payload);
      emit("updated");
      close();
    } catch {
      notifier.notify(t("admin.categories.updateFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog v-model="open" :title="t('admin.categories.editTitle')">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text>
        <v-row dense>
          <v-text-field
            disabled
            :label="t('admin.categories.id')"
            :model-value="category.id"
          />

          <v-text-field
            disabled
            :label="t('admin.categories.creator')"
            :model-value="category.creator ?? t('common.deletedUser')"
          />
        </v-row>

        <CategoryNameFields
          v-model:name-ar="form.name_ar"
          v-model:name-en="form.name_en"
        />

        <TimestampFields
          :created="category.created_at"
          :updated="category.updated_at"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="submitting" @click="close">
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
          <template #loader>
            <v-progress-circular indeterminate />
          </template>
          {{ t("common.save") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </FormDialog>
</template>
