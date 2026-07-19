<script setup lang="ts">
  import { ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useDisplay } from "vuetify";
  import { useNavLinks } from "@/composables/useNavLinks";
  import api from "@/plugins/axios";

  const { locale } = useI18n();
  const { navLinks } = useNavLinks();

  const locales = [
    { title: "English", value: "en" },
    { title: "العربية", value: "ar" },
  ];

  function setLocale(code: string) {
    locale.value = code;
  }

  const { mobile } = useDisplay();
  const drawer = ref<boolean | null>(null);

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
  <v-app-bar class="px-3 border-b border-b-tertiary" scroll-behavior="elevate">
    <template v-if="mobile" #prepend>
      <v-app-bar-nav-icon @click.stop="drawer = !drawer"></v-app-bar-nav-icon>
    </template>

    <v-app-bar-title>Samoona</v-app-bar-title>

    <template #append>
      <v-menu>
        <template #activator="{ props }">
          <v-btn
            v-bind="props"
            append-icon="mdi-chevron-down"
            color="on-surface"
            variant="text"
          >
            {{ locales.find((l) => l.value === locale)?.title }}
          </v-btn>
        </template>

        <v-list rounded="b-xl">
          <v-list-item
            v-for="localeOption in locales"
            :key="localeOption.value"
            :value="localeOption.value"
            @click="setLocale(localeOption.value)"
          >
            <v-list-item-title>{{ localeOption.title }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>
    </template>
  </v-app-bar>

  <v-navigation-drawer v-model="drawer" location="start">
    <v-list
      class="[&_.v-list-item-title]:tracking-wider"
      color="tertiary"
      :items="navLinks"
      mandatory
      nav
      rounded
    />

    <v-btn @click="test"> test api </v-btn>
  </v-navigation-drawer>
</template>
