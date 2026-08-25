<script setup lang="ts">
  import type { Document } from "@/types/document";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRoute } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentStore } from "@/stores/document";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatDateTime } = useDateFormat();
  const documentStore = useDocumentStore();
  const route = useRoute();

  // The route param is the single source of the document id: ImageGallery
  // fetches and uploads against it, so nothing here asks the user to pick one.
  const documentId = computed(() => Number(route.params.id));

  const document = ref<Document | null>(null);
  const loading = ref(true);
  const forbidden = ref(false);

  onMounted(async () => {
    try {
      document.value = await documentStore.fetchDocument(documentId.value);
    } catch {
      // A deep link to another team's document is a 403 from DocumentPolicy.
      // Say so rather than rendering a header-less page with an empty grid.
      forbidden.value = true;
    } finally {
      loading.value = false;
    }
  });
</script>

<template>
  <v-container class="pt-3 bg-surface-darken-3" fluid>
    <v-progress-linear v-if="loading" indeterminate />

    <p v-else-if="forbidden" class="mt-10 text-center text-error">
      {{ t("documents.forbidden") }}
    </p>

    <template v-else-if="document">
      <div class="mb-4" :class="isRtl ? 'text-right' : 'text-left'">
        <!--
          `exact` is load-bearing. Vue Router matches active routes inclusively,
          so /documents/13 counts as being inside /documents and this link would
          report isActive — Vuetify then lays its 24% black active overlay over
          the button, which reads as it being permanently dimmed.
        -->
        <v-btn
          class="mb-2"
          color="tertiary"
          exact
          :prepend-icon="isRtl ? 'mdi-arrow-right' : 'mdi-arrow-left'"
          size="small"
          :to="{ name: 'documents' }"
          variant="flat"
        >
          {{ t("documents.backToDocuments") }}
        </v-btn>

        <h1 class="text-2xl font-semibold" dir="auto">{{ document.title }}</h1>

        <p v-if="document.description" class="mt-1" dir="auto">
          {{ document.description }}
        </p>

        <div class="flex flex-row justify-between w-full">
          <p class="mt-1 text-sm opacity-70">
            {{ document.creator }}
          </p>

          <p class="mt-1 text-sm opacity-70">
            {{ formatDateTime(document.created_at) }}
          </p>
        </div>
      </div>

      <ImageGallery :document-id="documentId" />
    </template>
  </v-container>
</template>
