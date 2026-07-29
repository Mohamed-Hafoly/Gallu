<script setup lang="ts">
  import { useI18n } from "vue-i18n";

  const { locale } = useI18n();

  const locales = [
    { title: "English", value: "en" },
    { title: "العربية", value: "ar" },
  ];

  function setLocale(code: string) {
    locale.value = code;
  }

  defineProps<{ showNavIcon?: boolean }>();
  const emit = defineEmits<{ "toggle-drawer": [] }>();
</script>

<template>
  <v-app-bar class="px-3 border-b border-b-tertiary" scroll-behavior="elevate">
    <template v-if="showNavIcon" #prepend>
      <v-app-bar-nav-icon @click.stop="emit('toggle-drawer')" />
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
</template>
