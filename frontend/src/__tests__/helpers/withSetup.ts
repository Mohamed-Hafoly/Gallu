import { createApp } from "vue";
import i18n from "@/plugins/i18n";

/**
 * Runs a composable inside a real component instance.
 *
 * `app.runWithContext()` is not enough here: vue-i18n's `useI18n()` asserts it
 * was called at the top of a `setup()` function, so it needs an actual instance
 * rather than just an injection context. The probe component is left mounted —
 * unmounting would dispose the composer that the returned `t` is bound to.
 */
export function withSetup<T>(composable: () => T): T {
  let result!: T;

  const app = createApp({
    setup() {
      result = composable();
      return () => null;
    },
  });
  app.use(i18n);
  app.mount(document.createElement("div"));

  return result;
}
