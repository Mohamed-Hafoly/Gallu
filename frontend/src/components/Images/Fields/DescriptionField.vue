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

  <!-- Physical indent, not logical — see the note in ReadOnlyField.vue. -->
  <p
    v-else
    :class="isRtl ? 'text-right mr-2' : 'text-left ml-2'"
    dir="auto"
  >
    {{ model || t("gallery.noDescription") }}
  </p>
</template>
