<script setup lang="ts">
  /**
   * The filter/sort row above a card grid, shared by the images grid and the
   * documents grid.
   *
   * Holds no state of its own: the chip is a model, and the sort controls emit
   * rather than write, because both screens keep that state in the URL through
   * useListQuery. Every label is a prop for the same reason — the two grids
   * name the same controls after different things.
   */
  export interface FilterChip {
    value: string;
    label: string;
    count: number;
  }

  export interface SortOption {
    value: string;
    title: string;
  }

  defineProps<{
    chips: FilterChip[];
    sortOptions: SortOption[];
    sortBy: string;
    sortOrder: "asc" | "desc";
    searchLabel: string;
    sortLabel: string;
    sortDirectionLabel: string;
    selectLabel: string;
    /** Whether to offer selection mode at all. */
    canSelect: boolean;
    selecting: boolean;
  }>();

  const emit = defineEmits<{
    "update:sort-by": [value: string];
    "update:sort-order": [value: "asc" | "desc"];
    "toggle-selecting": [];
  }>();

  /** The active chip. */
  const filterChip = defineModel<string>({ required: true });

  /** What the user is typing; the caller debounces it. */
  const searchInput = defineModel<string | null>("search", { required: true });

  function toggleSortOrder(current: "asc" | "desc") {
    emit("update:sort-order", current === "asc" ? "desc" : "asc");
  }
</script>

<template>
  <div class="flex flex-col gap-4">
    <!--
      Styled to match /admin/images so the screens read as one app; the server
      variant takes no `:search` prop, the term rides in the request.
    -->
    <v-text-field
      v-model="searchInput"
      bg-color="surface-darken-2"
      clearable
      density="comfortable"
      hide-details
      :label="searchLabel"
      variant="outlined"
    >
      <template #prepend-inner>
        <v-icon class="opacity-100" color="tertiary" icon="mdi-magnify" />
      </template>

      <template #clear="{ props: clearProps }">
        <v-icon v-bind="clearProps" class="opacity-100" color="tertiary" />
      </template>
    </v-text-field>

    <!--
      Wraps below sm, with the sort controls dropping to their own line.
      basis-full makes that break deterministic rather than incidental: the chip
      group claims the whole first line, so the sort group has nowhere else to
      go. Tailwind's breakpoints are overridden to Vuetify's in
      styles/tailwind.css, so sm here is the same 600px useDisplay() uses.

      ms-auto on the sort group rather than justify-between on the container:
      space-between distributes items *within a line*, so once the sort group is
      alone on the second line it becomes the only item and lands at the line's
      start — the opposite of what is wanted. ms-auto pins it to the end whether
      it shares a row or has one to itself, and being logical it flips with RTL.

      min-w-0 on the chip group: the group scrolls horizontally, and a flex item
      defaults to min-width:auto, so without it the chips refuse to shrink and
      spill past the card edge instead of scrolling inside their line.
    -->
    <div class="mt-1 flex flex-wrap items-center gap-4">
      <v-chip-group
        v-model="filterChip"
        class="min-w-0 basis-full sm:basis-auto"
        color="tertiary"
        filter
        mandatory
      >
        <!--
          The count is a plain span rather than a v-badge: a badge floats over
          the chip's corner and would be clipped by the group's horizontal
          scroll on a narrow screen.
        -->
        <v-chip v-for="chip in chips" :key="chip.value" :value="chip.value">
          {{ chip.label }}
          <span class="ms-2 text-sm opacity-70">{{ chip.count }}</span>
        </v-chip>
      </v-chip-group>

      <div class="ms-auto flex shrink-0 items-center gap-2">
        <!--
          Both surfaces set explicitly, or they disagree: an outlined field is
          transparent and shows the page through it, while the dropdown is a
          v-list in a teleported overlay painting theme surface on its own.

          list-props rather than menu-props' contentClass — the list paints its
          own background over the overlay content, so a class on the overlay
          would sit underneath it and never show.
        -->
        <v-select
          bg-color="primary-darken-4"
          class="w-44"
          density="compact"
          hide-details
          item-title="title"
          item-value="value"
          :items="sortOptions"
          :label="sortLabel"
          :list-props="{ bgColor: 'primary-darken-4' }"
          :model-value="sortBy"
          variant="outlined"
          @update:model-value="emit('update:sort-by', $event)"
        />

        <v-btn
          color="tertiary"
          :icon="
            sortOrder === 'asc' ? 'mdi-sort-ascending' : 'mdi-sort-descending'
          "
          size="small"
          :title="sortDirectionLabel"
          variant="text"
          @click="toggleSortOrder(sortOrder)"
        />

        <!--
          Hidden where the caller could not act on anything anyway — see each
          grid's canSelect.
        -->
        <v-btn
          v-if="canSelect"
          :color="selecting ? 'primary' : 'tertiary'"
          :icon="
            selecting
              ? 'mdi-checkbox-multiple-marked'
              : 'mdi-checkbox-multiple-marked-outline'
          "
          size="small"
          :title="selectLabel"
          variant="text"
          @click="emit('toggle-selecting')"
        />
      </div>
    </div>
  </div>
</template>
