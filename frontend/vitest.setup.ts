import { vi } from "vitest";

// jsdom ships neither of these, and Vuetify's layout/display code needs both.
globalThis.ResizeObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
} as never;

// jsdom ships no IntersectionObserver either, and useInfiniteScroll builds one
// on mount, so any spec rendering the document page's image feed needs it. The
// stub never fires — specs that exercise paging call the observed callback
// themselves.
globalThis.IntersectionObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
  takeRecords() {
    return [];
  }
} as never;

// Needed by Vuetify's overlay location strategies, so any spec that mounts a
// v-dialog or v-menu depends on it.
Object.defineProperty(globalThis, "visualViewport", {
  writable: true,
  value: {
    width: 1024,
    height: 768,
    scale: 1,
    offsetLeft: 0,
    offsetTop: 0,
    pageLeft: 0,
    pageTop: 0,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  },
});

Object.defineProperty(globalThis, "matchMedia", {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
  })),
});
