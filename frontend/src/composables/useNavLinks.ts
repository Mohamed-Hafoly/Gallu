import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "@/stores/auth";

export function useNavLinks() {
  const { t } = useI18n();
  const authStore = useAuthStore();

  const navLinks = computed(() => [
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
      title: t("nav.projects"),
      value: "/projects",
      props: { to: { name: "projects" }, prependIcon: "mdi-bookshelf" },
    },
    {
      title: t("nav.profile"),
      value: "/profile",
      props: { to: { name: "profile" }, prependIcon: "mdi-account" },
    },
    {
      title: t("nav.settings"),
      value: "/settings",
      props: { to: { name: "settings" }, prependIcon: "mdi-cog" },
    },
    {
      title: t("nav.logout"),
      value: "logout",
      props: {
        prependIcon: "mdi-logout",
        class: "bg-tertiary mt-10 text-on-tertiary",
        color: "on-tertiary",
        onClick: () => authStore.logout(),
      },
    },
  ]);

  return { navLinks };
}
