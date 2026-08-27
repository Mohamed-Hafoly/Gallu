<script setup lang="ts">
  import type { Document } from "@/types/document";
  import { computed, onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRoute, useRouter } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentPermissions } from "@/composables/useDocumentPermissions";
  import { useDocumentStore } from "@/stores/document";
  import { useNotifierStore } from "@/stores/notifier";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatDateTime } = useDateFormat();
  const { canEdit, canDelete } = useDocumentPermissions();
  const documentStore = useDocumentStore();
  const notifier = useNotifierStore();
  const route = useRoute();
  const router = useRouter();

  // The route param is the single source of the document id: ImageGallery
  // fetches and uploads against it, so nothing here asks the user to pick one.
  const documentId = computed(() => Number(route.params.id));

  const document = ref<Document | null>(null);
  const loading = ref(true);
  const forbidden = ref(false);
  // No separate `editing` ref, unlike the documents list: there is exactly one
  // document on this page and it is already held above.
  /** Only for openCreate() — the upload button lives up in the header row. */
  const gallery = ref<{ openCreate: () => void } | null>(null);

  const editOpen = ref(false);
  const deleteOpen = ref(false);
  const deleting = ref(false);

  async function load() {
    document.value = await documentStore.fetchDocument(documentId.value);
  }

  /**
   * Refetched rather than patched in place: reassigning gives the dialog's
   * `watch(() => props.document)` a new object identity, so its form refills
   * from what was actually stored — mutating the existing object would not fire
   * it. Success is announced here and failure inside the dialog, the same split
   * the documents list uses.
   */
  async function onUpdated() {
    await load();
    notifier.notify(t("documents.updated"));
  }

  /**
   * A soft delete, which Document::booted() cascades to the document's live
   * images — hence the confirmation naming them. Both come back together on a
   * restore from /admin/documents.
   */
  async function destroy() {
    deleting.value = true;
    try {
      await documentStore.deleteDocument(documentId.value);
      notifier.notify(t("admin.documents.deleted"));
      // replace, not push: this page's document no longer exists, so leaving it
      // in history would put a 403 one Back press away.
      router.replace({ name: "documents" });
    } catch {
      notifier.notify(t("admin.documents.deleteFailed"), "error");
      deleteOpen.value = false;
    } finally {
      deleting.value = false;
    }
  }

  onMounted(async () => {
    try {
      await load();
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
      <div class="flex flex-col gap-3 mb-6" :class="isRtl ? 'text-right' : 'text-left'">
        <!--
          `exact` is load-bearing. Vue Router matches active routes inclusively,
          so /documents/13 counts as being inside /documents and this link would
          report isActive — Vuetify then lays its 24% black active overlay over
          the button, which reads as it being permanently dimmed.
        -->
        <div class="mb-2 flex items-center justify-between gap-4">
          <v-btn
            color="tertiary"
            exact
            :prepend-icon="isRtl ? 'mdi-arrow-right' : 'mdi-arrow-left'"
            size="small"
            :to="{ name: 'documents' }"
            variant="flat"
          >
            {{ t("documents.backToDocuments") }}
          </v-btn>

          <!--
            variant="flat" to match the button they share the row with, rather
            than the variant="text" the documents list uses on its cards — same
            actions, different surroundings. No .stop is needed here: unlike a
            card, nothing wraps these in a click handler.
          -->
          <div class="flex items-center gap-3">
            <!--
              No permission gate, unlike its neighbours: ImagePolicy::create is
              "member of the document's team", and the document is only visible
              to that team at all, so anyone reading this page may upload.
            -->
            <v-btn
              color="tertiary"
              icon="mdi-image-plus"
              size="x-small"
              :title="t('gallery.upload')"
              variant="flat"
              @click="gallery?.openCreate()"
            />

            <v-btn
              v-if="canEdit(document)"
              color="primary"
              icon="mdi-pencil"
              size="x-small"
              :title="t('documents.edit')"
              variant="flat"
              @click="editOpen = true"
            />

            <v-btn
              v-if="canDelete(document)"
              color="error"
              icon="mdi-delete"
              size="x-small"
              :title="t('documents.delete')"
              variant="flat"
              @click="deleteOpen = true"
            />
          </div>
        </div>

        <h1 class="text-2xl font-semibold" dir="auto">{{ document.title }}</h1>

        <p v-if="document.description" class="mt-1 text-sm" dir="auto">
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

      <!--
        Reached for exactly one thing: opening its create dialog from the
        header. The dialog and the reload it triggers stay inside the gallery.
      -->
      <ImageGallery ref="gallery" :document-id="documentId" />

      <!--
        No v-if of its own: this whole branch is already `v-else-if="document"`,
        which is what satisfies the dialog's non-null `document` prop. The team
        is locked to the document's own, so editing here can rename but never
        move it between teams — that stays an /admin/documents action.
      -->
      <DocumentEditDialog
        v-model="editOpen"
        :document="document"
        :locked-team-id="document.team?.id"
        @updated="onUpdated"
      />

      <!--
        Reuses the admin screen's wording, which already says the images go too
        and that both can be restored — the same sentence, not a second copy of
        it to keep in step.
      -->
      <ConfirmDialog
        v-model="deleteOpen"
        confirm-icon="mdi-delete"
        :confirm-label="t('common.delete')"
        :loading="deleting"
        :message="
          t('admin.documents.deleteConfirm', { title: document.title })
        "
        @confirm="destroy"
      />
    </template>
  </v-container>
</template>
