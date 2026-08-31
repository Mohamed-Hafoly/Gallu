<script setup lang="ts">
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
      bidi-auto, because the value is user text and can be Latin in the Arabic
      UI: it needs its paragraph direction taken from its own first strong
      character for correct bidi ordering. See styles/main.scss.

      The indent can be logical (ms-) precisely because that class works in CSS
      rather than through dir="auto": it leaves this element's own `direction`
      as the UI's, so the indent follows the UI edge the text is pinned to. The
      dir="auto" this replaces would have flipped it to the content's side.
    -->
    <p class="bidi-auto ms-2">
      {{ value }}
    </p>
  </div>
</template>
