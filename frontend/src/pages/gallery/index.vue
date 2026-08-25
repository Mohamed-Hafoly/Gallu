<script setup lang="ts">
  /**
   * The flat, cross-document view of every image the caller may see.
   *
   * Read-only by design: uploading needs a document to attach to, and this page
   * spans all of them, so the shared component hides its Upload button when no
   * documentId is passed. Images are created from /documents/[id].
   */
</script>

<template>
  <v-container class="pt-3" fluid>
    <ImageGallery />
  </v-container>
</template>

<route lang="json">
{
  "name": "gallery"
}
</route>
