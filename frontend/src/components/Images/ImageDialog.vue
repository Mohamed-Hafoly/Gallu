<script setup lang="ts">
  import { useI18n } from "vue-i18n";

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
</script>

<template>
  <v-dialog
    v-model="open"
    content-class="bg-[color-mix(in_oklab,rgb(var(--v-theme-surface)),black_50%)] rounded-lg"
    max-width="900"
    scrollable
  >
    <v-btn
      class="ml-auto"
      color="tertiary"
      icon="mdi-close"
      :title="t('gallery.close')"
      variant="text"
      @click="open = false"
    />

    <v-card class="p-2 pt-0 rounded-none rounded-b-lg">
      <slot />
    </v-card>
  </v-dialog>
</template>
