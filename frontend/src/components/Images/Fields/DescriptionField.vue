<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  defineProps<{ editable?: boolean }>();
  const model = defineModel<string>({ default: "" });

  const { t } = useI18n();
  const { descriptionRules } = useImageValidationRules();
</script>

<template>
  <div class="mt-7 mb-2 text-lg font-semibold">{{ t("gallery.description") }}</div>

  <v-textarea
    v-if="editable"
    v-model="model"
    auto-grow
    class="ms-2"
    :hint="t('gallery.descriptionHint')"
    persistent-hint
    :placeholder="t('gallery.descriptionPlaceholder')"
    rows="2"
    :rules="descriptionRules"
  />

  <!-- Logical indent, and bidi-auto — see the note in ReadOnlyField.vue. -->
  <p v-else class="bidi-auto ms-2">
    {{ model || t("gallery.noDescription") }}
  </p>
</template>
