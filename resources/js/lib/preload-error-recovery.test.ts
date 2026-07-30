import { describe, expect, it, vi } from "vite-plus/test";

import { recoverFromPreloadError } from "@/lib/preload-error-recovery";

describe("recoverFromPreloadError", () => {
  it("prevents the stale chunk error and reloads the current page", () => {
    const event = new Event("vite:preloadError", { cancelable: true });
    const reload = vi.fn();

    recoverFromPreloadError(event, reload);

    expect(event.defaultPrevented).toBe(true);
    expect(reload).toHaveBeenCalledOnce();
  });
});
