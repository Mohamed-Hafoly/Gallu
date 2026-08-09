<script setup lang="ts">
  import { computed, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useNavLinks } from "@/composables/useNavLinks";
  import { useAuthStore } from "@/stores/auth";

  const { profileLink } = useNavLinks();
  const { t } = useI18n();
  const authStore = useAuthStore();

  const name = computed(() => authStore.user?.name ?? "");
  const email = computed(() => authStore.user?.email ?? "");

  const menu = ref(false);

  function logout() {
    menu.value = false;
    authStore.logout();
  }
</script>

<template>
  <div class="text-center">
    <v-menu v-model="menu" location="end">
      <template #activator="{ props }">
        <v-card
          append-icon="mdi-dots-vertical"
          class="mx-auto"
          prepend-icon="mdi-account"
          :subtitle="email"
          :title="name"
          v-bind="props"
        >
        </v-card>
      </template>

      <v-card min-width="300">
        <v-list>
          <v-list-item
            prepend-icon="mdi-account"
            :subtitle="email"
            :title="name"
          >
          </v-list-item>
        </v-list>

        <v-divider></v-divider>

        <v-list nav rounded>
          <v-list-item
            v-bind="profileLink.props"
            :title="profileLink.title"
            @click="menu = false"
          />
        </v-list>

        <v-card-actions>
          <v-btn
            block
            class="bg-tertiary text-on-tertiary"
            prepend-icon="mdi-logout"
            @click="logout()"
          >
            {{ t("auth.logout") }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-menu>
  </div>
</template>
