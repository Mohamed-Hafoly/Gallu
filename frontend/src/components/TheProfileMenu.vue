<script setup lang="ts">
  import { computed, ref } from "vue";
  import { useI18n } from "vue-i18n";
  import { useEmailFormat } from "@/composables/useEmailFormat";
  import { useNavLinks } from "@/composables/useNavLinks";
  import { useAuthStore } from "@/stores/auth";

  const { profileLink } = useNavLinks();
  const { t } = useI18n();
  const { truncateEmail } = useEmailFormat();
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
              <p
                class="text-base text-start truncate max-w-[18ch]"
                dir="auto"
                :title="name"
              >
                {{ name }}
              </p>
            </template>

            <template #subtitle>
              <p
                class="text-sm text-start truncate max-w-[18ch]"
                dir="auto"
                :title="email"
              >
                {{ truncateEmail(email, 18) }}
              </p>
            </template>

            <template #append>
              <v-icon icon="mdi-dots-vertical" size="20" />
            </template>
          </v-card-item>
        </v-card>
      </template>

      <v-card class="py-2" min-width="300">
        <v-list>
          <v-list-item>
            <template #prepend>
              <v-avatar size="40">
                <v-img :alt="name" cover :src="avatarUrl" />
              </v-avatar>
            </template>

            <template #title>
              <p
                class="text-start truncate max-w-[22ch]"
                dir="auto"
                :title="name"
              >
                {{ name }}
              </p>
            </template>

            <template #subtitle>
              <p class="text-start truncate" :title="email">
                {{ truncateEmail(email, 22) }}
              </p>
            </template>
          </v-list-item>
        </v-list>

        <v-divider></v-divider>

        <v-list nav rounded>
          <v-list-item
            class="[--v-list-prepend-gap:14px]"
            slim
            v-bind="profileLink.props"
          >
            <template #title>
              <span class="tracking-wider">{{ profileLink.title }}</span>
            </template>
          </v-list-item>
        </v-list>

        <v-card-actions>
          <v-btn
            block
            color="tertiary"
            prepend-icon="mdi-logout"
            @click="logout()"
          >
            <template #loader>
              <!-- on-tertiary, not the tertiary default: this button is
                   color="tertiary", so an inherited spinner would be
                   invisible against its own fill. -->
              <v-progress-circular color="on-tertiary" indeterminate />
            </template>
            {{ t("auth.logout") }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-menu>
  </div>
</template>
