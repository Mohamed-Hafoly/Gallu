import { computed } from "vue";
import { useI18n } from "vue-i18n";

export function useNavLinks() {
  const { t } = useI18n();

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
  ]);

  const profileLink = computed(() => ({
    title: t("nav.profile"),
    value: "/profile",
    props: { to: { name: "profile" }, prependIcon: "mdi-account" },
  }));

  return { mainLinks, profileLink };
}
