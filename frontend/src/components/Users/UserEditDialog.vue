<script setup lang="ts">
  import type { User } from "@/types/user";
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useAuthStore } from "@/stores/auth";
  import { useNotifierStore } from "@/stores/notifier";
  import { useUserStore } from "@/stores/user";

  const props = defineProps<{ user: User }>();

  const emit = defineEmits<{
    updated: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const { formatDateTime } = useDateFormat();
  const userStore = useUserStore();
  const authStore = useAuthStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({ name: "", email: "", isSuperAdmin: false });
  const original = reactive({ name: "", email: "", isSuperAdmin: false });

  // The backend refuses to let a super-admin change their own flag, so the
  // select is shown disabled rather than offered and rejected.
  const canChangeRole = computed(() => props.user.id !== authStore.user?.id);

  const pickedAvatar = ref<File | null>(null);
  const removeAvatar = ref(false);

  function resetAvatarState() {
    pickedAvatar.value = null;
    removeAvatar.value = false;
  }

  // Refill whenever a different user is opened.
  watch(
    () => props.user,
    (user) => {
      form.name = user.name;
      form.email = user.email;
      form.isSuperAdmin = user.is_super_admin;
      original.name = user.name;
      original.email = user.email;
      original.isSuperAdmin = user.is_super_admin;
      resetAvatarState();
      formRef.value?.resetValidation();
    },
    { immediate: true },
  );

  // The page keeps this dialog mounted and hands back the same user object each
  // time, so the watcher above would not fire on reopening the same row — an
  // abandoned edit would still be sitting in the form, with Save enabled.
  watch(open, (isOpen) => {
    if (isOpen) return;

    form.name = original.name;
    form.email = original.email;
    form.isSuperAdmin = original.isSuperAdmin;
    resetAvatarState();
    formRef.value?.resetValidation();
  });

  // While a removal is pending we preview the default image, since that is what
  // saving would leave the user with — same as the profile page.
  const avatarSrc = computed(() =>
    removeAvatar.value ? props.user.default_avatar_url : props.user.avatar_url,
  );

  // Compared trimmed, because the backend trims before storing — otherwise a
  // stray trailing space would enable Save for a no-op edit.
  const isDirty = computed(
    () =>
      form.name.trim() !== original.name.trim() ||
      form.email.trim() !== original.email.trim() ||
      (canChangeRole.value && form.isSuperAdmin !== original.isSuperAdmin) ||
      Boolean(pickedAvatar.value) ||
      removeAvatar.value,
  );

  const canSave = computed(() => formValid.value === true && isDirty.value);

  function close() {
    open.value = false;
  }

  async function submit() {
    const { valid } = await formRef.value!.validate();
    if (!valid || !isDirty.value) return;

    submitting.value = true;
    try {
      await userStore.updateUser(props.user.id, {
        name: form.name.trim(),
        email: form.email.trim(),
        avatar: pickedAvatar.value,
        removeAvatar: removeAvatar.value,
        // Omitted entirely on your own row, so the request cannot 403.
        isSuperAdmin: canChangeRole.value ? form.isSuperAdmin : undefined,
      });

      emit("updated");
      close();
    } catch {
      notifier.notify(t("admin.users.updateFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog v-model="open" :title="t('admin.users.editTitle')">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <AvatarPicker
          v-model="pickedAvatar"
          editable
          :initial-src="avatarSrc"
          :removable="user.has_avatar && !removeAvatar"
          @remove="removeAvatar = true"
        />

        <v-text-field
          disabled
          :label="t('admin.users.id')"
          :model-value="user.id"
        />

        <UserFields
          v-model:email="form.email"
          v-model:is-super-admin="form.isSuperAdmin"
          v-model:name="form.name"
          :role-disabled="!canChangeRole"
        />

        <v-text-field
          disabled
          :label="t('admin.users.createdAt')"
          :model-value="formatDateTime(user.created_at)"
        />

        <v-text-field
          disabled
          :label="t('admin.users.updatedAt')"
          :model-value="formatDateTime(user.updated_at)"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="submitting" @click="close">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          color="primary"
          :disabled="!canSave"
          :loading="submitting"
          prepend-icon="mdi-content-save-edit"
          type="submit"
          variant="elevated"
        >
          <template #loader>
            <v-progress-circular color="tertiary" indeterminate width="3" />
          </template>
          {{ t("common.save") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </FormDialog>
</template>
