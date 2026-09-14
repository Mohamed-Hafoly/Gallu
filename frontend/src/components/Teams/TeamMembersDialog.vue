<script setup lang="ts">
  import type { Team, TeamMemberSelection, TeamRole } from "@/types/team";
  import type { User } from "@/types/user";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useNotifierStore } from "@/stores/notifier";
  import { useTeamStore } from "@/stores/team";

  const props = defineProps<{ team: Team }>();

  const emit = defineEmits<{
    /** Membership changed, so the page refetches and members_count catches up. */
    changed: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const teamStore = useTeamStore();
  const notifier = useNotifierStore();

  const loading = ref(false);
  const saving = ref(false);

  // Additions, removals and role changes are all local until Save — the same
  // deal as the create dialog, which is why both drive the one picker.
  const selection = ref<TeamMemberSelection[]>([]);
  const original = ref<TeamMemberSelection[]>([]);
  const pickerRef = ref<{ resetSearch: () => void } | null>(null);

  /**
   * An order-independent fingerprint of a membership, so shuffling rows is not
   * a change but a role edit is.
   */
  function fingerprint(entries: TeamMemberSelection[]) {
    return (
      entries
        .map((entry) => `${entry.user.id}:${entry.role}`)
        .toSorted()
        .join("|")
    );
  }

  const isDirty = computed(
    () => fingerprint(selection.value) !== fingerprint(original.value),
  );

  const canSave = computed(() => isDirty.value && !loading.value);

  function seed(members: User[]) {
    const entries = members.map((user) => ({
      user,
      // A member's `role` is their role in this team, since that is the only
      // team they can be in.
      role: user.role as TeamRole,
    }));

    selection.value = entries;
    // A separate array, not the same reference — otherwise every local edit
    // would move the baseline with it and nothing would ever read as dirty.
    original.value = [...entries];
  }

  async function load() {
    loading.value = true;
    try {
      seed(await teamStore.fetchMembers(props.team.id));
    } catch {
      notifier.notify(t("admin.teams.membersLoadFailed"), "error");
    } finally {
      loading.value = false;
    }
  }

  async function save() {
    if (!isDirty.value) return;

    saving.value = true;
    try {
      // One request carrying the whole desired membership, so a half-applied
      // edit is not possible.
      seed(
        await teamStore.syncMembers(
          props.team.id,
          selection.value.map((entry) => ({
            userId: entry.user.id,
            role: entry.role,
          })),
        ),
      );

      emit("changed");
      notifier.notify(t("admin.teams.membersSaved"));
      open.value = false;
    } catch {
      notifier.notify(t("admin.teams.membersSaveFailed"), "error");
    } finally {
      saving.value = false;
    }
  }

  // Loading hangs off `open` rather than onMounted, because the page keeps this
  // dialog mounted across openings. Immediate matters: the page sets `managing`
  // and `membersOpen` in the same tick, so the very first render already has
  // open true and a lazy watcher would never fire for it.
  //
  // Closing discards an abandoned edit, the way TeamEditDialog does — the same
  // instance is handed back on reopen, so the edit would otherwise still be
  // sitting there with Save enabled.
  watch(
    open,
    (isOpen) => {
      if (isOpen) {
        load();
        return;
      }

      selection.value = [...original.value];
      pickerRef.value?.resetSearch();
    },
    { immediate: true },
  );
</script>

<template>
  <FormDialog
    v-model="open"
    max-width="700"
    :title="t('admin.teams.membersTitle', { name: team.name })"
  >
    <v-card-text class="flex flex-col gap-4 ">
      <v-progress-linear v-if="loading" indeterminate />

      <MemberPicker
        v-else
        ref="pickerRef"
        v-model="selection"
        :team-id="team.id"
      />
    </v-card-text>

    <v-card-actions>
      <v-spacer />

      <v-btn :disabled="saving" @click="open = false">
        {{ t("common.cancel") }}
      </v-btn>

      <v-btn
        color="primary"
        :disabled="!canSave"
        :loading="saving"
        prepend-icon="mdi-content-save-edit"
        variant="elevated"
        @click="save"
      >
        {{ t("common.save") }}
      </v-btn>
    </v-card-actions>
  </FormDialog>
</template>
