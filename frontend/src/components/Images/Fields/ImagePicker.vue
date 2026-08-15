<script setup lang="ts">
  import { ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  const props = defineProps<{
    alt?: string;
    initialSrc?: string;
    disabled?: boolean;
  }>();

  const file = defineModel<File | null>({ default: null });

  const { t } = useI18n();
  const { imageFile, IMAGE_MIME_TYPES } = useImageValidationRules();

  const fileInput = ref<HTMLInputElement | null>(null);
  const previewUrl = ref(props.initialSrc ?? "");
  const fileError = ref("");

  function pickFile() {
    if (props.disabled) return;
    fileInput.value?.click();
  }

  function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const picked = input.files?.[0];
    input.value = ""; // allow re-picking the same file

    if (!picked) return;

    const valid = imageFile(picked);
    if (valid !== true) {
      fileError.value = valid;
      return;
    }

    fileError.value = "";
    file.value = picked;
  }

  // Revoke the previous *local* preview URL so we don't leak object URLs
  // across picks — never revoke `initialSrc`, we don't own that one.
  watch(file, (picked, previousPicked) => {
    if (previousPicked && previewUrl.value !== props.initialSrc) {
      URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = picked ? URL.createObjectURL(picked) : (props.initialSrc ?? "");
  });
</script>

<template>
  <ImageStage v-if="previewUrl" :alt="alt ?? ''" :src="previewUrl" />

  <v-btn
    v-if="previewUrl"
    block
    class="my-6 px-2"
    color="tertiary"
    :disabled="disabled"
    prepend-icon="mdi-image-edit"
    variant="elevated"
    @click="pickFile"
  >
    {{ t("gallery.changeImage") }}
  </v-btn>

  <div
    v-else
    class="bg-primary border-2 border-dashed border-tertiary rounded-lg mx-4 my-6 h-32 flex flex-col items-center justify-center gap-2 cursor-pointer"
    role="button"
    tabindex="0"
    @click="pickFile"
    @keydown.enter="pickFile"
    @keydown.space.prevent="pickFile"
  >
    <v-icon color="on-surface" icon="mdi-image-plus" size="32" />
    <span class="text-caption text-center opacity-70">{{ t("gallery.chooseImage") }}</span>
  </div>

  <input
    ref="fileInput"
    :accept="IMAGE_MIME_TYPES.join(',')"
    class="hidden"
    type="file"
    @change="onFileSelected"
  />

  <p v-if="fileError" class="ms-2 mt-1 text-caption text-error">{{ fileError }}</p>
</template>
