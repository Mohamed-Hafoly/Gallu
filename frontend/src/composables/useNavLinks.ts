import { computed } from "vue";
import { useI18n } from "vue-i18n";

export function useNavLinks() {
  const { t } = useI18n();

  const navLinks = computed(() => [
    {
      title: t("nav.home"),
      value: "/",
      props: { to: "/", prependIcon: "mdi-home", class: "tracking-wider" },
    },
    {
      title: t("nav.projects"),
      value: "/projects",
      props: { to: "/projects", prependIcon: "mdi-bookshelf" },
    },
    {
      title: t("nav.profile"),
      value: "/profile",
      props: { to: "/profile", prependIcon: "mdi-account" },
    },
    {
      title: t("nav.settings"),
      value: "/settings",
      props: { to: "/settings", prependIcon: "mdi-cog" },
    },
    {
      title: t("nav.logout"),
      value: "logout",
      props: {
        prependIcon: "mdi-logout",
        onClick: () => console.log("logout"),
      },
    },
  ]);

  return { navLinks };
}
