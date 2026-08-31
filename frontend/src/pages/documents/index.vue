<script setup lang="ts">
  import type { Document } from "@/types/document";
  import { computed, onMounted, ref, watch } from "vue";
  import { useI18n } from "vue-i18n";
  import { useRouter } from "vue-router";
  import { useBulkSelection } from "@/composables/useBulkSelection";
  import { useDateFormat } from "@/composables/useDateFormat";
  import { useDocumentPermissions } from "@/composables/useDocumentPermissions";
  import { useInfiniteScroll } from "@/composables/useInfiniteScroll";
  import { useListQuery } from "@/composables/useListQuery";
  import { useAuthStore } from "@/stores/auth";
  import { useDocumentStore } from "@/stores/document";
  import { useNotifierStore } from "@/stores/notifier";

  /** Documents fetched per scroll. */
  const PER_PAGE = 20;

  /** What the trash chip sends: deleted rows only, never mixed with live ones. */
  const TRASHED_ONLY = "only";

  /**
   * The two chips, in the order they render. No owner axis, unlike the images
   * grid: a document belongs to a team rather than to the person who made it,
   * so "mine" would not be a distinction the policies recognise.
   */
  type Filter = "all" | "trash";

  /**
   * What each chip puts on the wire. "All" sends nothing at all: the trash is a
   * separate bucket, not a subset, so a listing that does not ask for deleted
   * rows must never receive any.
   */
  const FILTERS = {
    all: {},
    trash: { trashed: TRASHED_ONLY },
  } as const;

  const CHIP_LABELS: Record<Filter, string> = {
    all: "documents.allDocuments",
    trash: "documents.trashedDocuments",
  };

  /** Newest first, and stable: DocumentController adds `id` as a tie-break. */
  const DEFAULT_SORT_BY = "created_at";
  const DEFAULT_SORT_ORDER = "desc";

  /**
   * Sortable columns, mirroring the admin documents table minus `id`.
   * `images_count` is withCount()'s select alias rather than a column, and
   * `deleted_at` is only meaningful on the trash chip; see sortOptions.
   */
  const SORT_FIELDS = [
    { value: "title", label: "admin.documents.documentTitle" },
    { value: "creator", label: "admin.documents.creator" },
    { value: "images_count", label: "admin.documents.imageCount" },
    { value: "created_at", label: "common.createdAt" },
    { value: "updated_at", label: "common.updatedAt" },
  ] as const;

  const { t } = useI18n();
  const { formatRelative } = useDateFormat();
  const { canCreate, canEdit, canDelete, lockedTeamId } =
    useDocumentPermissions();
  const authStore = useAuthStore();
  const documentStore = useDocumentStore();
  const notifier = useNotifierStore();
  const router = useRouter();

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

  /**
   * The card's cover images. Defaulted because DocumentResource only returns
   * the key when the request asks for it — this page's fetches send `cover`, so
   * it is always present here, but the type cannot know that.
   */
  const covers = (document_: Document) => document_.images ?? [];

  const documents = ref<Document[]>([]);

  const createOpen = ref(false);
  // The document being edited is held apart from the dialog's open flag, as on
  // the admin page: the dialog keeps rendering through its close transition, so
  // clearing the document with the flag would blank the form on the way out.
  const editing = ref<Document | null>(null);
  const editOpen = ref(false);

  const bulkDeleteOpen = ref(false);

  // Two flags, not one: `loading` replaces the grid with a bar for the first
  // page, `appending` puts a spinner under it for every page after. Sharing one
  // would blank the cards already on screen on every scroll.
  const loading = ref(true);
  const appending = ref(false);

  const page = ref(1);
  const lastPage = ref(1);

  /**
   * How many documents each chip stands for, shown beside its label. Kept for
   * both at once rather than read off the current listing, so the count on the
   * chip you are not on is a real number instead of a blank.
   */
  const totals = ref<Record<Filter, number>>({ all: 0, trash: 0 });

  /**
   * Who gets the trash chip and the selection toggle.
   *
   * Only admins and super-admins: DocumentPolicy gates `viewTrashed`, `delete`
   * and `restore` on `role() === Admin`, so a member's trash chip would be a
   * button that 403s and a selection they could do nothing with. Members keep
   * the search and the sort, which they may use.
   *
   * Cosmetic, like every other check in this app — the policy is what denies.
   */
  const isAdmin = computed(() => {
    const user = authStore.user;

    return Boolean(user?.is_super_admin || user?.role === "admin");
  });

  /** The chips the caller is offered, counts and all. */
  const chips = computed(() =>
    (Object.keys(FILTERS) as Filter[])
      .filter((value) => value === "all" || isAdmin.value)
      .map((value) => ({
        value,
        label: t(CHIP_LABELS[value]),
        count: totals.value[value],
      })),
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
      ? [{ value: "deleted_at", title: t("admin.documents.deletedAt") }]
      : []),
  ]);

  /**
   * Whether one card may be picked. isAdmin already guarantees this in every
   * reachable state — the toggle is only offered to admins — and restore needs
   * no check at all, since the trashed listing is already team-scoped.
   */
  function canPick(document_: Document) {
    return filter.value === "trash" || canDelete(document_);
  }

  /**
   * One handler for the whole card, because in select mode the card *is* the
   * checkbox: clicking anywhere on it picks rather than opening the document.
   *
   * The checkbox keeps its own @click.stop so a click there toggles once rather
   * than twice, and the edit and restore buttons are hidden while selecting so
   * nothing on the card does anything else.
   */
  function onCardClick(document_: Document) {
    if (selecting.value && canPick(document_)) togglePick(document_.id);
    else open(document_);
  }

  // Navigates rather than opening a dialog: a document's content is its images,
  // which need a page of their own.
  function open(document_: Document) {
    router.push(`/documents/${document_.id}`);
  }

  function openEdit(document_: Document) {
    editing.value = document_;
    editOpen.value = true;
  }

  /**
   * The time a card shows, labelled with what it actually is.
   *
   * A bare relative time is ambiguous once it can mean two things, so the label
   * travels with it: "last updated 3 hours ago" normally, "deleted 3 hours ago"
   * in the trash, where the card shows deleted_at instead.
   */
  function cardTimestamp(document_: Document) {
    const trashed = filter.value === "trash";
    const time = formatRelative(
      trashed ? document_.deleted_at : document_.updated_at,
    );

    return t(trashed ? "gallery.deletedAgo" : "gallery.lastUpdated", { time });
  }

  /**
   * One page of the listing. Page 1 replaces the grid, later pages append to
   * it — which is also what makes a chip switch a plain reset rather than a
   * special case.
   */
  async function fetchPage(target: number) {
    const first = target === 1;

    if (first) loading.value = true;
    else appending.value = true;

    try {
      const result = await documentStore.fetchDocumentPage({
        page: target,
        per_page: PER_PAGE,
        cover: 1,
        // Omitted entirely for "All": the trash is a separate bucket rather
        // than a subset of it.
        ...filterParams.value,
        search: searchTerm.value,
        sort_by: sortBy.value,
        sort_order: sortOrder.value,
      });

      documents.value = first
        ? result.items
        : [...documents.value, ...result.items];
      page.value = target;
      lastPage.value = result.lastPage;
      // The active chip's count comes free with its own page — only the other
      // one has to be asked for. See fetchIdleTotals().
      totals.value[filter.value] = result.total;
    } finally {
      loading.value = false;
      appending.value = false;
    }
  }

  /**
   * The count on the chip that is *not* selected.
   *
   * The cheapest possible request: per_page 1 means a single row of payload, no
   * `cover` means no thumbnails with it, and only meta.total is read off it.
   * Deliberately not awaited with the page above — a slow count must not hold
   * the grid back, and it carries no `page`, so it can never disturb the feed's
   * own paging.
   *
   * Skipped entirely for a member, whose only chip is the one they are on: the
   * trash probe would 403.
   */
  async function fetchIdleTotals() {
    const idle = (Object.keys(FILTERS) as Filter[]).filter(
      (key) => key !== filter.value && (key === "all" || isAdmin.value),
    );

    await Promise.all(
      idle.map(async (key) => {
        const result = await documentStore.fetchDocumentPage({
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
    page.value = 1;
    lastPage.value = 1;

    // Cleared here because reload() is the single funnel for a chip, search or
    // sort change — what stops the selection holding an id that is no longer on
    // screen.
    clearSelection();

    // The grid's own request goes out first; the count is a straggler nobody
    // waits on.
    const pending = fetchPage(1);

    void fetchIdleTotals();

    return pending;
  }

  const { sentinel } = useInfiniteScroll(
    () => fetchPage(page.value + 1),
    () => !loading.value && !appending.value && page.value < lastPage.value,
  );

  /**
   * Dropped locally rather than reloaded: reloading page 1 would throw away
   * every page scrolled so far and jump the viewport back to the top.
   */
  function removeLocally(ids: number[]) {
    const removed = new Set(ids);

    documents.value = documents.value.filter((row) => !removed.has(row.id));
    totals.value[filter.value] = Math.max(
      totals.value[filter.value] - removed.size,
      0,
    );
    void fetchIdleTotals();
  }

  async function bulkDestroy() {
    await runBulk((id) => documentStore.deleteDocument(id), {
      successKey: "admin.documents.bulkDeleted",
      failureKey: "admin.documents.bulkDeleteFailed",
      onRemoved: removeLocally,
    });

    // ConfirmDialog does not close itself, as on the admin screens.
    bulkDeleteOpen.value = false;
  }

  // No confirmation, unlike bulk delete: restoring is not destructive.
  async function bulkRestore() {
    await runBulk((id) => documentStore.restoreDocument(id), {
      successKey: "admin.documents.bulkRestored",
      failureKey: "admin.documents.bulkRestoreFailed",
      onRemoved: removeLocally,
    });
  }

  /**
   * Put a deleted document back.
   *
   * No per-document permission check: the trashed listing is only served to an
   * admin and is team-scoped, and DocumentPolicy::restore applies the same rule
   * server-side.
   */
  async function restore(document_: Document) {
    try {
      await documentStore.restoreDocument(document_.id);
    } catch {
      notifier.notify(t("admin.documents.restoreFailed"), "error");
      return;
    }

    removeLocally([document_.id]);
    notifier.notify(t("admin.documents.restored"));
  }

  // Success is announced here, failure inside the dialog — the same split the
  // admin documents screen uses, so a dialog that stays open on error is the
  // one reporting why.
  async function onCreated() {
    await reload();
    notifier.notify(t("documents.created"));
  }

  async function onUpdated() {
    await reload();
    notifier.notify(t("documents.updated"));
  }

  /**
   * An explicit list rather than a deep watch on a rebuilt params object, which
   * would fire on every render. Everything funnels through reload() so page and
   * lastPage reset together — calling fetchPage directly would leave the
   * infinite scroll paging through the previous result set.
   */
  watch([filter, searchTerm, sortBy, sortOrder], () => reload());

  /**
   * Only a chip change leaves the mode; reload() clears the selection on a
   * search or sort change too, but dropping out of selecting because somebody
   * typed a letter would be irritating. A chip change is different: the action
   * itself flips from delete to restore.
   */
  watch(filter, () => {
    selecting.value = false;
  });

  onMounted(reload);
</script>

<template>
  <v-container class="flex flex-col gap-7 pt-7 bg-surface-darken-3" fluid>
    <!--
      Above the three branches below, not inside one: loading, empty and the
      grid are mutually exclusive, so a button placed in any of them would
      vanish in the other two — including the empty state, which is exactly
      when creating a document matters most.
    -->
    <v-btn
      v-if="canCreate"
      block
      color="tertiary"
      prepend-icon="mdi-file-plus"
      @click="createOpen = true"
    >
      {{ t("documents.create") }}
    </v-btn>

    <!--
      A member gets a single "All" chip and no selection toggle, because
      DocumentPolicy would refuse them the trash and every bulk action. They
      keep the search and the sort. See isAdmin.
    -->
    <ListFilterBar
      v-model="filterChip"
      v-model:search="searchInput"
      :can-select="isAdmin"
      :chips="chips"
      :search-label="t('documents.searchDocuments')"
      :select-label="t('documents.selectDocuments')"
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

    <!-- Only on the trash chip. Reuses the admin screen's notice. -->
    <v-alert
      v-if="filter === 'trash'"
      class="mt-3"
      density="compact"
      type="warning"
      variant="tonal"
    >
      {{ t("admin.documents.trashedTitleNote") }}
    </v-alert>

    <BulkActionBar
      :count="selected.length"
      :delete-label="
        t('admin.documents.deleteSelected', { count: selected.length })
      "
      :loading="bulkInFlight"
      :restore-label="
        t('admin.documents.restoreSelected', { count: selected.length })
      "
      :trashed="filter === 'trash'"
      @delete="bulkDeleteOpen = true"
      @restore="bulkRestore"
    />

    <v-progress-linear v-if="loading" class="mt-4" indeterminate />

    <!--
      "there are no documents", "nothing is pending deletion" and "your search
      matched nothing" are different facts, so each gets its own line rather
      than one generic one.
    -->
    <p v-else-if="documents.length === 0" class="mt-10 text-center opacity-60">
      {{
        filter === "trash"
          ? t("admin.documents.trashedEmpty")
          : searchTerm
            ? t("documents.noDocumentsFound")
            : t("documents.empty")
      }}
    </p>

    <template v-else>
      <v-row class="mt-2" :gap="[8, 13]">
        <v-col
          v-for="doc in documents"
          :key="doc.id"
          class="m-0"
          cols="12"
          md="4"
          sm="6"
          xl="2"
        >
          <!--
            The ring is what makes a pick visible without reading the checkbox
            itself, which is small and sits over a busy cover.
          -->
          <v-card
            class="group flex flex-col h-full"
            :class="
              selected.includes(doc.id) ? 'ring-2 ring-tertiary' : undefined
            "
            @click="onCardClick(doc)"
          >
            <!--
              .stop is load-bearing: the card itself navigates into the
              document, so without it picking would also open it.

              A child of the card rather than of either cover branch, because
              the two branches are different elements and the checkbox must sit
              in the same corner of both. .v-card is already position: relative,
              so it is the containing block. Pinned to the inline end, so it
              follows direction — the right corner in English, the left in
              Arabic.
            -->
            <v-checkbox-btn
              v-if="selecting && canPick(doc)"
              base-color="tertiary"
              class="absolute top-1 inset-e-1 z-10 rounded bg-surface-darken-3"
              color="tertiary"
              density="comfortable"
              :model-value="selected.includes(doc.id)"
              @click.stop="togglePick(doc.id)"
            />

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

            <!--
              .v-card-title and .v-card-subtitle truncate by default, and both
              hold user text that can be Arabic among English ones. Which side
              the ellipsis falls on, and which edge the text pins to, is handled
              for both classes in styles/main.scss.
            -->
            <v-card-title class="p-3 pb-1 font-medium">
              {{ doc.title }}
            </v-card-title>

            <v-card-subtitle class="ms-1">
              {{ doc.creator }}
            </v-card-subtitle>

            <v-card-text class="pb-2 flex flex-col">
              <v-chip
                class="self-start text-start"
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
              <p class="mt-4 truncate">
                {{ doc.description || t("gallery.noDescription") }}
              </p>

              <!--
                The edit and restore buttons share the timestamp's row rather
                than taking a v-card-actions of their own: an extra block would
                add height, and the cards are deliberately identical (see the
                min-h note above).

                text-start on the timestamp, not the bidi handling the lines
                above get: Intl renders it in the active locale, so its script
                always matches the UI and its direction is already the
                element's. Only user-supplied text can disagree.
              -->
              <div class="mt-auto pt-4 flex items-center justify-between gap-2">
                <p class="text-sm text-start opacity-70">
                  {{ cardTimestamp(doc) }}
                </p>

                <!--
                  Both are gone while selecting: the whole card is a selection
                  target then, so a button that did something else would be a
                  hole in it. Bulk restore is right there in the bar instead.

                  .stop is load-bearing outside select mode: the card itself
                  navigates into the document.
                -->
                <v-btn
                  v-if="filter === 'trash' && !selecting"
                  color="tertiary"
                  density="comfortable"
                  icon="mdi-restore"
                  size="small"
                  :title="t('documents.restoreDocument')"
                  variant="text"
                  @click.stop="restore(doc)"
                />

                <v-btn
                  v-else-if="canEdit(doc) && !selecting"
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

      <!--
        The infinite-scroll trigger. Rendered only while pages remain, so
        reaching the end of the listing tears the observer down rather than
        leaving it to fire requests that would return nothing.
      -->
      <div
        v-if="page < lastPage"
        ref="sentinel"
        class="flex justify-center py-6"
      >
        <v-progress-circular v-if="appending" indeterminate />
      </div>
    </template>

    <ConfirmDialog
      v-model="bulkDeleteOpen"
      confirm-icon="mdi-delete"
      :confirm-label="t('common.delete')"
      :loading="bulkInFlight"
      :message="
        t('admin.documents.bulkDeleteConfirm', { count: selected.length })
      "
      @confirm="bulkDestroy"
    />

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
