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
  const avatarUrl = computed(() => authStore.user?.avatar_thumb_url ?? "");

  const menu = ref(false);

  function logout() {
    menu.value = false;
    authStore.logout();
  }
</script>

<template>
  <div class="text-center">
    <v-menu v-model="menu" location="start" transition="scale-transition">
      <template #activator="{ props }">
        <v-card class="mx-2" rounded="xl" variant="text" v-bind="props">
          <v-card-item class="p-2">
            <template #prepend>
              <v-avatar size="40">
                <v-img :alt="name" cover :src="avatarUrl" />
              </v-avatar>
            </template>

            <template #title>
              <p class="text-base text-start">
                {{ name }}
              </p>
            </template>

            <template #subtitle>
              <p class="text-sm text-start">
                {{ email }}
              </p>
            </template>

            <template #append>
              <v-icon icon="mdi-dots-vertical" size="20" />
            </template>
          </v-card-item>
        </v-card>
      </template>

      <v-card min-width="300">
        <v-list>
          <v-list-item :subtitle="email" :title="name">
            <template #prepend>
              <v-avatar size="40">
                <v-img :alt="name" cover :src="avatarUrl" />
              </v-avatar>
            </template>
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
