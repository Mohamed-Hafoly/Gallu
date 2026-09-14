import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";
import { useAuthStore } from "@/stores/auth";

export function useNavLinks() {
  const { t } = useI18n();
  const route = useRoute();
  const authStore = useAuthStore();

  const mainLinks = computed(() => [
    {
      title: t("nav.home"),
      value: "/",
      props: {
        to: { name: "home" },
        prependIcon: "mdi-home",
        class: "tracking-wider",
      },
    },
    {
      title: t("nav.documents"),
      value: "/documents",
      props: { to: { name: "documents" }, prependIcon: "mdi-folder-multiple" },
    },
    {
      title: t("nav.settings"),
      value: "/settings",
      props: { to: { name: "settings" }, prependIcon: "mdi-cog" },
    },
    // Hidden from everyone but super-admins. Cosmetic only — route paths ship
    // in the bundle either way; the backend policies are the real enforcement.
    ...(authStore.user?.is_super_admin
      ? [
          {
            title: t("nav.admin"),
            value: "/admin/users",
            props: {
              to: { name: "admin-users" },
              prependIcon: "mdi-shield-account",
            },
          },
        ]
      : []),
  ]);

  const adminLinks = computed(() => [
    {
      title: t("nav.backToDocuments"),
      value: "/documents",
      props: { to: { name: "documents" }, prependIcon: "mdi-arrow-left" },
    },
    {
      title: t("nav.users"),
      value: "/admin/users",
      props: {
        to: { name: "admin-users" },
        prependIcon: "mdi-account-multiple",
      },
    },
    {
      title: t("nav.teams"),
      value: "/admin/teams",
      props: { to: { name: "admin-teams" }, prependIcon: "mdi-account-group" },
    },
    {
      title: t("nav.documents"),
      value: "/admin/documents",
      props: {
        to: { name: "admin-documents" },
        prependIcon: "mdi-folder-multiple",
      },
    },
    {
      title: t("nav.categories"),
      value: "/admin/categories",
      props: {
        to: { name: "admin-categories" },
        prependIcon: "mdi-shape-plus",
      },
    },
  ]);

  /**
   * The drawer keeps the same chrome in both modes and only swaps its items,
   * so the /admin prefix is what decides which list is showing.
   */
  const links = computed(() =>
    route.path.startsWith("/admin") ? adminLinks.value : mainLinks.value,
  );

  const profileLink = computed(() => ({
    title: t("nav.profile"),
    value: "/profile",
    props: { to: { name: "profile" }, prependIcon: "mdi-account" },
  }));

  return { links, mainLinks, adminLinks, profileLink };
}
