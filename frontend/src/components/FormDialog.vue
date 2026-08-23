<script setup lang="ts">
  withDefaults(
    defineProps<{
      title: string;
      /** Widened by dialogs that hold a list rather than a short form. */
      maxWidth?: number | string;
    }>(),
    { maxWidth: 500 },
  );

  const open = defineModel<boolean>({ default: false });
</script>

<template>
  <v-dialog v-model="open" :max-width="maxWidth">
    <v-card class="border-2 border-tertiary p-2" rounded="md">
      <v-card-title class="text-h6 tracking-wider text-center mt-2">{{
        title
      }}</v-card-title>

      <slot />
    </v-card>
  </v-dialog>
</template>
