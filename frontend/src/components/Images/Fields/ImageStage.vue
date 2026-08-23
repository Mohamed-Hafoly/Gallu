<script setup lang="ts">
  defineProps<{
    src: string;
    alt: string;
  }>();
</script>

<template>
  <!--
    `contain` on a fixed-ratio black stage: a large image scales down to the
    dialog width, a portrait one keeps the stage width and gets black bars
    down the sides. The grid card uses `cover` instead, which crops.
  -->
  <div class="bg-black">
    <v-img :alt="alt" :aspect-ratio="3 / 2" contain :src="src">
      <template #placeholder>
        <div class="flex items-center justify-center h-full">
          <v-progress-circular indeterminate />
        </div>
      </template>
    </v-img>
  </div>
</template>
