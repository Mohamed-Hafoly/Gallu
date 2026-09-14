<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { useLocalizedName } from "@/composables/useLocalizedName";

  defineProps<{
    items: Category[];
  }>();

  const selectedCategoryIds = defineModel<number[]>({ default: () => [] });
  const localizedName = useLocalizedName();
</script>

<template>
  <v-chip-group v-model="selectedCategoryIds" column filter multiple>
    <v-chip
      v-for="item in items"
      :key="item.id"
      :class="selectedCategoryIds.includes(item.id) ? '' : 'opacity-80'"
      color="tertiary"
      label
      size="small"
      :value="item.id"
      :variant="selectedCategoryIds.includes(item.id) ? 'elevated' : 'tonal'"
    >
      {{ localizedName(item) }}
    </v-chip>
  </v-chip-group>
</template>
