<script setup lang="ts">
  import { useRtl } from "vuetify";

  /**
   * A dialog field the user can never change — the id, creator, and the three
   * timestamps. Callers pre-format: `value` is a plain string, not a date.
   *
   * On edit it becomes a *disabled* input rather than staying plain text, so the
   * edit form reads as one consistent set of fields; in view mode it matches the
   * plain-text presentation of TitleField and DescriptionField.
   */
  defineProps<{
    label: string;
    value: string;
    editable?: boolean;
  }>();

  const { isRtl } = useRtl();
</script>

<template>
  <v-text-field
    v-if="editable"
    class="mt-7"
    disabled
    :label="label"
    :model-value="value"
  />

  <div v-else class="mb-7 mt-5">
    <h2 class="mb-2 text-lg font-semibold">{{ label }}</h2>

    <!--
      The indent is physical (mr-/ml-), not logical (ms-): dir="auto" sets this
      element's direction from the text, so ms-2 would indent from whichever
      side the *content's* language starts — left for a Latin name in the Arabic
      UI. dir="auto" still drives bidi ordering and the ellipsis side.
    -->
    <p :class="isRtl ? 'text-right mr-2' : 'text-left ml-2'" dir="auto">
      {{ value }}
    </p>
  </div>
</template>
