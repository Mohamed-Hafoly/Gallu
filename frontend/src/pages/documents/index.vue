<script setup lang="ts">
  import type { Document } from "@/types/document";
  import { onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRouter } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentPermissions } from "@/composables/useDocumentPermissions";
  import { useDocumentStore } from "@/stores/document";
  import { useNotifierStore } from "@/stores/notifier";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatRelative } = useDateFormat();
  const { canCreate, canEdit, lockedTeamId } = useDocumentPermissions();
  const documentStore = useDocumentStore();
  const notifier = useNotifierStore();
  const router = useRouter();

  /**
   * The card's cover images. Defaulted because DocumentResource only returns
   * the key when the request asks for it — this page's fetchDocuments() sends
   * cover: 1, so it is always present here, but the type cannot know that.
   */
  const covers = (document_: Document) => document_.images ?? [];

  const documents = ref<Document[]>([]);
  const loading = ref(true);

  const createOpen = ref(false);
  // The document being edited is held apart from the dialog's open flag, as on
  // the admin page: the dialog keeps rendering through its close transition, so
  // clearing the document with the flag would blank the form on the way out.
  const editing = ref<Document | null>(null);
  const editOpen = ref(false);

  // Navigates rather than opening a dialog: a document's content is its images,
  // which need a page of their own.
  function open(document: Document) {
    router.push(`/documents/${document.id}`);
  }

  function openEdit(document: Document) {
    editing.value = document;
    editOpen.value = true;
  }

  async function load() {
    documents.value = await documentStore.fetchDocuments();
  }

  // Success is announced here, failure inside the dialog — the same split the
  // admin documents screen uses, so a dialog that stays open on error is the
  // one reporting why.
  async function onCreated() {
    await load();
    notifier.notify(t("documents.created"));
  }

  async function onUpdated() {
    await load();
    notifier.notify(t("documents.updated"));
  }

  onMounted(async () => {
    try {
      await load();
    } finally {
      loading.value = false;
    }
  });
</script>

<template>
  <v-container class="pt-3" fluid>
    <!--
      Above the three branches below, not inside one: loading, empty and the
      grid are mutually exclusive, so a button placed in any of them would
      vanish in the other two — including the empty state, which is exactly
      when creating a document matters most.
    -->
    <v-btn
      v-if="canCreate"
      block
      class="mb-3"
      color="tertiary"
      prepend-icon="mdi-file-plus"
      @click="createOpen = true"
    >
      {{ t("documents.create") }}
    </v-btn>

    <v-progress-linear v-if="loading" indeterminate />

    <p v-else-if="documents.length === 0" class="mt-10 text-center opacity-60">
      {{ t("documents.empty") }}
    </p>

    <v-row v-else :gap="[8, 13]">
      <v-col
        v-for="doc in documents"
        :key="doc.id"
        class="m-0"
        cols="12"
        md="4"
        sm="6"
        xl="2"
      >
        <v-card class="group flex flex-col h-full" @click="open(doc)">
          <!--
            A 2x2 cover grid of the four most recent images. Rendered by index
            rather than v-for over the array, so a document with fewer than four
            leaves genuinely empty cells instead of collapsing to a 2x1 or 1x1 —
            the 2x2 shape is fixed regardless of how many images exist.

            A document with none keeps the folder icon: four blank cells would
            read as a broken thumbnail rather than an empty document.

            flex-none on both branches is load-bearing: .v-img ships
            flex: 1 0 auto and would grow to absorb the row's leftover height,
            breaking the 3:2 crop, while the fallback's default flex: 0 1 auto
            would let it shrink and stop the branches lining up in one row.

            thumb is reused rather than given its own conversion: at 533x400 it
            covers a ~157x105 quadrant even at 2x DPR.

            Dimmed with a brightness filter, not opacity: opacity would make the
            grid translucent and let the card's teal surface show through, which
            washes the images out rather than darkening them. A filter acts on
            the pixels and so is independent of whatever sits behind.

            The filter sits on each image rather than on the grid, because a
            filter also applies to the element's own background — and this
            grid's background *is* the divider lines, showing through the gap.
            Filtering the container would mute the primary along with the photos.

            min-h-0 is what actually holds the 3:2 box, and only breaks once the
            images load — which is why it is easy to miss. aspect-ratio is a
            *preferred* size, and this div is a flex item of the card, so it
            carries min-height: auto, whose automatic minimum is its content
            height. A loaded VImg gives its .v-responsive__sizer the
            thumbnail's own ratio (padding-bottom: 100% for the square thumbs),
            so two rows of squares outrank the aspect box: the container goes
            square, grid-rows-2's minmax(0,1fr) tracks then resolve against the
            grown height, and a row of documents with images ends up ~116px
            taller than a row of empty ones.

            flex-none does not cover this — it sets grow/shrink/basis, not
            min-height. overflow-hidden would also work, since any non-visible
            overflow zeroes the automatic minimum size, but it says nothing
            about why. The images are still clipped either way: .v-img ships
            overflow: hidden, so the oversized sizer is cropped rather than
            spilling, and `cover` keeps filling the quadrant.
          -->
          <div
            v-if="covers(doc).length > 0"
            class="grid min-h-0 flex-none grid-cols-2 grid-rows-2 gap-0.5 bg-primary"
            style="aspect-ratio: 3 / 2"
          >
            <template v-for="cell in 4" :key="cell">
              <v-img
                v-if="covers(doc)[cell - 1]"
                :alt="covers(doc)[cell - 1].title"
                class="bg-black brightness-65 transition duration-200 group-hover:brightness-100"
                cover
                :src="covers(doc)[cell - 1].thumb_url"
              >
                <template #placeholder>
                  <div class="flex items-center justify-center h-full">
                    <v-progress-circular indeterminate size="20" />
                  </div>
                </template>
              </v-img>

              <!-- Same bg-black as the images above, and for the same reason:
                   the container's background is primary, so any cell that is
                   not opaque — empty here, still loading there — would show a
                   solid primary block instead of just the gap lines. -->
              <div v-else class="bg-black" />
            </template>
          </div>

          <!-- min-h-0 here too, so the branches cannot drift: defensive today,
               since one 48px icon can never exceed the box, but this branch has
               to keep agreeing with the one above. -->
          <div
            v-else
            class="flex min-h-0 flex-none items-center justify-center bg-surface-darken-2"
            style="aspect-ratio: 3 / 2"
          >
            <v-icon icon="mdi-folder-outline" size="48" />
          </div>

          <v-card-title
            class="p-3 pb-1 font-medium"
            :class="isRtl ? 'text-right' : 'text-left'"
            dir="auto"
          >
            {{ doc.title }}
          </v-card-title>

          <v-card-subtitle
            :class="isRtl ? 'text-right mr-1' : 'text-left ml-1'"
            dir="auto"
          >
            {{ doc.creator }}
          </v-card-subtitle>

          <v-card-text class="pb-2 flex flex-col">
            <v-chip
              class="self-start"
              :class="isRtl ? 'text-right' : 'text-left'"
              color="tertiary"
              size="small"
              variant="elevated"
            >
              {{ t("documents.imageCount", doc.images_count) }}
            </v-chip>

            <!--
              truncate, so a description is one line whatever its length. That
              is also what keeps the cards uniform: it is the only
              variable-height element on the card — both cover branches are
              aspect-ratio 3/2 and .v-card-title/.v-card-subtitle are nowrap —
              so fixing it at one line makes every card in the grid identical,
              with no min-height to reserve.

              Not line-clamp-2: Chrome 148 clips that without painting an
              ellipsis, and no standard multi-line alternative is supported
              there. text-overflow does paint one, but only on a single line.
            -->
            <p
              class="mt-4 truncate"
              :class="isRtl ? 'text-right' : 'text-left'"
              dir="auto"
            >
              {{ doc.description || t("gallery.noDescription") }}
            </p>

            <!--
              The edit button shares the created-at row rather than taking a
              v-card-actions of its own: an extra block would add height, and
              the cards are deliberately identical (see the min-h note above).
              It is present on every card or none — the permission is per user,
              and the listing is already team-scoped — so the rows stay even.
            -->
            <div
              class="mt-auto pt-4 flex items-center justify-between gap-2"
            >
              <!-- No dir="auto": Intl renders this in the active locale already. -->
              <p
                class="text-sm opacity-70"
                :class="isRtl ? 'text-right' : 'text-left'"
              >
                {{ formatRelative(doc.created_at) }}
              </p>

              <!--
                .stop is load-bearing: the whole card carries @click="open(doc)",
                so without it editing would also navigate into the document.
              -->
              <v-btn
                v-if="canEdit(doc)"
                color="tertiary"
                density="comfortable"
                icon="mdi-pencil"
                size="small"
                :title="t('documents.edit')"
                variant="text"
                @click.stop="openEdit(doc)"
              />
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <DocumentCreateDialog
      v-model="createOpen"
      :locked-team-id="lockedTeamId"
      @created="onCreated"
    />

    <!--
      v-if for the non-null `document` prop; `editOpen` is the separate flag
      that keeps the content through the close transition.
    -->
    <DocumentEditDialog
      v-if="editing"
      v-model="editOpen"
      :document="editing"
      :locked-team-id="editing.team?.id"
      @updated="onUpdated"
    />
  </v-container>
</template>

<route lang="json">
{
  "name": "documents"
}
</route>
