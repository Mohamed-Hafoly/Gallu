<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
  import { useAuthStore } from "@/stores/auth";

  interface RegisterPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }

  const { t } = useI18n();
  const { nameRules, emailRules, passwordRules, passwordConfirmationRules } =
    useAuthValidationRules();
  const confirmRules = passwordConfirmationRules(() => formData.password);
  const isSubmitting = ref(false);
  const authStore = useAuthStore();

  // Mirrors User::DEFAULT_AVATAR_PATH — served straight from backend/public, so
  // it resolves without a session, unlike UserResource's default_avatar_url.
  const DEFAULT_AVATAR_URL = `${import.meta.env.VITE_API_BASE_URL}/images/default-avatar.jpg`;

  const selectedAvatar = ref<File | null>(null);

  const formData = reactive<RegisterPayload>({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });

  const formRef = ref<VForm | null>(null);

  const errorMessage = ref("");

  watch(
    () => formData.password,
    () => {
      if (formData.password_confirmation) formRef.value?.validate();
    },
  );

  async function register() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    isSubmitting.value = true;
    errorMessage.value = "";

    try {
      // Trimmed at submit rather than with v-model.trim, which strips the
      // space as it is typed. Both passwords are deliberately left untouched.
      await authStore.register({
        ...formData,
        name: formData.name.trim(),
        email: formData.email.trim(),
        avatar: selectedAvatar.value,
      });
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
      class="py-8 px-6 flex flex-col justify-center "
      :disabled="isSubmitting"
      max-width="480"
      rounded="lg"
      width="100%"
    >
      <v-card-title class="text-center pt-0 pb-8 font-bold text-3xl">
        {{ t("auth.register") }}</v-card-title
      >

      <v-alert v-if="errorMessage" class="mb-4" type="error">{{
        errorMessage
      }}</v-alert>

      <v-card-text>
        <v-form
          ref="formRef"
          class="flex flex-col gap-6"
          @submit.prevent="register"
        >
          <AvatarPicker
            v-model="selectedAvatar"
            editable
            :initial-src="DEFAULT_AVATAR_URL"
          />

          <v-text-field
            v-model="formData.name"
            :label="t('auth.name')"
            :rules="nameRules"
          />

          <v-text-field
            v-model="formData.email"
            :label="t('auth.email')"
            :rules="emailRules"
            type="email"
          />

          <v-text-field
            v-model="formData.password"
            :hint="t('auth.passwordHint')"
            :label="t('auth.password')"
            persistent-hint
            :rules="passwordRules"
            type="password"
          />

          <v-text-field
            v-model="formData.password_confirmation"
            :label="t('auth.confirmPassword')"
            :rules="confirmRules"
            type="password"
          />

          <v-btn
            active-color="text-tertiary"
            block
            class="py-6"
            color="primary"
            :loading="isSubmitting ? 'tertiary' : false"
            type="submit"
            >{{ t("auth.register") }}</v-btn
          >

          <p class="text-center text-base mt-6">
            {{ t("auth.registerToLogin") }}
            <v-btn
              class="underline underline-offset-2 px-1 text-base"
              color="tertiary"
              density="compact"
              slim
              :to="{ name: 'login' }"
              variant="text"
            >
              {{ t("auth.loginHere") }}
            </v-btn>
          </p>
        </v-form>
      </v-card-text>
    </v-card>
  </v-container>
</template>

<route lang="json">
{
  "name": "register"
}
</route>
