<script setup lang="ts">
  import type { Image } from "@/types/image";
  import { computed, onMounted, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRoute, useRouter } from "vue-router";
  import { useRtl } from "vuetify";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useImagePermissions } from "@/composables/useImagePermissions";
  import { useInfiniteScroll } from "@/composables/useInfiniteScroll";
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

  /** How long typing settles before the term reaches the URL and the wire. */
  const SEARCH_DEBOUNCE = 300;

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
  const { isRtl } = useRtl();
  const { formatRelative } = useDateFormat();
  const imageStore = useImageStore();
  const notifier = useNotifierStore();
  const authStore = useAuthStore();
  const { canDelete } = useImagePermissions();
  const route = useRoute();
  const router = useRouter();

  /**
   * What the user is typing, seeded from the URL and debounced into it.
   *
   * Separate from the query param on purpose: the URL is the source of truth
   * for the *request*, but writing it on every keystroke would refetch per
   * letter and bury the history in near-identical entries.
   */
  const searchInput = ref(String(route.query.search ?? ""));

  const images = ref<Image[]>([]);
  const selectedImage = ref<Image | null>(null);
  const detailOpen = ref(false);
  const createOpen = ref(false);

  /** Whether the grid is in selection mode. Off until the toggle turns it on. */
  const selecting = ref(false);

  /**
   * Ids, like the admin tables' `selected: number[]` — theirs is keyed by id
   * implicitly, through Vuetify's default item-value; here it is explicit.
   */
  const selected = ref<number[]>([]);

  const bulkInFlight = ref(false);
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
   * Which chip is active, derived from the query string rather than held in a
   * ref: the URL is the single source of truth, so back/forward and a refresh
   * land on the right chip without a second copy of the state to keep in sync.
   *
   * `undefined` — no param at all — is "All". The backend accepts no `owner=all`
   * because "all" is the absence of the filter, so the default URL stays clean.
   */
  const filter = computed<Filter>(() => {
    if (route.query.trashed === TRASHED_ONLY) return "trash";

    return route.query.owner === OWNER_MINE ? "mine" : "all";
  });

  /** The term that actually goes on the wire; "" is sent as nothing at all. */
  const searchTerm = computed(
    () => String(route.query.search ?? "") || undefined,
  );

  const sortBy = computed(() => String(route.query.sort_by ?? DEFAULT_SORT_BY));
  const sortOrder = computed(() =>
    route.query.sort_order === "asc" ? "asc" : DEFAULT_SORT_ORDER,
  );

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
    if (selecting.value && canPick(image)) togglePick(image);
    else openDetail(image);
  }

  function togglePick(image: Image) {
    selected.value = selected.value.includes(image.id)
      ? selected.value.filter((id) => id !== image.id)
      : [...selected.value, image.id];
  }

  /** Leaving the mode drops the selection with it; nothing else would. */
  function toggleSelecting() {
    selecting.value = !selecting.value;
    if (!selecting.value) selected.value = [];
  }

  /** Writes one query key without disturbing the others. */
  function replaceQuery(patch: Record<string, string | undefined>) {
    const query = { ...route.query };

    for (const [key, value] of Object.entries(patch)) {
      if (value === undefined) delete query[key];
      else query[key] = value;
    }

    router.replace({ query });
  }

  /**
   * What each chip puts on the wire. All sends nothing at all: the trash is a
   * separate bucket, not a subset, so a listing that does not ask for deleted
   * rows must never receive any.
   */
  const filterParams = computed(() => {
    if (filter.value === "mine") return { owner: OWNER_MINE } as const;
    if (filter.value === "trash") return { trashed: TRASHED_ONLY } as const;

    return {};
  });

  /**
   * v-chip-group binds a value, so the chips read and write the query param
   * through here. Spreading the rest of `route.query` is what keeps the search
   * and further filters planned for this row from being wiped by a chip click.
   *
   * replace rather than push: the chips filter one page, they are not places to
   * navigate between, so a toggle should not cost a history entry. Pushing made
   * Back walk out of the document one chip click at a time instead of leaving
   * it. The cost, taken deliberately: back and forward no longer step through
   * chip states. The param still lives in the URL, so a refresh or a shared
   * link lands on the right chip either way.
   */
  const filterChip = computed({
    get: () => filter.value,
    set: (value: Filter) => {
      const query = { ...route.query };

      // Both keys are cleared before one is set: the chips are one axis, and
      // leaving the other behind would produce ?owner=mine&trashed=only, which
      // reads as two filters at once and is not a state any chip can show.
      delete query.owner;
      delete query.trashed;

      if (value === "mine") query.owner = OWNER_MINE;
      if (value === "trash") query.trashed = TRASHED_ONLY;

      // Leaving the trash while sorted by deleted_at would strand the select on
      // a value it no longer offers, and sort live rows by a column that is
      // null on every one of them.
      if (value !== "trash" && query.sort_by === "deleted_at") {
        delete query.sort_by;
      }

      router.replace({ query });
    },
  });

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
   * Run one action across the selection.
   *
   * There is no batch endpoint, so each id is its own request. allSettled
   * rather than all: one rejection must not abandon the rest, and the count of
   * failures is what gets reported — the same shape as the admin tables.
   *
   * Where this deliberately parts company with them: they finish by reloading,
   * which is also how their selection gets cleared. Reloading here would throw
   * away every page scrolled so far and jump the viewport to the top, so the
   * rows that succeeded are spliced out locally instead — the same choice
   * onDeleted and restore already make — and the selection is cleared by hand.
   */
  async function runBulk(
    action: (id: number) => Promise<unknown>,
    successKey: string,
    failureKey: string,
  ) {
    // Copied before the await: the array is emptied below, and the admin
    // version copies for the same reason.
    const ids = [...selected.value];

    bulkInFlight.value = true;
    try {
      const results = await Promise.allSettled(ids.map((id) => action(id)));
      const failed = results.filter((r) => r.status === "rejected").length;

      // Only the ones that actually went through leave the grid; a row that
      // failed is still there, and still selected in spirit if not in state.
      const removed = new Set(
        ids.filter((_, index) => results[index]!.status === "fulfilled"),
      );

      images.value = images.value.filter((image) => !removed.has(image.id));
      totals.value[filter.value] = Math.max(
        totals.value[filter.value] - removed.size,
        0,
      );
      void fetchIdleTotals();

      selected.value = [];

      if (failed > 0)
        notifier.notify(t(failureKey, { count: failed }), "error");
      else notifier.notify(t(successKey, { count: ids.length }));
    } finally {
      bulkInFlight.value = false;
    }
  }

  async function bulkDestroy() {
    await runBulk(
      (id) => imageStore.deleteImage(id),
      "admin.images.bulkDeleted",
      "admin.images.bulkDeleteFailed",
    );

    // ConfirmDialog does not close itself, as on the admin screens.
    bulkDeleteOpen.value = false;
  }

  // No confirmation, unlike bulk delete: restoring is not destructive.
  async function bulkRestore() {
    await runBulk(
      (id) => imageStore.restoreImage(id),
      "admin.images.bulkRestored",
      "admin.images.bulkRestoreFailed",
    );
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

  /** What each chip would send, so an idle one can be counted without selecting it. */
  const PARAMS_BY_FILTER = {
    all: {},
    mine: { owner: OWNER_MINE },
    trash: { trashed: TRASHED_ONLY },
  } as const;

  /**
   * The counts on the two chips that are *not* selected.
   *
   * Separate requests, but the cheapest possible ones: per_page 1 means a single
   * row of payload, and only meta.total is read off each. Deliberately not
   * awaited with the page above - a slow count must not hold the grid back, and
   * they carry no `page`, so they can never disturb the feed's own paging.
   */
  async function fetchIdleTotals() {
    const idle = (Object.keys(PARAMS_BY_FILTER) as Filter[]).filter(
      (key) => key !== filter.value,
    );

    await Promise.all(
      idle.map(async (key) => {
        const result = await imageStore.fetchImagePage({
          document_id: props.documentId,
          page: 1,
          per_page: 1,
          ...PARAMS_BY_FILTER[key],
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
    selected.value = [];

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
   * Debounced into the URL rather than straight into a request: the query
   * string is what the fetch reads, so writing it is what triggers a reload,
   * and doing that per keystroke would be a request per letter.
   */
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  watch(searchInput, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      replaceQuery({ search: value.trim() || undefined });
    }, SEARCH_DEBOUNCE);
  });

  // Back/forward and a chip's own fallback can change the term without going
  // through the field, so the field follows the URL too.
  watch(searchTerm, (value) => {
    if ((value ?? "") !== searchInput.value.trim())
      searchInput.value = value ?? "";
  });

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

  /**
   * The only thing the page reaches in for. The dialog and its reload stay here
   * so the page never has to refresh the feed itself; the button lives up in
   * the document header beside edit and delete.
   */
  defineExpose({
    openCreate: () => {
      createOpen.value = true;
    },
  });

  onMounted(reload);
</script>

<template>
  <div class="flex flex-col gap-4">
    <!--
      Where the block upload button used to be. Upload is an icon in the
      document header now, beside edit and delete.

      Styled to match /admin/images so the two screens read as one app; the
      server variant takes no `:search` prop, the term rides in the request.
    -->
    <v-text-field
      v-if="documentId !== undefined"
      v-model="searchInput"
      bg-color="surface-darken-2"
      clearable
      density="comfortable"
      hide-details
      :label="t('documents.searchImages')"
      variant="outlined"
    >
      <template #prepend-inner>
        <v-icon class="opacity-100" color="tertiary" icon="mdi-magnify" />
      </template>

      <template #clear="{ props: clearProps }">
        <v-icon v-bind="clearProps" class="opacity-100" color="tertiary" />
      </template>
    </v-text-field>

    <!--
      The filter row. Only inside a document — /gallery has no owner axis, since
      it is already everything the caller may see.

      Wraps below sm, with the sort controls dropping to their own line.
      basis-full makes that break deterministic rather than incidental: the chip
      group claims the whole first line, so the sort group has nowhere else to
      go. Tailwind's breakpoints are overridden to Vuetify's in
      styles/tailwind.css, so sm here is the same 600px useDisplay() uses.

      ms-auto on the sort group rather than justify-between on the container:
      space-between distributes items *within a line*, so once the sort group is
      alone on the second line it becomes the only item and lands at the line's
      start — the opposite of what is wanted. ms-auto pins it to the end whether
      it shares a row or has one to itself, and being logical it flips with RTL.

      min-w-0 on the chip group is still load-bearing, for a different reason
      now: the group scrolls horizontally, and a flex item defaults to
      min-width:auto, so without it the chips refuse to shrink and spill past
      the card edge instead of scrolling inside their line.
    -->
    <div
      v-if="documentId !== undefined"
      class="mt-1 flex flex-wrap items-center gap-4"
    >
      <v-chip-group
        v-model="filterChip"
        class="min-w-0 basis-full sm:basis-auto"
        color="tertiary"
        filter
        mandatory
      >
        <!--
          The count is a plain span rather than a v-badge: a badge floats over
          the chip's corner and would be clipped by the group's horizontal
          scroll on a narrow screen.
        -->
        <v-chip value="all">
          {{ t("documents.allImages") }}
          <span class="ms-2 text-sm opacity-70">{{ totals.all }}</span>
        </v-chip>

        <v-chip value="mine">
          {{ t("documents.yourImages") }}
          <span class="ms-2 text-sm opacity-70">{{ totals.mine }}</span>
        </v-chip>

        <!--
          Shown to everyone, not just admins: the backend scopes this listing
          rather than refusing it, so a member reaches their own deleted images
          and an admin the whole team's.
        -->
        <v-chip value="trash">
          {{ t("documents.trashedImages") }}
          <span class="ms-2 text-sm opacity-70">{{ totals.trash }}</span>
        </v-chip>
      </v-chip-group>

      <div class="ms-auto flex shrink-0 items-center gap-2">
        <!--
          Both surfaces set explicitly, or they disagree: an outlined field is
          transparent and shows the page through it, while the dropdown is a
          v-list in a teleported overlay painting theme surface on its own.

          list-props rather than menu-props' contentClass — the list paints its
          own background over the overlay content, so a class on the overlay
          would sit underneath it and never show.
        -->
        <v-select
          bg-color="primary-darken-4"
          class="w-44"
          density="compact"
          hide-details
          item-title="title"
          item-value="value"
          :items="sortOptions"
          :label="t('documents.sortBy')"
          :list-props="{ bgColor: 'primary-darken-4' }"
          :model-value="sortBy"
          variant="outlined"
          @update:model-value="replaceQuery({ sort_by: $event })"
        />

        <v-btn
          color="tertiary"
          :icon="
            sortOrder === 'asc' ? 'mdi-sort-ascending' : 'mdi-sort-descending'
          "
          size="small"
          :title="t('documents.sortDirection')"
          variant="text"
          @click="
            replaceQuery({ sort_order: sortOrder === 'asc' ? 'desc' : 'asc' })
          "
        />

        <!--
          Hidden where the caller could not act on anything anyway: a member on
          All is looking at teammates' images. See canSelect.
        -->
        <v-btn
          v-if="canSelect"
          :color="selecting ? 'primary' : 'tertiary'"
          :icon="
            selecting
              ? 'mdi-checkbox-multiple-marked'
              : 'mdi-checkbox-multiple-marked-outline'
          "
          size="small"
          :title="t('documents.select')"
          variant="text"
          @click="toggleSelecting"
        />
      </div>
    </div>

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

    <!--
      The action follows the chip: rows on the trash chip can only be put back,
      everywhere else they can only be binned. Restore fires straight away where
      delete asks first, matching the admin screens — restoring is not
      destructive.
    -->
    <div v-if="selected.length > 0" class="mt-3">
      <v-btn
        v-if="filter === 'trash'"
        block
        color="tertiary"
        :loading="bulkInFlight ? 'on-tertiary' : false"
        prepend-icon="mdi-restore"
        variant="elevated"
        @click="bulkRestore"
      >
        {{ t("admin.images.restoreSelected", { count: selected.length }) }}
      </v-btn>

      <v-btn
        v-else
        block
        color="error"
        :loading="bulkInFlight"
        prepend-icon="mdi-delete"
        variant="elevated"
        @click="bulkDeleteOpen = true"
      >
        {{ t("admin.images.deleteSelected", { count: selected.length }) }}
      </v-btn>
    </div>

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
                @click.stop="togglePick(image)"
              />

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
                truncate, not line-clamp-2: Chrome 148 clips the clamp without
                painting an ellipsis, and no standard multi-line alternative is
                supported there. text-overflow does paint one, at the cost of
                being a single line.

                dir="auto" picks the ellipsis side from the description's own
                text; useRtl() keeps every card's text pinned to the UI edge
                regardless.
              -->
              <p
                class="mt-4 truncate"
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

                cardTimestamp() carries its own label, so the string already
                says which time it is — created_at was dropped because the
                seeded images were made in one batch and it read the same on
                every card.
              -->
              <div class="mt-auto pt-4 flex items-center justify-between gap-2">
                <p
                  class="text-sm opacity-70"
                  :class="isRtl ? 'text-right' : 'text-left'"
                >
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
