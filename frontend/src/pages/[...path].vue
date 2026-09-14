<script setup lang="ts">
  import { useI18n } from "vue-i18n";

  /**
   * The catch-all. Any path no other page claims lands here.
   *
   * `App.vue` supplies the app bar unconditionally and the nav drawer to
   * everything but /login and /register, so this page keeps both without asking
   * for them - there is no layout system to opt into.
   *
   * Only reachable while signed in: authGuard allow-lists public routes by name
   * and this one is not among them, so a signed-out visitor is sent to login
   * rather than told the page is missing. Under /admin it is reachable only by
   * a super-admin, since the guard's admin rule runs whether or not the path
   * exists.
   */
  const { t } = useI18n();
</script>

<template>
  <!--
    h-full so the surface fills the space below the app bar rather than stopping
    under the strip: v-main already spans it, and its padding-top is the bar, so
    100% of its content box is exactly the room there is.

    Centred rather than top-aligned, and py-7 rather than pt-7 so the padding
    stays symmetric around it.
  -->
  <v-container
    class=" p-0 flex h-full flex-col justify-center pb-10"
    fluid
  >
    <!--
      The strip: its own surface across the container's full width, so the
      message reads as a band rather than as text floating on the page.
      surface-darken-2 against the container's -darken-3 is what separates them,
      the same pairing ImageDialog and the empty cover cell use.

      text-center rather than the useRtl() alignment the card pages do: the
      strip is centred in both directions, and 404 is a direction-neutral
      numeral, so there is nothing here to flip.
    -->
    <div class="bg-primary-darken-2 w-full py-16 text-center">
      <p class="text-8xl font-bold leading-none text-tertiary">404</p>

      <p class="mt-6 ">{{ t("common.notFound") }}</p>
    </div>
  </v-container>
</template>

<route lang="json">
{
  "name": "not-found"
}
</route>
