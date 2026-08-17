<script setup lang="ts">
  import { useRoute } from "vue-router";

  const route = useRoute();
</script>

<template>
  <p>{{ route.name }}</p>
</template>

<route lang="json">
{
  "name": "admin-teams"
}
</route>
