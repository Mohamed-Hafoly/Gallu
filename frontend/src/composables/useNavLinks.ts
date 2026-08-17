import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

export function useNavLinks() {
  const { t } = useI18n();
  const route = useRoute();

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
      title: t("nav.gallery"),
      value: "/gallery",
      props: { to: { name: "gallery" }, prependIcon: "mdi-image-multiple" },
    },
    {
      title: t("nav.settings"),
      value: "/settings",
      props: { to: { name: "settings" }, prependIcon: "mdi-cog" },
    },
    // TODO: only super-admins should see this. The backend has no role column
    // yet (UserResource returns id/name/email), so for now it shows for every
    // signed-in user; filter here once the role reaches the User type.
    {
      title: t("nav.admin"),
      value: "/admin/categories",
      props: {
        to: { name: "admin-categories" },
        prependIcon: "mdi-shield-account",
      },
    },
  ]);

  const adminLinks = computed(() => [
    {
      title: t("nav.backToGallery"),
      value: "/gallery",
      props: { to: { name: "gallery" }, prependIcon: "mdi-arrow-left" },
    },
    {
      title: t("nav.categories"),
      value: "/admin/categories",
      props: { to: { name: "admin-categories" }, prependIcon: "mdi-pentagram" },
    },
    {
      title: t("nav.teams"),
      value: "/admin/teams",
      props: { to: { name: "admin-teams" }, prependIcon: "mdi-account-group" },
    },
    {
      title: t("nav.users"),
      value: "/admin/users",
      props: {
        to: { name: "admin-users" },
        prependIcon: "mdi-account-multiple",
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
