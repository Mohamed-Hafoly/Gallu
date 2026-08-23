<script setup lang="ts">
  import type { TeamMemberSelection } from "@/types/team";
  import type { VForm } from "vuetify/components";
  import { nextTick, reactive, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useAuthStore } from "@/stores/auth";
  import { useNotifierStore } from "@/stores/notifier";
  import { useTeamStore } from "@/stores/team";

  const emit = defineEmits<{
    created: [];
  }>();

  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
  const teamStore = useTeamStore();
  const authStore = useAuthStore();
  const notifier = useNotifierStore();

  const formRef = ref<VForm | null>(null);
  const formValid = ref<boolean | null>(null);
  const submitting = ref(false);

  const form = reactive({ name: "", description: "" });

  // Nothing is written until Create, so the picks are plain local state — the
  // picker owns the searching and the roles.
  const picked = ref<TeamMemberSelection[]>([]);
  const pickerRef = ref<{ resetSearch: () => void } | null>(null);

  // Start fresh next time, whether closed via Cancel or Create. No isDirty here
  // as there is on the edit dialog — every field starts empty, so validity alone
  // decides whether Create is offered.
  watch(open, async (isOpen) => {
    if (isOpen) return;

    form.name = "";
    form.description = "";
    picked.value = [];
    pickerRef.value?.resetSearch();

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
      await teamStore.createTeam({
        // Trimmed at submit rather than with v-model.trim, which strips the
        // space as it is typed. An empty description is null, not "".
        name: form.name.trim(),
        description: form.description.trim() || null,
        // Omitted by the store when empty, keeping the rule `sometimes`.
        members: picked.value.map((entry) => ({
          userId: entry.user.id,
          role: entry.role,
        })),
      });

      emit("created");
      close();
    } catch {
      notifier.notify(t("admin.teams.createFailed"), "error");
    } finally {
      submitting.value = false;
    }
  }
</script>

<template>
  <FormDialog
    v-model="open"
    max-width="700"
    :title="t('admin.teams.createTitle')"
  >
    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-card-text class="flex flex-col gap-6">
        <!-- The backend takes the creator from the session, so this previews
             what it will store rather than offering a choice. Same as the
             category create dialog. -->
        <v-text-field
          disabled
          :label="t('admin.teams.creator')"
          :model-value="authStore.user?.name"
        />

        <TeamFields
          v-model:description="form.description"
          v-model:name="form.name"
        />

        <v-divider />

        <div class="flex flex-col gap-3">
          <p class="text-sm opacity-70">
            {{ t("admin.teams.addMembersTitle") }}
          </p>

          <MemberPicker ref="pickerRef" v-model="picked" />
        </div>
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
