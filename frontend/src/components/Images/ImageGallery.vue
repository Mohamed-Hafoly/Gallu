<script setup lang="ts">
  import type { Image } from "@/types/image";
  import { computed, onMounted, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useBulkSelection } from "@/composables/useBulkSelection";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useImagePermissions } from "@/composables/useImagePermissions";
  import { useInfiniteScroll } from "@/composables/useInfiniteScroll";
  import { useListQuery } from "@/composables/useListQuery";
  import { useAuthStore } from "@/stores/auth";
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

  /** What the trash chip sends: deleted rows only, never mixed with live ones. */
  const TRASHED_ONLY = "only";

  /** The three chips, in the order they render. */
  type Filter = "all" | "mine" | "trash";

  /**
   * What each chip puts on the wire. "All" sends nothing at all: the trash is a
   * separate bucket, not a subset, so a listing that does not ask for deleted
   * rows must never receive any. Trash is declared last so a URL carrying both
   * params lands on it, as the hand-written branch it replaced did.
   */
  const FILTERS = {
    all: {},
    mine: { owner: OWNER_MINE },
    trash: { trashed: TRASHED_ONLY },
  } as const;

  /** What each chip is called, in the same order. */
  const CHIP_LABELS: Record<Filter, string> = {
    all: "documents.allImages",
    mine: "documents.yourImages",
    trash: "documents.trashedImages",
  };

  /** Newest first, and stable: ImageController adds `id` as a tie-break. */
  const DEFAULT_SORT_BY = "created_at";
  const DEFAULT_SORT_ORDER = "desc";

  /**
   * Sortable columns, mirroring the admin images table minus `id` — and minus
   * `document_id`, which is fixed on this page and could not reorder anything.
   * `deleted_at` is only meaningful on the trash chip; see sortOptions.
   */
  const SORT_FIELDS = [
    { value: "title", label: "gallery.titleLabel" },
    { value: "creator", label: "admin.images.creator" },
    { value: "created_at", label: "common.createdAt" },
    { value: "updated_at", label: "common.updatedAt" },
  ] as const;

  const { t } = useI18n();
  const { formatRelative } = useDateFormat();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();
  const authStore = useAuthStore();
  const { canDelete } = useImagePermissions();

  // The URL is the single source of truth for what the listing asks for, so
  // back/forward and a refresh land on the right chip, term and sort.
  const {
    filter,
    filterParams,
    filterChip,
    searchInput,
    searchTerm,
    sortBy,
    sortOrder,
    replaceQuery,
  } = useListQuery<Filter>({
    filters: FILTERS,
    defaultSortBy: DEFAULT_SORT_BY,
    defaultSortOrder: DEFAULT_SORT_ORDER,
  });

  const {
    selecting,
    selected,
    bulkInFlight,
    togglePick,
    toggleSelecting,
    clear: clearSelection,
    runBulk,
  } = useBulkSelection();

  const images = ref<Image[]>([]);
  const selectedImage = ref<Image | null>(null);
  const detailOpen = ref(false);
  const createOpen = ref(false);

  const bulkDeleteOpen = ref(false);

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
   * Kept for all three at once rather than read off the current listing, so the
   * counts on the chips you are not on are real numbers instead of blanks that
   * only fill in once you click them.
   */
  const totals = ref<Record<Filter, number>>({ all: 0, mine: 0, trash: 0 });

  /**
   * `deleted_at` only while the trash chip is active: every live row's is null,
   * so offering it elsewhere would be a sort that does nothing.
   */
  const sortOptions = computed(() => [
    ...SORT_FIELDS.map((field) => ({
      value: field.value,
      title: t(field.label),
    })),
    ...(filter.value === "trash"
      ? [{ value: "deleted_at", title: t("admin.images.deletedAt") }]
      : []),
  ]);

  /**
   * Who may turn selection on, and where.
   *
   * An admin may act on any image in their team, so every chip is fair game. A
   * member may only act on their own, and All is full of teammates' images — so
   * they get the mode on Yours and on Recently deleted, where every row is
   * theirs by construction, and not on All.
   *
   * Cosmetic, like every other check in this app: ImagePolicy is what denies,
   * and each id in a bulk run is authorised on its own.
   */
  const canSelect = computed(() => {
    const user = authStore.user;

    if (!user) return false;

    return (
      user.is_super_admin || user.role === "admin" || filter.value !== "all"
    );
  });

  /**
   * Whether one card may be picked. canSelect already guarantees this in every
   * reachable state — the same "belt to that braces" the documents permissions
   * keep — and restore needs no check at all, since the trashed listing is
   * already scoped to rows the caller may restore.
   */
  function canPick(image: Image) {
    return filter.value === "trash" || canDelete(image);
  }

  /** The bar renders straight off this, counts and all. */
  const chips = computed(() =>
    (Object.keys(FILTERS) as Filter[]).map((value) => ({
      value,
      label: t(CHIP_LABELS[value]),
      count: totals.value[value],
    })),
  );

  /**
   * One handler for the whole card, because in select mode the card *is* the
   * checkbox: clicking anywhere on it picks rather than opening the image.
   *
   * The checkbox keeps its own @click.stop so a click there toggles once
   * rather than twice, and the per-card restore button is hidden while
   * selecting so nothing on the card does anything else.
   *
   * Falls through to the dialog for a card that cannot be picked. canSelect
   * makes that unreachable today — the mode is only offered where every row is
   * actionable — but a dead card that swallows clicks would be worse than one
   * that just opens.
   */
  function onCardClick(image: Image) {
    if (selecting.value && canPick(image)) togglePick(image.id);
    else openDetail(image);
  }

  function openDetail(image: Image) {
    selectedImage.value = image;
    detailOpen.value = true;
  }

  /**
   * The time a card shows, labelled with what it actually is.
   *
   * A bare relative time is ambiguous once it can mean two things, so the
   * label travels with it: "last updated 3 hours ago" normally, "deleted 3
   * hours ago" in the trash, where the card shows deleted_at instead.
   *
   * `filter` is "all" on /gallery, which has no chips, so that path lands on
   * updated_at without needing a documentId check.
   */
  function cardTimestamp(image: Image) {
    const trashed = filter.value === "trash";
    const time = formatRelative(trashed ? image.deleted_at : image.updated_at);

    return t(trashed ? "gallery.deletedAgo" : "gallery.lastUpdated", { time });
  }

  function onDeleted(id: number) {
    // Filtered locally rather than refetched: reloading page 1 would throw away
    // every page scrolled so far and jump the viewport back to the top.
    images.value = images.value.filter((image) => image.id !== id);

    // The chip counts have to follow, or they keep advertising a row that is no
    // longer in the grid. The active chip is decremented in place; the other is
    // re-asked, because an admin may delete a teammate's image and the listing
    // does not say whose an image was. Deleting also grows the trash.
    totals.value[filter.value] = Math.max(totals.value[filter.value] - 1, 0);
    void fetchIdleTotals();

    notifier.notify(t("gallery.deleted"));
  }

  /**
   * Put a deleted image back.
   *
   * No per-image permission check: the trash listing is scoped to rows the
   * caller may restore - a member sees only their own, an admin the team's -
   * and ImagePolicy::restore applies the same rule server-side.
   *
   * Dropped locally rather than reloaded, like onDeleted, so restoring the
   * fourth of twenty does not throw away the scroll position.
   */
  async function restore(image: Image) {
    try {
      await imageStore.restoreImage(image.id);
    } catch {
      notifier.notify(t("admin.images.restoreFailed"), "error");
      return;
    }

    images.value = images.value.filter((row) => row.id !== image.id);
    totals.value.trash = Math.max(totals.value.trash - 1, 0);
    void fetchIdleTotals();

    notifier.notify(t("documents.restored"));
  }

  /**
   * What every bulk run does with the ids that actually went through.
   *
   * Spliced out locally rather than reloaded — the same choice onDeleted and
   * restore make — because reloading would throw away every page scrolled so
   * far and jump the viewport to the top.
   */
  function onBulkRemoved(ids: number[]) {
    const removed = new Set(ids);

    images.value = images.value.filter((image) => !removed.has(image.id));
    totals.value[filter.value] = Math.max(
      totals.value[filter.value] - removed.size,
      0,
    );
    void fetchIdleTotals();
  }

  async function bulkDestroy() {
    await runBulk((id) => imageStore.deleteImage(id), {
      successKey: "admin.images.bulkDeleted",
      failureKey: "admin.images.bulkDeleteFailed",
      onRemoved: onBulkRemoved,
    });

    // ConfirmDialog does not close itself, as on the admin screens.
    bulkDeleteOpen.value = false;
  }

  // No confirmation, unlike bulk delete: restoring is not destructive.
  async function bulkRestore() {
    await runBulk((id) => imageStore.restoreImage(id), {
      successKey: "admin.images.bulkRestored",
      failureKey: "admin.images.bulkRestoreFailed",
      onRemoved: onBulkRemoved,
    });
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
        // Omitted entirely for "All": there is no owner=all, and the trash is
        // a separate bucket rather than a subset of it.
        ...filterParams.value,
        search: searchTerm.value,
        sort_by: sortBy.value,
        sort_order: sortOrder.value,
      });

      images.value = first ? result.items : [...images.value, ...result.items];
      page.value = target;
      lastPage.value = result.lastPage;
      // The active chip's count comes free with its own page - only the other
      // ones have to be asked for. See fetchIdleTotals().
      totals.value[filter.value] = result.total;
    } finally {
      loading.value = false;
      appending.value = false;
    }
  }

  /**
   * The counts on the two chips that are *not* selected.
   *
   * Separate requests, but the cheapest possible ones: per_page 1 means a single
   * row of payload, and only meta.total is read off each. Deliberately not
   * awaited with the page above - a slow count must not hold the grid back, and
   * they carry no `page`, so they can never disturb the feed's own paging.
   */
  async function fetchIdleTotals() {
    const idle = (Object.keys(FILTERS) as Filter[]).filter(
      (key) => key !== filter.value,
    );

    await Promise.all(
      idle.map(async (key) => {
        const result = await imageStore.fetchImagePage({
          document_id: props.documentId,
          page: 1,
          per_page: 1,
          ...FILTERS[key],
          // The search narrows a count the same way it narrows the grid, so the
          // chips agree with what is on screen. The sort is left out: ordering
          // cannot change a total, and omitting it keeps these probes identical
          // across sort changes.
          search: searchTerm.value,
        });

        totals.value[key] = result.total;
      }),
    );
  }

  function reload() {
    if (props.documentId === undefined) return fetchAll();

    page.value = 1;
    lastPage.value = 1;

    // Cleared here because reload() is the single funnel for a chip, search or
    // sort change — the same rule the admin loaders follow, and what stops the
    // selection holding an id that is no longer on screen.
    clearSelection();

    // The grid's own request goes out first; the counts are stragglers nobody
    // waits on.
    const pending = fetchPage(1);

    void fetchIdleTotals();

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

  /**
   * An explicit list rather than a deep watch on a rebuilt params object, which
   * would fire on every render. Everything funnels through reload() so page and
   * lastPage reset together - calling fetchPage directly would leave the
   * infinite scroll paging through the previous result set.
   */
  watch([filter, searchTerm, sortBy, sortOrder], () => reload());

  /**
   * Only a chip change leaves the mode; reload() clears the selection on a
   * search or sort change too, but dropping out of selecting because somebody
   * typed a letter would be irritating. A chip change is different: the action
   * itself can flip from delete to restore, and a member may lose the right to
   * select at all.
   */
  watch(filter, () => {
    selecting.value = false;
  });

  onMounted(reload);
</script>

<template>
  <div class="flex flex-col gap-6">
    <!--
      Only inside a document — /gallery has no owner axis, since it is already
      everything the caller may see, and nothing there to search within.
    -->
    <ListFilterBar
      v-if="documentId !== undefined"
      v-model="filterChip"
      v-model:search="searchInput"
      :can-select="canSelect"
      :chips="chips"
      :search-label="t('documents.searchImages')"
      :select-label="t('documents.select')"
      :selecting="selecting"
      :sort-by="sortBy"
      :sort-direction-label="t('documents.sortDirection')"
      :sort-label="t('documents.sortBy')"
      :sort-options="sortOptions"
      :sort-order="sortOrder"
      @toggle-selecting="toggleSelecting"
      @update:sort-by="replaceQuery({ sort_by: $event })"
      @update:sort-order="replaceQuery({ sort_order: $event })"
    />

    <!--
      Only on the trash chip. Reuses the admin screen's notice rather than a
      second copy of the same sentence.
    -->
    <v-alert
      v-if="filter === 'trash'"
      class="mt-3"
      density="compact"
      type="warning"
      variant="tonal"
    >
      {{ t("admin.images.trashedTitleNote") }}
    </v-alert>

    <BulkActionBar
      :count="selected.length"
      :delete-label="
        t('admin.images.deleteSelected', { count: selected.length })
      "
      :loading="bulkInFlight"
      :restore-label="
        t('admin.images.restoreSelected', { count: selected.length })
      "
      :trashed="filter === 'trash'"
      @delete="bulkDeleteOpen = true"
      @restore="bulkRestore"
    />

    <!--
      Only inside a document, like the filter row above it: /gallery renders no
      ImageCreateDialog, having no document to attach an upload to, so the
      button there would open nothing.

      Gone on the trash chip, where every row is already deleted and an upload
      would land in a listing that cannot show it.
    -->
    <v-btn
      v-if="documentId !== undefined && filter !== 'trash'"
      block
      class="-mb-4"
      color="tertiary"
      prepend-icon="mdi-image-plus"
      variant="flat"
      @click="createOpen = true"
    >
      {{ t("gallery.upload") }}
    </v-btn>

    <v-progress-linear v-if="loading" class="mt-4" indeterminate />

    <!--
      "this document is empty" and "you have uploaded nothing here" are
      different facts, so the Yours chip gets its own line rather than the
      generic one.
    -->
    <p v-else-if="images.length === 0" class="mt-10 text-center opacity-60">
      {{
        filter === "trash"
          ? t("admin.images.trashedEmpty")
          : filter === "mine"
            ? t("documents.noImagesYours")
            : t("gallery.empty")
      }}
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
          <!--
            The ring is what makes a pick visible without reading the checkbox
            itself, which is small and sits over a busy thumbnail.
          -->
          <v-card
            class="flex flex-col h-full"
            :class="
              selected.includes(image.id) ? 'ring-2 ring-tertiary' : undefined
            "
            @click="onCardClick(image)"
          >
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
              <!--
                .stop is load-bearing: the card itself opens the detail dialog,
                so without it picking would also open the image. Same reason as
                the restore button further down.

                Pinned to the inline end, so it follows direction like every
                other affordance here — the right corner in English, the left
                in Arabic.
              -->
              <v-checkbox-btn
                v-if="selecting && canPick(image)"
                base-color="tertiary"
                class="absolute top-1 inset-e-1 rounded bg-surface-darken-3"
                color="tertiary"
                density="comfortable"
                :model-value="selected.includes(image.id)"
                @click.stop="togglePick(image.id)"
              />

              <template #placeholder>
                <div class="flex items-center justify-center h-full">
                  <v-progress-circular indeterminate />
                </div>
              </template>
            </v-img>

            <!--
              .v-card-title and .v-card-subtitle truncate by default, and both
              hold user text that can be Arabic among English ones. Which side
              the ellipsis falls on, and which edge the text pins to, is handled
              for both classes in styles/main.scss.
            -->
            <v-card-title class="p-3 pb-1 font-medium">
              {{ image.title }}
            </v-card-title>

            <v-card-subtitle class="ms-1">
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
                truncate, not line-clamp-2: Chrome 148 clips the clamp without
                painting an ellipsis, and no standard multi-line alternative is
                supported there. text-overflow does paint one, at the cost of
                being a single line.
              -->
              <p class="mt-4 truncate">
                {{ image.description || t("gallery.noDescription") }}
              </p>

              <!--
                text-start, not the bidi handling the lines above get:
                Intl.RelativeTimeFormat renders in the active locale, so this
                string's script always matches the UI and its direction is
                already the element's. Only user-supplied text (title,
                description, creator) can disagree with the UI.

                cardTimestamp() carries its own label, so the string already
                says which time it is — created_at was dropped because the
                seeded images were made in one batch and it read the same on
                every card.
              -->
              <div class="mt-auto pt-4 flex items-center justify-between gap-2">
                <p class="text-sm text-start opacity-70">
                  {{ cardTimestamp(image) }}
                </p>

                <!--
                  Gone while selecting: the whole card is a selection target
                  then, so a button that did something else would be a hole in
                  it. Bulk restore is right there in the bar instead.

                  .stop is load-bearing outside select mode: the card itself
                  opens the detail dialog, so without it restoring would also
                  open the image.
                -->
                <v-btn
                  v-if="filter === 'trash' && !selecting"
                  color="tertiary"
                  density="comfortable"
                  icon="mdi-restore"
                  size="small"
                  :title="t('documents.restore')"
                  variant="text"
                  @click.stop="restore(image)"
                />
              </div>
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

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="t('admin.images.bulkDeleteConfirm', { count: selected.length })"
      @confirm="bulkDestroy"
    />

    <ImageCreateDialog
      v-if="documentId !== undefined"
      v-model="createOpen"
      :document-id="documentId"
      @created="onCreated"
    />
  </div>
</template>
