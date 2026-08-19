<script setup lang="ts">
  import { computed } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";

  defineProps<{
    /** The edit dialog disables this on your own row — the backend refuses a
     * self-promotion, so the control is shown but not offered. */
    roleDisabled?: boolean;
  }>();

  const name = defineModel<string>("name", { default: "" });
  const email = defineModel<string>("email", { default: "" });
  const isSuperAdmin = defineModel<boolean>("isSuperAdmin", { default: false });

  const { t } = useI18n();
  const { nameRules, emailRules } = useAuthValidationRules();

  // Computed, not a plain array: t() would otherwise be evaluated once at setup
  // and the labels would keep the locale that was active then.
  //
  // Boolean values, matching the is_super_admin field both stores send.
  // RoleName::Admin ("Team admin") is deliberately absent — it is a per-team
  // role, and neither endpoint accepts anything but the global boolean.
  const roleOptions = computed(() => [
    { title: t("admin.users.roles.member"), value: false },
    { title: t("admin.users.roles.super-admin"), value: true },
  ]);
</script>

<template>
  <div class="flex flex-col gap-6">
    <v-text-field
      v-model="name"
      class="[&_input]:truncate"
      dir="auto"
      :label="t('auth.name')"
      :rules="nameRules"
    />

    <v-text-field
      v-model="email"
      class="[&_input]:truncate"
      dir="auto"
      :label="t('auth.email')"
      :rules="emailRules"
      :title="email"
      type="email"
    />

    <!-- The create dialog puts its password fields here; the edit dialog has
         nothing to add and simply leaves the slot empty. -->
    <slot name="after-email" />

    <v-select
      v-model="isSuperAdmin"
      density="comfortable"
      :disabled="roleDisabled"
      hide-details
      icon-color="tertiary"
      :items="roleOptions"
      :label="t('admin.users.role')"
    />
  </div>
</template>
