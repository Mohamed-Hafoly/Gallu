<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
  import { useAuthStore } from "@/stores/auth";

  interface UpdatePayload {
    name: string;
    email: string;
  }

  const { t } = useI18n();
  const { nameRules, emailRules } = useAuthValidationRules();
  const isSubmitting = ref(false);
  const isEditing = ref(false);
  const authStore = useAuthStore();

  const currentUser = computed(() => authStore.user!);

  const formData = reactive<UpdatePayload>({
    name: currentUser.value.name,
    email: currentUser.value.email,
  });
  const original = reactive<UpdatePayload>({
    name: currentUser.value.name,
    email: currentUser.value.email,
  });

  const pickedAvatar = ref<File | null>(null);
  const removeAvatar = ref(false);

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const errorMessage = ref("");

  // While a removal is pending we preview the default image, since that is
  // what saving would leave the user with.
  const avatarSrc = computed(() =>
    removeAvatar.value
      ? currentUser.value.default_avatar_url
      : currentUser.value.avatar_url,
  );

  const isDirty = computed(() => {
    if (!isEditing.value) return false;

    if (formData.name.trim() !== original.name.trim()) return true;
    if (formData.email.trim() !== original.email.trim()) return true;
    return Boolean(pickedAvatar.value) || removeAvatar.value;
  });

  const canSave = computed(() => isDirty.value && formValid.value === true);

  function resetAvatarState() {
    pickedAvatar.value = null;
    removeAvatar.value = false;
  }

  function startEditing() {
    isEditing.value = true;
  }

  function cancelEditing() {
    formData.name = original.name;
    formData.email = original.email;

    resetAvatarState();
    formRef.value?.resetValidation();
    errorMessage.value = "";
    isEditing.value = false;
  }

  async function confirmEditing() {
    const { valid } = await formRef.value!.validate();
    if (!valid || !isDirty.value) return;

    isSubmitting.value = true;
    errorMessage.value = "";

    try {
      // Trimmed at submit rather than with v-model.trim, which strips the
      // space as it is typed.
      await authStore.updateProfile({
        name: formData.name.trim(),
        email: formData.email.trim(),
        avatar: pickedAvatar.value,
        removeAvatar: removeAvatar.value,
      });

      // Re-seed from the refreshed user so the form goes clean again.
      formData.name = currentUser.value.name;
      formData.email = currentUser.value.email;
      original.name = currentUser.value.name;
      original.email = currentUser.value.email;

      resetAvatarState();
      isEditing.value = false;
    } catch (error: any) {
      errorMessage.value = error.userMessage;
    } finally {
      isSubmitting.value = false;
    }
  }
</script>

<template>
  <v-container class="flex items-center justify-center min-h-full">
    <v-card
      class="py-8 px-6 flex flex-col justify-center"
      :disabled="isSubmitting"
      max-width="480"
      rounded="lg"
      width="100%"
    >
      <v-card-title class="text-center pt-0 pb-8 font-bold text-3xl text-wrap">
        {{ t("profile.title") }}
      </v-card-title>

      <v-alert v-if="errorMessage" class="mb-4" type="error">{{
        errorMessage
      }}</v-alert>

      <v-card-text>
        <v-form
          ref="formRef"
          v-model="formValid"
          class="flex flex-col gap-6"
          @submit.prevent="confirmEditing"
        >
          <AvatarPicker
            v-model="pickedAvatar"
            :editable="isEditing"
            :initial-src="avatarSrc"
            :removable="currentUser.has_avatar && !removeAvatar"
            @remove="removeAvatar = true"
          />

          <v-text-field
            v-model="formData.name"
            :disabled="!isEditing"
            :label="t('auth.name')"
            :rules="nameRules"
          />

          <v-text-field
            v-model="formData.email"
            :disabled="!isEditing"
            :label="t('auth.email')"
            :rules="emailRules"
            type="email"
          />

          <v-btn
            v-if="!isEditing"
            block
            class="py-6"
            color="primary"
            @click="startEditing"
          >
            {{ t("common.edit") }}
          </v-btn>

          <div v-else class="flex gap-4">
            <v-btn
              class="py-6 flex-1"
              variant="outlined"
              @click="cancelEditing"
            >
              {{ t("common.cancel") }}
            </v-btn>

            <v-btn
              class="py-6 flex-1"
              color="primary"
              :disabled="!canSave"
              :loading="isSubmitting"
              type="submit"
            >
              {{ t("profile.confirm") }}
            </v-btn>
          </div>
        </v-form>
      </v-card-text>
    </v-card>
  </v-container>
</template>

<route lang="json">
{
  "name": "profile"
}
</route>
