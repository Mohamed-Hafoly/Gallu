<script setup lang="ts">
  import { useI18n } from "vue-i18n";

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
</script>

<template>
  <v-dialog
    v-model="open"
    content-class="bg-surface-darken-2 rounded-lg"
    max-width="900"
  >
    <v-card class="p-2 pt-0 rounded-lg">
      <v-btn
        class="ml-auto"
        color="tertiary"
        icon="mdi-close"
        :title="t('gallery.close')"
        variant="text"
        @click="open = false"
      />

      <slot />
    </v-card>
  </v-dialog>
</template>
