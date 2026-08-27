<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { useI18n } from "vue-i18n";

  defineProps<{
    items: Category[];
    editable?: boolean;
  }>();
  const selectedCategoryIds = defineModel<number[]>({ default: () => [] });

  const { t } = useI18n();
</script>

<template>
  <div>
    <div class="mb-2 text-lg font-semibold">
      {{ t("gallery.categories") }}
    </div>

    <!--
      Categories are optional, so the picker carries no rule and no caption: it
      neither takes part in the surrounding v-form nor has anything to say when
      nothing is picked.
    -->
    <CategoryPicker
      v-if="editable"
      v-model="selectedCategoryIds"
      class="ms-2"
      :items="items"
    />

    <template v-else>
      <CategoryChips
        v-if="items.length > 0"
        class="ms-2"
        :items="items"
        :limit="0"
      />

      <p v-else class="ms-2">{{ t("gallery.noCategories") }}</p>
    </template>
  </div>
</template>
