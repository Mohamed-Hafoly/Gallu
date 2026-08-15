<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  defineProps<{ editable?: boolean }>();
  const model = defineModel<string>({ default: "" });

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { descriptionRules } = useImageValidationRules();
</script>

<template>
  <div class="mt-5 mb-4 text-caption">{{ t("gallery.description") }}</div>

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

  <p
    v-else
    class="text-body-2 ms-2"
    :class="isRtl ? 'text-right' : 'text-left'"
    dir="auto"
  >
    {{ model || t("gallery.noDescription") }}
  </p>
</template>
