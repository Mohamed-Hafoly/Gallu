<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { nextTick, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
  import { useNotifierStore } from "@/stores/notifier";
  import { useUserStore } from "@/stores/user";

  const emit = defineEmits<{
    created: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const { passwordRules, passwordConfirmationRules } = useAuthValidationRules();
  const userStore = useUserStore();
  const notifier = useNotifierStore();

  // Mirrors User::DEFAULT_AVATAR_PATH — served straight from backend/public,
  // so it resolves the same way register.vue's does. There is no user yet to
  // read default_avatar_url from.
  const DEFAULT_AVATAR_URL = `${import.meta.env.VITE_API_BASE_URL}/images/default-avatar.jpg`;

  const pickedAvatar = ref<File | null>(null);

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({
    name: "",
    email: "",
    password: "",
    passwordConfirmation: "",
    isSuperAdmin: false,
  });

  // Start fresh next time, whether closed via Cancel or Create. No isDirty here
  // as there is on the edit dialog — every field starts empty, so validity alone
  // decides whether Create is offered.
  watch(open, async (isOpen) => {
    if (isOpen) return;

    form.name = "";
    form.email = "";
    form.password = "";
    form.passwordConfirmation = "";
    form.isSuperAdmin = false;
    pickedAvatar.value = null;

    // After the tick, not before: emptying the fields re-runs their rules, so a
    // reset on the same tick is immediately undone and the next open greets you
    // with "this field is required" on a form you have not touched.
    await nextTick();
    formRef.value?.resetValidation();
  });

  function close() {
    open.value = false;
  }

  async function submit() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    submitting.value = true;
    try {
      await userStore.createUser({
        // Trimmed at submit rather than with v-model.trim, which strips the
        // space as it is typed. Passwords are left untouched — " hunter2 " is
        // ten characters to the backend.
        name: form.name.trim(),
        email: form.email.trim(),
        password: form.password,
        passwordConfirmation: form.passwordConfirmation,
        isSuperAdmin: form.isSuperAdmin,
        avatar: pickedAvatar.value,
      });

      emit("created");
      close();
    } catch {
      notifier.notify(t("admin.users.createFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog v-model="open" :title="t('admin.users.createTitle')">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <!-- Optional, as on registration: leaving it alone means the new user
             falls back to the shipped default image. -->
        <AvatarPicker
          v-model="pickedAvatar"
          editable
          :initial-src="DEFAULT_AVATAR_URL"
        />

        <UserFields
          v-model:email="form.email"
          v-model:is-super-admin="form.isSuperAdmin"
          v-model:name="form.name"
        >
          <!-- Create-only, and slotted so it lands between email and role
               rather than after the whole shared block. -->
          <template #after-email>
            <v-text-field
              v-model="form.password"
              :hint="t('auth.passwordHint')"
              :label="t('auth.password')"
              :rules="passwordRules"
              type="password"
            />

            <v-text-field
              v-model="form.passwordConfirmation"
              :label="t('auth.confirmPassword')"
              :rules="passwordConfirmationRules(() => form.password)"
              type="password"
            />
          </template>
        </UserFields>
      </v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="submitting" @click="close">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          color="primary"
          :disabled="formValid !== true"
          :loading="submitting"
          prepend-icon="mdi-content-save"
          type="submit"
          variant="elevated"
        >
          <template #loader>
            <v-progress-circular color="tertiary" indeterminate width="3" />
          </template>
          {{ t("common.create") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </FormDialog>
</template>
