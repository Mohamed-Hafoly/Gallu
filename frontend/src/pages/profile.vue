<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useValidationRules } from "@/composables/useValidationRules";
  import { useAuthStore } from "@/stores/auth";

  interface UpdatePayload {
    name: string;
    email: string;
  }

  const { t } = useI18n();
  const { nameRules, emailRules } = useValidationRules();
  const isSubmitting = ref(false);
  const isEditing = ref(false);
  const authStore = useAuthStore();

  const currentUser = computed(() => authStore.user!);

  const formData = reactive<UpdatePayload>({
    name: currentUser.value.name,
    email: currentUser.value.email,
  });

  const formRef = ref<VForm | null>(null);
  const errorMessage = ref("");

  function startEditing() {
    isEditing.value = true;
  }

  function cancelEditing() {
    formData.name = currentUser.value.name;
    formData.email = currentUser.value.email;

    errorMessage.value = "";
    isEditing.value = false;
  }

  async function confirmEditing() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    isSubmitting.value = true;
    errorMessage.value = "";

    try {
      await authStore.updateProfile({
        name: formData.name,
        email: formData.email,
      });

      isEditing.value = false;
    } catch (error: any) {
      errorMessage.value = error.response?.data?.message ?? "Update failed.";
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
      <v-card-title class="text-center pt-0 pb-8 font-bold text-3xl text-wrap">
        {{ t("profile.title") }}
      </v-card-title>

      <v-alert v-if="errorMessage" class="mb-4" type="error">{{
        errorMessage
      }}</v-alert>

      <v-card-text>
        <v-form
          ref="formRef"
          class="flex flex-col gap-6"
          @submit.prevent="confirmEditing"
        >
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
            {{ t("profile.edit") }}
          </v-btn>

          <div v-else class="flex gap-4">
            <v-btn
              class="py-6 flex-1"
              variant="outlined"
              @click="cancelEditing"
            >
              {{ t("profile.cancel") }}
            </v-btn>

            <v-btn
              class="py-6 flex-1"
              color="primary"
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
