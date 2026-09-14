<script setup lang="ts">
  import { computed, ref } from "vue";
  import { useRoute } from "vue-router";
  import { useDisplay } from "vuetify";
  import { useNotifierStore } from "@/stores/notifier";

  const notifier = useNotifierStore();
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

    <v-main class="bg-surface-darken-3">
      <router-view />
    </v-main>

    <!-- The one snackbar for the whole app; anything can raise it through the
         notifier store rather than emitting up to a page. -->
    <v-snackbar
      v-model="notifier.visible"
      :color="notifier.tone"
      :timeout="4000"
    >
      {{ notifier.message }}
    </v-snackbar>
  </v-app>
</template>
