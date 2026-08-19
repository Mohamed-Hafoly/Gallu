<script setup lang="ts">
  defineProps<{ title: string }>();

  const open = defineModel<boolean>({ default: false });
</script>

<template>
  <v-dialog v-model="open" max-width="500">
    <v-card class="border-2 border-tertiary p-2" rounded="md">
      <v-card-title class="text-h6 tracking-wider text-center mt-2">{{ title }}</v-card-title>

      <slot />
    </v-card>
  </v-dialog>
</template>
