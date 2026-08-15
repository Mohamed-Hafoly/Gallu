<script setup lang="ts">
  import { computed, ref } from "vue";
  import { useRoute } from "vue-router";
  import { useDisplay } from "vuetify";

  const { mobile } = useDisplay();
  const drawer = ref<boolean | null>(null);

  const route = useRoute();
  const isAuthRoute = computed(() =>
    ["/login", "/register"].includes(route.path),
  );
</script>

<template>
  <v-app>
    <the-app-bar :show-nav-icon="mobile" @toggle-drawer="drawer = !drawer" />

    <template v-if="!isAuthRoute">
      <the-nav v-model="drawer" />
    </template>

    <v-main>
      <div
        id="main-scroll"
        class="h-[calc(100dvh-var(--v-layout-top,0px))] overflow-y-auto"
      >
        <router-view />
      </div>
    </v-main>
  </v-app>
</template>
