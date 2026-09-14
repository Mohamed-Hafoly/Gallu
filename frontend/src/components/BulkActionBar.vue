<script setup lang="ts">
  /**
   * The action for the current selection, shared by the images and documents
   * grids.
   *
   * The action follows the chip: rows on the trash chip can only be put back,
   * everywhere else they can only be binned. Restore fires straight away where
   * delete asks first, matching the admin screens — restoring is not
   * destructive, so the caller wires the confirmation to `delete` only.
   *
   * Renders nothing until something is picked: selection mode on its own is not
   * enough, or an empty bar would sit under the filters doing nothing.
   */
  defineProps<{
    count: number;
    /** Whether the trash chip is active, which flips the action. */
    trashed: boolean;
    loading: boolean;
    deleteLabel: string;
    restoreLabel: string;
  }>();

  defineEmits<{ delete: []; restore: [] }>();
</script>

<template>
  <div v-if="count > 0" class="mt-3">
    <v-btn
      v-if="trashed"
      block
      color="tertiary"
      :loading="loading ? 'on-tertiary' : false"
      prepend-icon="mdi-restore"
      variant="elevated"
      @click="$emit('restore')"
    >
      {{ restoreLabel }}
    </v-btn>

    <v-btn
      v-else
      block
      color="error"
      :loading="loading"
      prepend-icon="mdi-delete"
      variant="elevated"
      @click="$emit('delete')"
    >
      {{ deleteLabel }}
    </v-btn>
  </div>
</template>
