import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vite-plus/test";

globalThis.ResizeObserver = class ResizeObserver {
  observe() {}

  unobserve() {}

  disconnect() {}
};

// Node ships its own `localStorage`/`sessionStorage` globals. Vitest's jsdom environment
// only assigns a global when it is not already present on `globalThis`, so it never
// installs jsdom's Storage there, and Node's own implementation is a non-functional stub
// without `--localstorage-file`. jsdom's real Storage instance is still reachable through
// `globalThis.jsdom`, which the jsdom environment sets up for every test; use it instead.
const jsdomGlobal = (globalThis as { jsdom?: { window: Window } }).jsdom;

if (jsdomGlobal) {
  Object.defineProperty(globalThis, "localStorage", {
    configurable: true,
    value: jsdomGlobal.window.localStorage,
  });
  Object.defineProperty(globalThis, "sessionStorage", {
    configurable: true,
    value: jsdomGlobal.window.sessionStorage,
  });
}

afterEach(cleanup);
