<script setup lang="ts">
  import type { TeamRole } from "@/types/team";
  import type { User } from "@/types/user";
  import { computed } from "vue";
  import { useI18n } from "vue-i18n";
  import { useEmailFormat } from "@/composables/useEmailFormat";

  /**
   * The member row list, shared by the manage-members dialog and the create
   * dialog's pending picks — the two are the same thing, one persisted and one
   * not. Removal only ever *emits*: the members dialog confirms first, the
   * create dialog just drops the pick.
   *
   * Scrolls inside itself rather than growing the dialog it sits in.
   */
  const props = withDefaults(
    defineProps<{
      users: User[];
      /** The role to show per row — held server-side in one case, locally in the other. */
      roleOf: (user: User) => TeamRole;
      /** Row whose role is mid-flight, so only that select spins. */
      busyId?: number | null;
      removeIcon?: string;
      removeTitle?: string;
      /** Annotate anyone who currently belongs to another team. */
      hint?: boolean;
      /** The team being edited, so its own members are not annotated. */
      currentTeamId?: number | null;
    }>(),
    {
      busyId: null,
      removeIcon: "mdi-account-remove",
      removeTitle: undefined,
      hint: false,
      currentTeamId: null,
    },
  );

  defineEmits<{
    "update:role": [user: User, role: TeamRole];
    remove: [user: User];
  }>();

  const { t } = useI18n();
  const { truncateEmail } = useEmailFormat();

  // Computed, not a plain array: t() would otherwise be evaluated once at setup
  // and the labels would keep the locale that was active then.
  const roleOptions = computed(() => [
    { title: t("admin.users.roles.member"), value: "member" },
    { title: t("admin.users.roles.admin"), value: "admin" },
  ]);

  /**
   * Only about *other* teams: someone already in the team being edited would
   * otherwise be annotated with the team you are looking at.
   */
  function otherTeam(user: User) {
    if (!props.hint || !user.team || user.team.id === props.currentTeamId) {
      return null;
    }

    return t("admin.teams.currentTeamHint", { team: user.team.name });
  }
</script>

<template>
  <v-list bg-color="surface-darken-2 rounded-2xl" lines="two">
    <v-list-item v-for="user in users" :key="user.id">
      <template #prepend>
        <!-- Same treatment as the nav bar's profile card: a nested v-img so the
             picture is cropped rather than stretched. -->
        <v-avatar size="40">
          <v-img :alt="user.name" cover :src="user.avatar_thumb_url" />
        </v-avatar>
      </template>

      <v-list-item-title>
        <span class="truncate" :title="user.name">
          {{ user.name }}
        </span>
      </v-list-item-title>

      <v-list-item-subtitle>
        <!-- Truncated toward the domain, the more identifying half — the plain
             CSS ellipsis would eat it instead. -->
        <span :title="user.email">{{ truncateEmail(user.email, 28) }}</span>

        <span v-if="otherTeam(user)" class="text-warning">
          — {{ otherTeam(user) }}
        </span>
      </v-list-item-subtitle>

      <template #append>
        <div class="flex items-center gap-2">
          <v-select
            class="w-36"
            density="compact"
            hide-details
            icon-color="tertiary"
            :items="roleOptions"
            :loading="busyId === user.id"
            :model-value="roleOf(user)"
            variant="solo"
            @update:model-value="$emit('update:role', user, $event as TeamRole)"
          />

          <v-btn
            color="error"
            :icon="removeIcon"
            size="small"
            :title="removeTitle ?? t('common.delete')"
            variant="text"
            @click="$emit('remove', user)"
          />
        </div>
      </template>
    </v-list-item>
  </v-list>
</template>
