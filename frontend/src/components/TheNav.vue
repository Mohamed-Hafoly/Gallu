<script setup lang="ts">
  import { useNavLinks } from "@/composables/useNavLinks";
  import TheProfileMenu from "./TheProfileMenu.vue";

  const { links } = useNavLinks();

  const drawer = defineModel<boolean | null>();
</script>

<template>
  <v-navigation-drawer v-model="drawer" location="start">
    <div class="flex flex-col h-full">
      <v-list
        class="[&_.v-list-item-title]:tracking-wider"
        color="tertiary"
        :items="links"
        mandatory
        nav
        rounded
      />

      <the-profile-menu class="mt-auto pb-2 " />
    </div>
  </v-navigation-drawer>
</template>
