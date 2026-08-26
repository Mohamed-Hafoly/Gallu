<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  defineProps<{
    editable?: boolean;
    /**
     * A server-side rejection for this field — today, the per-document title
     * collision, which the browser cannot check for itself. Vuetify composes
     * this with `rules`, so the local required/max checks still run; it clears
     * only when the owner clears it, not on the next keystroke's validation.
     */
    error?: string;
  }>();
  const model = defineModel<string>({ default: "" });

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { titleRules } = useImageValidationRules();
</script>

<template>
  <div v-if="editable" class="px-4 pt-4">
    <v-text-field
      v-model="model"
      :error-messages="error"
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
