<script setup lang="ts">
  import type { Team } from "@/types/team";
  import type { User } from "@/types/user";
  import { computed, onMounted, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
  import { useTeamStore } from "@/stores/team";

  defineProps<{
    /** The edit dialog disables this on your own row — the backend refuses a
     * self-promotion, so the control is shown but not offered. */
    roleDisabled?: boolean;
  }>();

  const name = defineModel<string>("name", { default: "" });
  const email = defineModel<string>("email", { default: "" });
  const role = defineModel<User["role"]>("role", { default: "member" });
  const teamId = defineModel<number | null>("teamId", { default: null });

  const { t } = useI18n();
  const { nameRules, emailRules } = useAuthValidationRules();
  const teamStore = useTeamStore();

  const teams = ref<Team[]>([]);

  // Computed, not plain arrays: t() would otherwise be evaluated once at setup
  // and the labels would keep the locale that was active then.
  //
  // Three options, straight from the backend's RoleName. `super-admin` is the
  // global flag; `admin` and `member` are roles *within* a team, which is why
  // picking either only means anything alongside a team.
  const roleOptions = computed(() => [
    { title: t("admin.users.roles.member"), value: "member" },
    { title: t("admin.users.roles.admin"), value: "admin" },
    { title: t("admin.users.roles.super-admin"), value: "super-admin" },
  ]);

  // Null is offered explicitly so a user can be taken out of their team.
  const teamOptions = computed(() => [
    { title: t("admin.users.noTeam"), value: null },
    ...teams.value.map((team) => ({ title: team.name, value: team.id })),
  ]);

  /**
   * Pin the team menu above the field.
   *
   * The field sits near the bottom of the dialog — roughly 90px of viewport
   * below it against 550px above — so Vuetify's connected strategy opened
   * downward for a single result and flipped upward for two or more. It flips
   * whenever the other side fits better, and there is no prop to disable that,
   * so the fix is to ask for the roomy side and cap the height low enough that
   * it always fits there and never flips back.
   */
  const teamMenuProps = { location: "top", maxHeight: 300 } as const;

  // A super-admin sits above teams per req.txt, and the backend clears the team
  // when the flag is set — so the control follows rather than lying about it.
  const isSuperAdmin = computed(() => role.value === "super-admin");

  watch(isSuperAdmin, (superAdmin) => {
    if (superAdmin) teamId.value = null;
  });

  onMounted(async () => {
    // Defaulted rather than assigned blind: a failed or stubbed fetch would
    // otherwise leave the list undefined and break teamOptions.
    teams.value = (await teamStore.fetchPickerTeams()) ?? [];
  });
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

    <v-row dense>
      <!-- Autocomplete rather than a plain select: the team list grows with the
           app, so it needs to be searchable. -->
      <v-autocomplete
        v-model="teamId"
        density="comfortable"
        :disabled="roleDisabled || isSuperAdmin"
        hide-details
        :items="teamOptions"
        :label="t('admin.users.team')"
        :menu-props="teamMenuProps"
        variant="outlined"
      />

      <v-select
        v-model="role"
        density="comfortable"
        :disabled="roleDisabled"
        hide-details
        :items="roleOptions"
        :label="t('admin.users.role')"
      />
    </v-row>
  </div>
</template>
