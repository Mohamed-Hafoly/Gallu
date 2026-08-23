<script setup lang="ts">
  import type { Team } from "@/types/team";
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useNotifierStore } from "@/stores/notifier";
  import { useTeamStore } from "@/stores/team";

  const props = defineProps<{ team: Team }>();

  const emit = defineEmits<{
    updated: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const teamStore = useTeamStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({ name: "", description: "" });
  const original = reactive({ name: "", description: "" });

  // Refill whenever a different team is opened.
  watch(
    () => props.team,
    (team) => {
      form.name = team.name;
      form.description = team.description ?? "";
      original.name = form.name;
      original.description = form.description;
      formRef.value?.resetValidation();
    },
    { immediate: true },
  );

  // The page keeps this dialog mounted and hands back the same team object each
  // time, so the watcher above would not fire on reopening the same row — an
  // abandoned edit would still be sitting in the form, with Save enabled.
  watch(open, (isOpen) => {
    if (isOpen) return;

    form.name = original.name;
    form.description = original.description;
    formRef.value?.resetValidation();
  });

  // Compared trimmed, because the backend trims before storing — otherwise a
  // stray trailing space would enable Save for a no-op edit.
  const isDirty = computed(
    () =>
      form.name.trim() !== original.name.trim() ||
      form.description.trim() !== original.description.trim(),
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
      await teamStore.updateTeam(props.team.id, {
        name: form.name.trim(),
        description: form.description.trim() || null,
      });

      emit("updated");
      close();
    } catch {
      notifier.notify(t("admin.teams.updateFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog v-model="open" :title="t('admin.teams.editTitle')">
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <v-row dense>
          <v-text-field
            disabled
            :label="t('admin.teams.id')"
            :model-value="team.id"
          />

          <!-- The team's own creator, not the signed-in user: the column nulls
               when that account is deleted, hence the fallback. -->
          <v-text-field
            disabled
            :label="t('admin.teams.creator')"
            :model-value="team.creator ?? t('common.emptyValue')"
          />
        </v-row>

        <TimestampFields
          :created="team.created_at"
          :updated="team.updated_at"
        />

        <TeamFields
          v-model:description="form.description"
          v-model:name="form.name"
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
          {{ t("common.save") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </FormDialog>
</template>
