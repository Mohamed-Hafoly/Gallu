<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { useI18n } from "vue-i18n";

  defineProps<{
    items: Category[];
    editable?: boolean;
    error?: string;
  }>();
  const selectedCategoryIds = defineModel<number[]>({ default: () => [] });

  const { t } = useI18n();
</script>

<template>
  <div>
    <div class="mb-4 mt-4 text-caption">{{ t("gallery.categories") }}</div>

    <CategoryPicker v-if="editable" v-model="selectedCategoryIds" class="ms-2" :items="items" />

    <p
      v-if="editable"
      class="ms-2 text-caption"
      :class="error ? 'text-error' : 'opacity-70'"
    >
      {{ error || t("gallery.categoriesHint") }}
    </p>

    <template v-else>
      <CategoryChips v-if="items.length > 0" class="ms-2" :items="items" :limit="0" />
      <p v-else class="text-body-2 ms-2">{{ t("gallery.noCategories") }}</p>
    </template>
  </div>
</template>
