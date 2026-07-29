<script setup lang="ts">
  import { useNavLinks } from "@/composables/useNavLinks";
  import api from "@/plugins/axios";

  const { navLinks } = useNavLinks();

  const drawer = defineModel<boolean | null>();

  async function checkHealth() {
    const { data } = await api.get("/up");
    return data;
  }
  async function test() {
    try {
      await checkHealth();
      console.log("API is up");
    } catch (error) {
      console.error("API is down or unreachable", error);
    }
  }
</script>

<template>
  <v-navigation-drawer v-model="drawer" location="start">
    <v-list
      class="[&_.v-list-item-title]:tracking-wider"
      color="tertiary"
      :items="navLinks"
      mandatory
      nav
      rounded
    />

    <v-btn @click="test">test api</v-btn>
  </v-navigation-drawer>
</template>
