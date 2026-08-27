<script setup lang="ts">
  import type { Team } from "@/types/team";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDocumentValidationRules } from "@/composables/useDocumentValidationRules";
  import { useTeamStore } from "@/stores/team";

  // Models only, mirroring TeamFields: the read-only metadata (id, creator,
  // timestamps) belongs to the dialog that owns the document, not here.
  const props = defineProps<{
    /**
     * A server-side rejection for the title — today, the per-team collision,
     * which the browser cannot check for itself. Vuetify composes this with
     * `rules`, so the local required/max checks still run.
     */
    titleError?: string;
    /**
     * Drop the team select entirely — for callers that already know the team
     * and submit it themselves (the /documents page, where a document is filed
     * under the caller's own team and cannot be moved).
     */
    hideTeam?: boolean;
  }>();

  const title = defineModel<string>("title", { default: "" });
  const description = defineModel<string>("description", { default: "" });
  const teamId = defineModel<number | null>("teamId", { default: null });

  const { t } = useI18n();
  const { titleRules, descriptionRules, teamRules } =
    useDocumentValidationRules();
  const teamStore = useTeamStore();

  const teams = ref<Team[]>([]);

  // Computed, not a plain array: t() would otherwise be evaluated once at setup
  // and the label would keep the locale that was active then.
  //
  // Unlike UserFields' team select there is no "no team" entry — a user can be
  // taken out of their team, but a document must belong to one.
  const teamOptions = computed(() =>
    teams.value.map((team) => ({ title: team.name, value: team.id })),
  );

  /**
   * Pin the team menu above the field.
   *
   * The field sits near the bottom of the dialog, and Vuetify's connected
   * strategy flips whenever the other side fits better — with no prop to
   * disable that. Same fix as UserFields: ask for the roomy side and cap the
   * height low enough that it always fits there and never flips back.
   */
  const teamMenuProps = { location: "top", maxHeight: 300 } as const;

  onMounted(async () => {
    // A hidden field must not cost a request.
    if (props.hideTeam) return;

    // Defaulted rather than assigned blind: a failed or stubbed fetch would
    // otherwise leave the list undefined and break teamOptions.
    teams.value = (await teamStore.fetchPickerTeams()) ?? [];
  });
</script>

<template>
  <div class="flex flex-col gap-6">
    <v-text-field
      v-model="title"
      class="[&_input]:truncate"
      dir="auto"
      :error-messages="titleError"
      :label="t('admin.documents.documentTitle')"
      :rules="titleRules"
    />

    <!-- Optional: the column is nullable, and the store omits an empty string
         rather than sending "". -->
    <v-textarea
      v-model="description"
      auto-grow
      color="tertiary"
      dir="auto"
      :label="t('admin.documents.description')"
      rows="2"
      :rules="descriptionRules"
      variant="outlined"
    />

    <!-- Autocomplete rather than a plain select: the team list grows with the
         app, so it needs to be searchable. Single-select — a document belongs
         to exactly one team — so no `multiple`, and no :search binding either:
         the list loads once and Vuetify filters it client-side. -->
    <v-autocomplete
      v-if="!hideTeam"
      v-model="teamId"
      density="comfortable"
      :items="teamOptions"
      :label="t('admin.documents.team')"
      :menu-props="teamMenuProps"
      :rules="teamRules"
      variant="outlined"
    />
  </div>
</template>
