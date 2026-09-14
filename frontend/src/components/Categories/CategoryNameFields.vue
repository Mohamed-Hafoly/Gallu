<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useCategoryValidationRules } from "@/composables/useCategoryValidationRules";

  const nameEn = defineModel<string>("nameEn", { default: "" });
  const nameAr = defineModel<string>("nameAr", { default: "" });

  const { t } = useI18n();
  const { nameRules } = useCategoryValidationRules();
</script>

<template>
  <div class="flex flex-col gap-6">
    <v-text-field
      v-model="nameEn"
      :label="t('admin.categories.nameEnglish')"
      :rules="nameRules"
    />

    <v-text-field
      v-model="nameAr"
      :label="t('admin.categories.nameArabic')"
      :rules="nameRules"
    />
  </div>
</template>
