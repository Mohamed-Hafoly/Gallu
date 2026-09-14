<script setup lang="ts">
  import type { Category } from "@/types/category";
  import { computed } from "vue";
  import { useLocalizedName } from "@/composables/useLocalizedName";

  const props = withDefaults(
    defineProps<{
      items: Category[];
      /** Chips to show before collapsing the rest into "+N". 0 shows them all. */
      limit?: number;
    }>(),
    { limit: 4 },
  );

  const localizedName = useLocalizedName();

  const capped = computed(() => props.limit > 0);
  const shown = computed(() =>
    capped.value ? props.items.slice(0, props.limit) : props.items,
  );
  const hidden = computed(() => props.items.length - shown.value.length);
</script>

<template>
  <div class="flex gap-2 flex-wrap">
    <v-chip
      v-for="item in shown"
      :key="item.id"
      class="shrink-0"
      color="tertiary"
      label
      :size=" capped ? 'x-small' : 'default'"
      :title="localizedName(item)"
      variant="tonal"
    >
      <span class="block" :class="capped ? 'max-w-[12ch] truncate' : ''">{{
        localizedName(item)
      }}</span>
    </v-chip>

    <v-chip
      v-if="hidden > 0"
      class="shrink-0"
      color="tertiary"
      label
      size="x-small"
      variant="tonal"
    >
      +{{ hidden }}
    </v-chip>
  </div>
</template>
