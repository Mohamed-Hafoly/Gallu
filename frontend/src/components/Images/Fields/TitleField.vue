<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  defineProps<{ editable?: boolean }>();
  const model = defineModel<string>({ default: "" });

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { titleRules } = useImageValidationRules();
</script>

<template>
  <div v-if="editable" class="px-4 pt-4">
    <v-text-field
      v-model="model"
      :hint="t('gallery.titleHint')"
      :label="t('gallery.titleLabel')"
      persistent-hint
      :rules="titleRules"
    />
  </div>

  <v-card-title
    v-else
    :class="isRtl ? 'text-right' : 'text-left'"
    dir="auto"
  >
    {{ model }}
  </v-card-title>
</template>
