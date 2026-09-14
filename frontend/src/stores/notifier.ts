import { defineStore } from "pinia";
import { ref } from "vue";

export type NotifyTone = "success" | "error";

/**
 * Backs the single app-level snackbar in App.vue, so anything — a page or a
 * dialog buried in the tree — can report an outcome without emitting up.
 */
export const useNotifierStore = defineStore("notifier", () => {
  const visible = ref(false);
  const message = ref("");
  const tone = ref<NotifyTone>("success");

  function notify(text: string, nextTone: NotifyTone = "success") {
    message.value = text;
    tone.value = nextTone;
    visible.value = true;
  }

  return { visible, message, tone, notify };
});
