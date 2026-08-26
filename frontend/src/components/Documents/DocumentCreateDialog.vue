<script setup lang="ts">
  import type { VForm } from "vuetify/components";
  import { nextTick, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthStore } from "@/stores/auth";
  import { useDocumentStore } from "@/stores/document";
  import { useNotifierStore } from "@/stores/notifier";

  const emit = defineEmits<{
    created: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const documentStore = useDocumentStore();
  const authStore = useAuthStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({
    title: "",
    description: "",
    teamId: null as number | null,
  });

  // Start fresh next time, whether closed via Cancel or Create. No isDirty here
  // as there is on an edit dialog — every field starts empty, so validity alone
  // decides whether Create is offered.
  watch(open, async (isOpen) => {
    if (isOpen) return;

    form.title = "";
    form.description = "";
    form.teamId = null;

    // After the tick, not before: emptying the fields re-runs their rules, so a
    // reset on the same tick is immediately undone and the next open greets you
    // with "this field is required" on a form you have not touched.
    await nextTick();
    formRef.value?.resetValidation();
  });

  function close() {
    open.value = false;
  }

  async function submit() {
    const { valid } = await formRef.value!.validate();
    if (!valid) return;

    submitting.value = true;
    try {
      await documentStore.createDocument({
        // Trimmed at submit rather than with v-model.trim, which strips the
        // space as it is typed. An empty description is omitted, not "".
        title: form.title.trim(),
        description: form.description.trim() || undefined,
        // Non-null by the time we are here: teamRules blocks submit otherwise.
        team_id: form.teamId!,
      });

      emit("created");
      close();
    } catch {
      notifier.notify(t("admin.documents.createFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog
    v-model="open"
    max-width="700"
    :title="t('admin.documents.createTitle')"
  >
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <!-- The backend takes the creator from the session, so this previews
             what it will store rather than offering a choice. Same as the team
             and category create dialogs. -->
        <v-text-field
          disabled
          :label="t('admin.documents.creator')"
          :model-value="authStore.user?.name"
        />

        <DocumentFields
          v-model:description="form.description"
          v-model:team-id="form.teamId"
          v-model:title="form.title"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="submitting" @click="close">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          color="primary"
          :disabled="formValid !== true"
          :loading="submitting"
          prepend-icon="mdi-content-save"
          type="submit"
          variant="elevated"
        >
          {{ t("common.create") }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </FormDialog>
</template>
