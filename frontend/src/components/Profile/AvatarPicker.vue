<script setup lang="ts">
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useImageValidationRules } from "@/composables/useImageValidationRules";

  const props = defineProps<{
    /** The avatar to show when no local file is picked. Never empty — the
     * backend falls back to the default image, so there is no empty state.
     * The parent swaps this for the default while a removal is pending. */
    initialSrc: string;
    removable?: boolean;
    editable?: boolean;
  }>();

  const emit = defineEmits<{
    remove: [];
  }>();

  const file = defineModel<File | null>({ default: null });

  const { t } = useI18n();
  const { imageFile, IMAGE_MIME_TYPES } = useImageValidationRules();

  const fileInput = ref<HTMLInputElement | null>(null);
  const previewUrl = ref(props.initialSrc);
  const fileError = ref("");

  // With a file already picked there is nothing to remove yet — cancelling is
  // the way back — so the trash stays hidden until the pick is cleared.
  const canRemove = computed(
    () => props.removable && props.editable && !file.value,
  );

  function pickFile() {
    if (!props.editable) return;
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
  // across picks — never revoke a server URL, we don't own those.
  watch(file, (picked, previousPicked) => {
    if (previousPicked && previewUrl.value !== props.initialSrc) {
      URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = picked
      ? URL.createObjectURL(picked)
      : props.initialSrc;
  });

  // The parent re-seeds `initialSrc` after a save, and swaps it for the
  // fallback while a removal is pending — follow it whenever no local file
  // is overriding the preview.
  watch(
    () => props.initialSrc,
    (src) => {
      if (!file.value) previewUrl.value = src;
    },
  );

  function requestRemove() {
    fileError.value = "";
    emit("remove");
  }
</script>

<template>
  <div class="flex flex-col items-center gap-2">
    <v-avatar class="relative" size="140">
      <v-img :alt="t('profile.avatar')" cover :src="previewUrl" />

      <!-- Nested inside v-avatar so its overflow:hidden crops the overlay to
           the circle. `flex-1` on each half is what makes the edit half fill
           the whole overlay when there is nothing to delete. Keyboard handlers
           mirror ImagePicker's drop zone — these are real controls. -->
      <div
        v-if="editable"
        class="absolute inset-x-0 bottom-0 h-full flex flex-col"
      >
        <div
          :aria-label="t('profile.changeAvatar')"
          class="flex-1 flex items-center justify-center cursor-pointer bg-black/55 hover:bg-black/80 transition-colors"
          role="button"
          tabindex="0"
          @click="pickFile"
          @keydown.enter="pickFile"
          @keydown.space.prevent="pickFile"
        >
          <v-icon color="tertiary" icon="mdi-pencil" size="20" />
        </div>

        <div
          v-if="canRemove"
          :aria-label="t('profile.removeAvatar')"
          class="flex-1 flex items-center justify-center cursor-pointer bg-black/55 hover:bg-black/80 transition-colors"
          role="button"
          tabindex="0"
          @click="requestRemove"
          @keydown.enter="requestRemove"
          @keydown.space.prevent="requestRemove"
        >
          <v-icon color="error" icon="mdi-delete" size="20" />
        </div>
      </div>
    </v-avatar>

    <input
      ref="fileInput"
      :accept="IMAGE_MIME_TYPES.join(',')"
      class="hidden"
      type="file"
      @change="onFileSelected"
    />

    <p v-if="fileError" class="text-error">{{ fileError }}</p>
  </div>
</template>
