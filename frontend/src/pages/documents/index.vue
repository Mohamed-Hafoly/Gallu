<script setup lang="ts">
  import type { Document } from "@/types/document";
  import { onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRouter } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentStore } from "@/stores/document";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatRelative } = useDateFormat();
  const documentStore = useDocumentStore();
  const router = useRouter();

  const documents = ref<Document[]>([]);
  const loading = ref(true);

  // Navigates rather than opening a dialog: a document's content is its images,
  // which need a page of their own. Creating and editing documents come later.
  function open(document: Document) {
    router.push(`/documents/${document.id}`);
  }

  onMounted(async () => {
    try {
      documents.value = await documentStore.fetchDocuments();
    } finally {
      loading.value = false;
    }
  });
</script>

<template>
  <v-container class="pt-3" fluid>
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
            v-if="doc.images.length > 0"
            class="grid min-h-0 flex-none grid-cols-2 grid-rows-2 gap-0.5 bg-primary"
            style="aspect-ratio: 3 / 2"
          >
            <template v-for="cell in 4" :key="cell">
              <v-img
                v-if="doc.images[cell - 1]"
                :alt="doc.images[cell - 1].title"
                class="bg-black brightness-65 transition duration-200 group-hover:brightness-100"
                cover
                :src="doc.images[cell - 1].thumb_url"
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
            <p :class="isRtl ? 'text-right' : 'text-left'">
              {{ t("documents.imageCount", doc.images_count) }}
            </p>

            <!--
              min-h reserves the two lines line-clamp-2 allows, rather than
              leaving a one-line description 20px shorter than a two-line one.
              Without it the cards are uniform only *within* a row: h-full
              matches a card to its tallest sibling, but v-row wraps and each
              wrapped line sizes independently, so one long description makes
              its whole row taller than the next. The description is the card's
              only variable-height element — both cover branches are
              aspect-ratio 3/2, and .v-card-title/.v-card-subtitle are nowrap —
              so pinning it makes every card in the grid identical.

              2lh, not a pixel value: it stays exactly two lines if the
              font-size or the locale changes the line box. min-h rather than h
              for the same reason — a floor pads, a fixed height would clip.
            -->
            <p
              class="mt-4 line-clamp-2 min-h-[2lh]"
              :class="isRtl ? 'text-right' : 'text-left'"
              dir="auto"
            >
              {{ doc.description || t("gallery.noDescription") }}
            </p>

            <!-- No dir="auto": Intl renders this in the active locale already. -->
            <p
              class="mt-auto pt-4 text-sm opacity-70"
              :class="isRtl ? 'text-right' : 'text-left'"
            >
              {{ formatRelative(doc.created_at) }}
            </p>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<route lang="json">
{
  "name": "documents"
}
</route>
