<script setup lang="ts">
  import type { Image } from "@/types/image";
  import { computed, onMounted, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRoute, useRouter } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useInfiniteScroll } from "@/composables/useInfiniteScroll";
  import { useImageStore } from "@/stores/image";
  import { useNotifierStore } from "@/stores/notifier";

  /**
   * The images grid, shared by /gallery and /documents/[id].
   *
   * With a `documentId` it is the document's feed: an All/Yours chip pair and
   * an infinite-scrolled listing paged server-side, and it can upload into the
   * document. Without one it is the flat, read-only view of everything the
   * caller may see — one request, no paging, no chips, and no upload button,
   * since there is no document to attach an upload to.
   */
  const props = defineProps<{ documentId?: number }>();

  /** Images fetched per scroll. */
  const PER_PAGE = 20;

  /**
   * The one value the `owner` filter takes. "All" is the *absence* of the
   * param, not a value of it — the backend 422s anything else.
   */
  const OWNER_MINE = "mine";

  const { t } = useI18n();
  const { isRtl } = useRtl();
  const { formatRelative } = useDateFormat();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();
  const route = useRoute();
  const router = useRouter();

  const images = ref<Image[]>([]);
  const selectedImage = ref<Image | null>(null);
  const detailOpen = ref(false);
  const createOpen = ref(false);

  // Two flags, not one: `loading` replaces the grid with a bar for the first
  // page, `appending` puts a spinner under it for every page after. Sharing one
  // would blank the images already on screen on every scroll.
  const loading = ref(true);
  const appending = ref(false);

  const page = ref(1);
  const lastPage = ref(1);

  /**
   * How many images each chip stands for, shown beside its label.
   *
   * Kept for both chips at once rather than read off the current listing, so
   * the count on the chip you are not on is a real number instead of a blank
   * that only fills in once you click it.
   */
  const totals = ref({ all: 0, mine: 0 });

  /**
   * Which chip is active, derived from the query string rather than held in a
   * ref: the URL is the single source of truth, so back/forward and a refresh
   * land on the right chip without a second copy of the state to keep in sync.
   *
   * `undefined` — no param at all — is "All". The backend accepts no `owner=all`
   * because "all" is the absence of the filter, so the default URL stays clean.
   */
  const owner = computed(() =>
    route.query.owner === OWNER_MINE ? OWNER_MINE : undefined,
  );

  /**
   * v-chip-group binds a value, so the chips read and write the query param
   * through here. Spreading the rest of `route.query` is what keeps the search
   * and further filters planned for this row from being wiped by a chip click.
   *
   * push rather than replace: a filter change is a place the user can go back
   * from, and the watcher on `owner` reloads on a popstate just as it does on a
   * click, so back and forward move between the chips for free.
   */
  const ownerChip = computed({
    get: () => owner.value ?? "all",
    set: (value: string) => {
      const query = { ...route.query };

      if (value === OWNER_MINE) query.owner = OWNER_MINE;
      else delete query.owner;

      router.push({ query });
    },
  });

  function openDetail(image: Image) {
    selectedImage.value = image;
    detailOpen.value = true;
  }

  function onDeleted(id: number) {
    // Filtered locally rather than refetched: reloading page 1 would throw away
    // every page scrolled so far and jump the viewport back to the top.
    images.value = images.value.filter((image) => image.id !== id);

    // The chip counts have to follow, or they keep advertising a row that is no
    // longer in the grid. The active chip is decremented in place; the other is
    // re-asked, because an admin may delete a teammate's image and the listing
    // does not say whose an image was.
    const active = owner.value ?? "all";
    totals.value[active] = Math.max(totals.value[active] - 1, 0);
    void fetchIdleTotal();

    notifier.notify(t("gallery.deleted"));
  }

  /** The flat /gallery listing: everything the caller may see, in one request. */
  async function fetchAll() {
    loading.value = true;
    try {
      images.value = await imageStore.fetchImages(props.documentId);
    } finally {
      loading.value = false;
    }
  }

  /**
   * One page of the document's feed. Page 1 replaces the list, later pages
   * append to it — which is also what makes a chip switch a plain reset rather
   * than a special case.
   */
  async function fetchPage(target: number) {
    const first = target === 1;

    if (first) loading.value = true;
    else appending.value = true;

    try {
      const result = await imageStore.fetchImagePage({
        document_id: props.documentId,
        page: target,
        per_page: PER_PAGE,
        // Omitted entirely for "All": there is no owner=all, and an empty
        // string would be a 422.
        ...(owner.value ? { owner: owner.value } : {}),
      });

      images.value = first ? result.items : [...images.value, ...result.items];
      page.value = target;
      lastPage.value = result.lastPage;
      // The active chip's count comes free with its own page - only the other
      // one has to be asked for. See fetchIdleTotal().
      totals.value[owner.value ?? "all"] = result.total;
    } finally {
      loading.value = false;
      appending.value = false;
    }
  }

  /**
   * The count on the chip that is *not* selected.
   *
   * A separate request, but the cheapest possible one: per_page 1 means a single
   * row of payload, and only meta.total is read off it. Deliberately not awaited
   * with the page above - a slow count must not hold the grid back, and it
   * carries no `page`, so it can never disturb the feed's own paging.
   */
  async function fetchIdleTotal() {
    const idle = owner.value ? undefined : OWNER_MINE;

    const result = await imageStore.fetchImagePage({
      document_id: props.documentId,
      page: 1,
      per_page: 1,
      ...(idle ? { owner: idle } : {}),
    });

    totals.value[idle ?? "all"] = result.total;
  }

  function reload() {
    if (props.documentId === undefined) return fetchAll();

    page.value = 1;
    lastPage.value = 1;

    // The grid's own request goes out first; the count is a straggler nobody
    // waits on.
    const pending = fetchPage(1);

    void fetchIdleTotal();

    return pending;
  }

  const { sentinel } = useInfiniteScroll(
    () => fetchPage(page.value + 1),
    () => !loading.value && !appending.value && page.value < lastPage.value,
  );

  async function onCreated() {
    notifier.notify(t("gallery.uploaded"));
    // Back to page 1 rather than prepending the new row: it is only correct at
    // the top under the listing's current sort, and a reload cannot disagree
    // with what the server would serve.
    await reload();
  }

  async function onUpdated() {
    notifier.notify(t("gallery.saved"));
    await reload();
  }

  watch(owner, () => reload());

  onMounted(reload);
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

    <!--
      The filter row. Only inside a document — /gallery has no owner axis, since
      it is already everything the caller may see. Search and further filters
      are planned to sit alongside the chips here.
    -->
    <v-chip-group
      v-if="documentId !== undefined"
      v-model="ownerChip"
      class="mt-1"
      color="tertiary"
      filter
      mandatory
    >
      <!--
        The count is a plain span rather than a v-badge: a badge floats over the
        chip's corner and would be clipped by the group's horizontal scroll on a
        narrow screen.
      -->
      <v-chip value="all">
        {{ t("documents.allImages") }}
        <span class="ms-2 text-sm opacity-70">{{ totals.all }}</span>
      </v-chip>

      <v-chip value="mine">
        {{ t("documents.yourImages") }}
        <span class="ms-2 text-sm opacity-70">{{ totals.mine }}</span>
      </v-chip>
    </v-chip-group>

    <v-progress-linear v-if="loading" class="mt-4" indeterminate />

    <!--
      "this document is empty" and "you have uploaded nothing here" are
      different facts, so the Yours chip gets its own line rather than the
      generic one.
    -->
    <p v-else-if="images.length === 0" class="mt-10 text-center opacity-60">
      {{ owner ? t("documents.noImagesYours") : t("gallery.empty") }}
    </p>

    <template v-else>
      <v-row class="mt-2" :gap="[8, 13]">
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

      <!--
        The infinite-scroll trigger. Rendered only while pages remain, so
        reaching the end of the listing tears the observer down rather than
        leaving it to fire requests that would return nothing.
      -->
      <div
        v-if="documentId !== undefined && page < lastPage"
        ref="sentinel"
        class="flex justify-center py-6"
      >
        <v-progress-circular v-if="appending" indeterminate />
      </div>
    </template>

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
