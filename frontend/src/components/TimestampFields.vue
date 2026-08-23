<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";

  /**
   * The read-only created/updated pair every admin edit dialog ends with.
   *
   * Both are nullable on the API resources, and formatDateTime already falls
   * back to the empty-value dash, so a missing timestamp needs no guard here.
   */
  defineProps<{
    created?: string | null;
    updated?: string | null;
  }>();

  const { t } = useI18n();
  const { formatDateTime } = useDateFormat();
</script>

<template>
  <v-row dense>
    <v-text-field
      disabled
      :label="t('common.createdAt')"
      :model-value="formatDateTime(created)"
    />

    <v-text-field
      disabled
      :label="t('common.updatedAt')"
      :model-value="formatDateTime(updated)"
    />
  </v-row>
</template>
