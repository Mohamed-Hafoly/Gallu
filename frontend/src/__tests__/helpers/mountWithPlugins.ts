import type { ComponentMountingOptions } from "@vue/test-utils";
import { createTestingPinia } from "@pinia/testing";
import { mount } from "@vue/test-utils";
import { vi } from "vitest";
import i18n from "@/plugins/i18n";
import vuetify from "@/plugins/vuetify";

/**
 * Mounts a component with the app's real Vuetify and i18n plugins, so specs
 * assert against the same theme/locale/defaults the app actually uses.
 *
 * Vuetify's overlay components teleport to `document.body`, so anything inside
 * a dialog must be queried there rather than through the wrapper.
 */
export function mountWithPlugins<T>(
  component: T,
  options: ComponentMountingOptions<T> = {},
  /** Seeds Pinia for components that read a store during setup. */
  initialState?: Record<string, unknown>,
) {
  return mount(component, {
    ...options,
    global: {
      ...options.global,
      plugins: [
        vuetify,
        i18n,
        createTestingPinia({ createSpy: vi.fn, initialState }),
        ...(options.global?.plugins ?? []),
      ],
    },
  });
}
