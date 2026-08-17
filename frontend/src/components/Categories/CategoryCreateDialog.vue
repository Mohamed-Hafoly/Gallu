<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthStore } from "@/stores/auth";
  import { useCategoryStore } from "@/stores/category";

  const emit = defineEmits<{
    created: [];
    failed: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const authStore = useAuthStore();
  const categoryStore = useCategoryStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({ name_en: "", name_ar: "" });

  // Start fresh next time, whether closed via Cancel or Create.
  watch(open, (isOpen) => {
    if (isOpen) return;

    form.name_en = "";
    form.name_ar = "";
    formRef.value?.resetValidation();
  });

  function close() {
    open.value = false;
  }

  async function submit() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    submitting.value = true;
    try {
      await categoryStore.createCategory({
        name_en: form.name_en.trim(),
        name_ar: form.name_ar.trim(),
      });
      emit("created");
      close();
    } catch {
      emit("failed");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <CategoryDialog v-model="open" :title="t('admin.categories.createTitle')">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">

        <v-text-field
          disabled
          :label="t('admin.categories.creator')"
          :model-value="authStore.user?.name"
        />

        <CategoryNameFields
          v-model:name-ar="form.name_ar"
          v-model:name-en="form.name_en"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="submitting" @click="close">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          color="primary"
          :disabled="formValid !== true"
          :loading="submitting"
          prepend-icon="mdi-content-save"
          type="submit"
          variant="elevated"
        >
          {{ t("common.create") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </CategoryDialog>
</template>
