<script setup lang="ts">
  import { useI18n } from "vue-i18n";
  import { useTeamValidationRules } from "@/composables/useTeamValidationRules";

  // Models only, mirroring UserFields: the read-only metadata (id, creator,
  // timestamps) belongs to the dialog that owns the team, not here.
  const name = defineModel<string>("name", { default: "" });
  const description = defineModel<string>("description", { default: "" });

  const { t } = useI18n();
  const { nameRules, descriptionRules } = useTeamValidationRules();
</script>

<template>
  <div class="flex flex-col gap-6">
    <v-text-field
      v-model="name"
      class="[&_input]:truncate"
      dir="auto"
      :label="t('admin.teams.name')"
      :rules="nameRules"
    />

    <!-- Optional: the column is nullable, and the store sends an empty string
         back as null. -->
    <v-textarea
      v-model="description"
      auto-grow
      color="tertiary"
      dir="auto"
      :label="t('admin.teams.description')"
      rows="2"
      :rules="descriptionRules"
      variant="outlined"
    />
  </div>
</template>
