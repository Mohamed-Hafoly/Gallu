<script setup lang="ts">
  import type { Image } from "@/types/image";
  import { onMounted, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  /**
   * The images grid, shared by /gallery and /documents/[id].
   *
   * With a `documentId` it lists just that document's images and can upload into
   * it. Without one it is the flat, read-only view of everything the caller may
   * see: there is no document to attach an upload to, so the button is hidden
   * rather than shown and then failing validation.
   */
  const props = defineProps<{ documentId?: number }>();

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatRelative } = useDateFormat();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();

  const images = ref<Image[]>([]);
  const loading = ref(true);
  const selectedImage = ref<Image | null>(null);
  const detailOpen = ref(false);
  const createOpen = ref(false);

  function openDetail(image: Image) {
    selectedImage.value = image;
    detailOpen.value = true;
  }

  function onDeleted(id: number) {
    images.value = images.value.filter((image) => image.id !== id);
    notifier.notify(t("gallery.deleted"));
  }

  async function fetchImages() {
    loading.value = true;
    try {
      images.value = await imageStore.fetchImages(props.documentId);
    } finally {
      loading.value = false;
    }
  }

  async function onCreated() {
    notifier.notify(t("gallery.uploaded"));
    await fetchImages();
  }

  async function onUpdated() {
    notifier.notify(t("gallery.saved"));
    await fetchImages();
  }

  onMounted(fetchImages);
</script>

<template>
  <div>
    <!-- Only inside a document: a flat listing has nothing to upload into. -->
    <v-btn
      v-if="documentId !== undefined"
      block
      color="tertiary"
      prepend-icon="mdi-image-plus"
      @click="createOpen = true"
    >
      {{ t("gallery.upload") }}
    </v-btn>

    <v-progress-linear v-if="loading" class="mt-4" indeterminate />

    <p v-else-if="images.length === 0" class="mt-10 text-center opacity-60">
      {{ t("gallery.empty") }}
    </p>

    <v-row v-else class="mt-2" :gap="[8, 13]">
      <v-col
        v-for="image in images"
        :key="image.id"
        class="m-0"
        cols="12"
        md="4"
        sm="6"
        xl="2"
      >
        <v-card class="flex flex-col h-full" @click="openDetail(image)">
          <!--
            flex-none is load-bearing: .v-img ships flex: 1 0 auto, so inside the
            card's flex column it grows to absorb whatever height the row's
            tallest card leaves over, deepening the thumbnail and breaking the
            3:2 crop on exactly the cards with the *least* text.
          -->
          <v-img
            :alt="image.title"
            :aspect-ratio="3 / 2"
            class="flex-none"
            cover
            :src="image.thumb_url"
          >
            <template #placeholder>
              <div class="flex items-center justify-center h-full">
                <v-progress-circular indeterminate />
              </div>
            </template>
          </v-img>

          <!--
            dir="auto" picks the ellipsis side from the text's own direction;
            useRtl() keeps every card pinned to the UI edge regardless.
          -->
          <v-card-title
            class="p-3 pb-1 font-medium"
            :class="isRtl ? 'text-right' : 'text-left'"
            dir="auto"
          >
            {{ image.title }}
          </v-card-title>

          <v-card-subtitle
            :class="isRtl ? 'text-right mr-1' : 'text-left ml-1'"
            dir="auto"
          >
            {{ image.creator }}
          </v-card-subtitle>

          <!--
            flex-col so the timestamp's mt-auto can push it to the bottom.
            v-card-text is already flex:1 1 auto inside the card's column, so it
            fills the leftover height and the timestamp lands on the card's floor
            no matter how many lines the chips or description take.
          -->
          <v-card-text class="pb-2 flex flex-col">
            <CategoryChips :items="image.categories" />

            <!--
              dir="auto" picks the ellipsis side from the description's own text;
              useRtl() keeps every card's text pinned to the UI edge regardless.
            -->
            <p
              class="mt-4 line-clamp-2"
              :class="isRtl ? 'text-right' : 'text-left'"
              dir="auto"
            >
              {{ image.description || t("gallery.noDescription") }}
            </p>

            <!--
              No dir="auto" here, unlike the lines above: Intl.RelativeTimeFormat
              renders in the active locale, so this string's script always
              matches the UI and dir="auto" would be inert. Only user-supplied
              text (title, description, creator) can disagree with the UI.
            -->
            <p
              class="mt-auto pt-4 text-sm opacity-70"
              :class="isRtl ? 'text-right' : 'text-left'"
            >
              {{ formatRelative(image.created_at) }}
            </p>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <ImageDetailDialog
      v-model="detailOpen"
      :image="selectedImage"
      @deleted="onDeleted"
      @updated="onUpdated"
    />

    <ImageCreateDialog
      v-if="documentId !== undefined"
      v-model="createOpen"
      :document-id="documentId"
      @created="onCreated"
    />
  </div>
</template>
