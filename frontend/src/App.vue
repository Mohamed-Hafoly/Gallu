<script lang="ts" setup>
  import TheHeader from "./components/TheHeader.vue";
</script>
<template>
  <v-app>
    <the-header />

    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
