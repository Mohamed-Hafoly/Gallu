<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { reactive, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
  import { useAuthStore } from "@/stores/auth";

  interface RegisterPayload {
    email: string;
    password: string;
  }

  const { t } = useI18n();
  const { emailRules, passwordRules } = useAuthValidationRules();
  const isSubmitting = ref(false);
  const authStore = useAuthStore();

  const formData = reactive<RegisterPayload>({
    email: "",
    password: "",
  });

  const formRef = ref<VForm | null>(null);

  const errorMessage = ref("");

  async function login() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    isSubmitting.value = true;
    errorMessage.value = "";

    try {
      await authStore.login(formData);
    } catch (error: any) {
      errorMessage.value = error.userMessage;
    } finally {
      isSubmitting.value = false;
    }
  }
</script>

<template>
  <v-container class="flex items-center justify-center h-full">
    <v-card
      class="py-8 px-6 flex flex-col justify-center"
      :disabled="isSubmitting"
      max-width="480"
      rounded="lg"
      width="100%"
    >
      <v-card-title class="text-center pt-0 pb-8 font-bold text-3xl">{{
        t("auth.login")
      }}</v-card-title>

      <v-alert v-if="errorMessage" class="mb-4" type="error">{{
        errorMessage
      }}</v-alert>

      <v-card-text>
        <v-form
          ref="formRef"
          class="flex flex-col gap-6"
          @submit.prevent="login"
        >
          <v-text-field
            v-model="formData.email"
            :label="t('auth.email')"
            :rules="emailRules"
            type="email"
          />

          <v-text-field
            v-model="formData.password"
            :label="t('auth.password')"
            :rules="passwordRules"
            type="password"
          />

          <v-btn
            active-color="text-tertiary"
            block
            class="py-6"
            color="primary"
            :loading="isSubmitting ? 'tertiary' : false"
            type="submit"
            >{{ t("auth.login") }}</v-btn
          >

          <p class="text-center text-lg mt-6">
            {{ t("auth.loginToRegister") }}
            <v-btn
              class="underline underline-offset-2 px-1"
              color="tertiary"
              density="compact"
              slim
              :to="{ name: 'register' }"
              variant="text"
            >
              {{ t("auth.registerHere") }}
            </v-btn>
          </p>
        </v-form>
      </v-card-text>
    </v-card>
  </v-container>
</template>

<route lang="json">
{
  "name": "login"
}
</route>
