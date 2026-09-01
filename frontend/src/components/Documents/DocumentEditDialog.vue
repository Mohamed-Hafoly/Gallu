<script setup lang="ts">
  import type { Document } from "@/types/document";
  import type { AxiosError } from "axios";
  import type { VForm } from "vuetify/components";
  import { computed, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDocumentStore } from "@/stores/document";
  import { useNotifierStore } from "@/stores/notifier";

  /**
   * `lockedTeamId` drops the team field and submits that id instead - the
   * /documents page, where editing may rename a document but never move it
   * between teams. /admin/documents omits it and keeps the picker, which is
   * where a super-admin moves documents.
   */
  const props = defineProps<{ document: Document; lockedTeamId?: number }>();

  const emit = defineEmits<{
    updated: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const documentStore = useDocumentStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);
  // Server-side only: the title must be unique within the team, which the
  // browser has no cheap way to know. Cleared as soon as the title is edited,
  // so the message never outlives the value it was about.
  const titleError = ref("");

  const form = reactive({
    title: "",
    description: "",
    teamId: null as number | null,
  });
  const original = reactive({
    title: "",
    description: "",
    teamId: null as number | null,
  });

  // Refill whenever a different document is opened.
  watch(
    () => props.document,
    (document_) => {
      form.title = document_.title;
      form.description = document_.description ?? "";
      // Null only when the team is soft-deleted and this payload did not widen
      // the relation; the team rule then keeps Save disabled until one is
      // chosen.
      form.teamId = document_.team?.id ?? null;
      original.title = form.title;
      original.description = form.description;
      original.teamId = form.teamId;
      titleError.value = "";
      formRef.value?.resetValidation();
    },
    { immediate: true },
  );

  // The page keeps this dialog mounted and hands back the same document object
  // each time, so the watcher above would not fire on reopening the same row —
  // an abandoned edit would still be sitting in the form, with Save enabled.
  watch(open, (isOpen) => {
    if (isOpen) return;

    form.title = original.title;
    form.description = original.description;
    form.teamId = original.teamId;
    titleError.value = "";
    formRef.value?.resetValidation();
  });

  // The text fields are compared trimmed, because the backend trims before
  // storing — otherwise a stray trailing space would enable Save for a no-op
  // edit. The team is an id, so it compares as-is.
  const isDirty = computed(
    () =>
      form.title.trim() !== original.title.trim() ||
      form.description.trim() !== original.description.trim() ||
      form.teamId !== original.teamId,
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
      await documentStore.updateDocument(props.document.id, {
        title: form.title.trim(),
        description: form.description.trim() || undefined,
        // Non-null by the time we are here: either the caller locked a team, or
        // teamRules blocked submit until one was picked.
        team_id: props.lockedTeamId ?? form.teamId!,
      });

      emit("updated");
      close();
    } catch (error) {
      titleError.value = (error as AxiosError).fieldErrors?.title?.[0] ?? "";
      notifier.notify(t("admin.documents.updateFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog
    v-model="open"
    max-width="700"
    :title="t('admin.documents.editTitle')"
  >
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <!-- Bare fields in a dense row, no v-col wrappers — the same way the
             team, category and user dialogs lay their metadata out. -->
        <v-row dense>
          <v-text-field
            disabled
            :label="t('admin.documents.id')"
            :model-value="document.id"
          />

          <!-- No empty-value fallback, unlike the team and category dialogs:
               documents.user_id is NOT NULL and DocumentResource serves
               `creator` unconditionally. -->
          <v-text-field
            disabled
            :label="t('admin.documents.creator')"
            :model-value="document.creator ?? t('common.deletedUser')"
          />
        </v-row>

        <TimestampFields
          :created="document.created_at"
          :updated="document.updated_at"
        />

        <DocumentFields
          v-model:description="form.description"
          v-model:team-id="form.teamId"
          v-model:title="form.title"
          :hide-team="lockedTeamId !== undefined"
          :title-error="titleError"
          @update:title="titleError = ''"
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
