<script setup lang="ts">
  import { useI18n } from "vue-i18n";

  defineProps<{
    message: string;
    loading?: boolean;
    confirmLabel: string;
    confirmColor?: string;
    confirmIcon?: string;
  }>();
  const emit = defineEmits<{ confirm: [] }>();
  const open = defineModel<boolean>({ default: false });

  const { t } = useI18n();
</script>

<template>
  <v-dialog v-model="open" max-width="400">
    <v-card class="border-2 border-tertiary" rounded="md">
      <v-card-text>{{ message }}</v-card-text>

      <v-card-actions>
        <v-spacer />

        <v-btn :disabled="loading" @click="open = false">
          {{ t("common.cancel") }}
        </v-btn>

        <v-btn
          :color="confirmColor ?? 'error'"
          :loading="loading"
          :prepend-icon="confirmIcon"
          variant="elevated"
          @click="emit('confirm')"
        >
          {{ confirmLabel }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
