<script setup lang="ts">
  import type { TeamMemberSelection, TeamRole } from "@/types/team";
  import type { User } from "@/types/user";
  import { computed, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useUserStore } from "@/stores/user";

  /**
   * Search for users and hold the ones picked, with a role each.
   *
   * Shared by the create dialog and the manage-members dialog: both edit a
   * membership locally and write it in one go, so neither needs this to touch
   * the API beyond searching. The selection is the model.
   */
  const selection = defineModel<TeamMemberSelection[]>({
    default: () => [],
  });

  const props = defineProps<{
    /**
     * The team being edited, so its own members are not annotated as belonging
     * elsewhere. Absent on create, where the team does not exist yet.
     */
    teamId?: number | null;
  }>();

  const { t } = useI18n();
  const userStore = useUserStore();

  /**
   * Below this, no request is made at all — so the menu must not claim a search
   * came back empty. Read by both the guard and the empty-state copy, so the
   * two cannot drift.
   */
  const MIN_SEARCH_LENGTH = 2;

  const candidates = ref<User[]>([]);
  // Bound rather than left to the autocomplete's internal state, which survives
  // a close and would otherwise show the previous query on reopen.
  const candidateSearch = ref("");
  const searching = ref(false);
  const pickedUserId = ref<number | null>(null);

  const pickedIds = computed(
    () => new Set(selection.value.map((entry) => entry.user.id)),
  );

  /**
   * Users the search turned up who could actually join: super-admins sit above
   * teams and the backend refuses them, and anyone already picked would be a
   * duplicate. Someone in *another* team stays on the list — saving moves them,
   * which is what the inline hint says.
   */
  const candidateItems = computed(() =>
    candidates.value
      .filter((user) => !user.is_super_admin && !pickedIds.value.has(user.id))
      .map((user) => ({
        title: user.name,
        subtitle: user.team
          ? `${user.email} — ${t("admin.teams.currentTeamHint", { team: user.team.name })}`
          : user.email,
        value: user.id,
      })),
  );

  const pickedUsers = computed(() =>
    selection.value.map((entry) => entry.user),
  );

  /**
   * What the empty menu says, which has to match what actually happened.
   *
   * `no-filter` means the menu lists exactly `candidateItems`, so it is empty
   * before anything is searched — and claiming "no matching users" there would
   * report a failure for a search that never ran.
   */
  const noDataText = computed(() => {
    if (candidateSearch.value.trim().length < MIN_SEARCH_LENGTH) {
      return t("admin.teams.searchHint", { count: MIN_SEARCH_LENGTH });
    }

    // The loading bar is already animating; without this the text under it
    // would contradict it for the length of the request.
    if (searching.value) return t("admin.teams.searching");

    return t("admin.teams.noCandidates");
  });

  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  watch(candidateSearch, (term) => onSearch(term ?? ""));

  /**
   * Reuses the admin users listing rather than a candidates endpoint of its own
   * — it already searches name and email server-side under the same super-admin
   * gate, and its rows carry the team each user is currently in.
   */
  function onSearch(term: string) {
    clearTimeout(searchTimer);

    if (term.trim().length < MIN_SEARCH_LENGTH) {
      candidates.value = [];
      return;
    }

    searchTimer = setTimeout(async () => {
      searching.value = true;
      try {
        const page = await userStore.fetchUsers({
          page: 1,
          per_page: 20,
          search: term.trim(),
        });
        candidates.value = page.items;
      } catch {
        candidates.value = [];
      } finally {
        searching.value = false;
      }
    }, 300);
  }

  // The user is captured here, synchronously, rather than derived from
  // `candidates` in a computed. Vuetify resets its search on selection, which
  // empties `candidates` a tick later — a derived pick would evaporate with it,
  // leaving the caller with an id it can no longer resolve.
  watch(pickedUserId, (id) => {
    if (id === null) return;

    const user = candidates.value.find((candidate) => candidate.id === id);
    if (user && !pickedIds.value.has(user.id)) {
      selection.value = [...selection.value, { user, role: "member" }];
    }

    // Cleared so the same box is ready for the next pick.
    pickedUserId.value = null;
    candidateSearch.value = "";
    candidates.value = [];
  });

  function unpick(user: User) {
    selection.value = selection.value.filter(
      (entry) => entry.user.id !== user.id,
    );
  }

  function setRole(user: User, role: TeamRole) {
    selection.value = selection.value.map((entry) =>
      entry.user.id === user.id ? { ...entry, role } : entry,
    );
  }

  function roleOf(user: User): TeamRole {
    return (
      selection.value.find((entry) => entry.user.id === user.id)?.role ??
      "member"
    );
  }

  /** Called by the dialogs when they close, so a reopen starts clean. */
  function resetSearch() {
    clearTimeout(searchTimer);
    pickedUserId.value = null;
    candidateSearch.value = "";
    candidates.value = [];
  }

  defineExpose({ resetSearch });
</script>

<template>
  <div class="flex flex-col gap-3">
    <v-autocomplete
      v-model="pickedUserId"
      v-model:search="candidateSearch"
      density="comfortable"
      hide-details
      icon-color="tertiary"
      item-props
      :items="candidateItems"
      :label="t('admin.teams.searchUsers')"
      :loading="searching"
      :no-data-text="noDataText"
      no-filter
      variant="outlined"
    />

    <!-- Unrendered until something is picked, rather than an empty box. -->
    <MemberRows
      v-if="selection.length > 0"
      :current-team-id="props.teamId"
      hint
      remove-icon="mdi-account-remove"
      :remove-title="t('admin.teams.removeMember')"
      :role-of="roleOf"
      :users="pickedUsers"
      @remove="unpick"
      @update:role="setRole"
    />
  </div>
</template>
