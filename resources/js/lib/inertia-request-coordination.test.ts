import type { VisitOptions } from "@inertiajs/core";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import {
  backgroundVisitOptions,
  registerForegroundVisitCancellation,
} from "@/lib/inertia-request-coordination";

const inertia = vi.hoisted(() => ({
  cancelAll: vi.fn(),
  on: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: inertia,
}));

describe("Inertia request coordination", () => {
  beforeEach(() => {
    inertia.cancelAll.mockReset();
    inertia.on.mockReset();
  });

  it("preserves the current URL for background GET reloads", () => {
    const options = { async: true, method: "get", component: null } as VisitOptions;

    expect(backgroundVisitOptions("/horizon/queues/default", options)).toEqual({
      ...options,
      preserveUrl: true,
    });
  });

  it("leaves navigations, prefetches, and explicit URL ownership unchanged", () => {
    const navigation = { async: false, method: "get", component: null } as VisitOptions;
    const instantVisit = { async: true, method: "get", component: "Queues/Show" } as VisitOptions;
    const prefetch = {
      async: true,
      method: "get",
      component: null,
      prefetch: true,
    } as VisitOptions;
    const explicit = {
      async: true,
      method: "get",
      component: null,
      preserveUrl: false,
    } as VisitOptions;

    expect(backgroundVisitOptions("/horizon", navigation)).toBe(navigation);
    expect(backgroundVisitOptions("/horizon", instantVisit)).toBe(instantVisit);
    expect(backgroundVisitOptions("/horizon", prefetch)).toBe(prefetch);
    expect(backgroundVisitOptions("/horizon", explicit)).toBe(explicit);
  });

  it("cancels active poll requests when a foreground visit starts", () => {
    inertia.on.mockReturnValue(vi.fn());
    const cancel = vi.fn();
    const options = backgroundVisitOptions("/horizon/jobs/pending", {
      async: true,
      method: "get",
      component: null,
      poll: true,
    } as VisitOptions & { poll: boolean });

    options.onCancelToken?.({ cancel });

    registerForegroundVisitCancellation();

    expect(inertia.on).toHaveBeenCalledWith("before", expect.any(Function));

    const listener = inertia.on.mock.calls[0][1];
    listener({ detail: { visit: { prefetch: false } } });

    expect(cancel).toHaveBeenCalledOnce();
    expect(inertia.cancelAll).not.toHaveBeenCalled();
  });

  it("does not cancel Inertia deferred requests for same-page foreground visits", () => {
    inertia.on.mockReturnValue(vi.fn());
    const deferredCancel = vi.fn();
    const options = backgroundVisitOptions("/horizon/jobs/pending", {
      async: true,
      component: null,
      deferredProps: true,
      method: "get",
    } as VisitOptions & { deferredProps: boolean });

    options.onCancelToken?.({ cancel: deferredCancel });

    registerForegroundVisitCancellation();

    const listener = inertia.on.mock.calls[0][1];
    listener({ detail: { visit: { async: false, prefetch: false } } });

    expect(deferredCancel).not.toHaveBeenCalled();
    expect(inertia.cancelAll).not.toHaveBeenCalled();
  });

  it("does not cancel an active poll for asynchronous or prefetch visits", () => {
    inertia.on.mockReturnValue(vi.fn());
    const cancel = vi.fn();
    const options = backgroundVisitOptions("/horizon/jobs/pending", {
      async: true,
      method: "get",
      component: null,
      poll: true,
    } as VisitOptions & { poll: boolean });

    options.onCancelToken?.({ cancel });
    registerForegroundVisitCancellation();

    const listener = inertia.on.mock.calls[0][1];
    listener({ detail: { visit: { async: true, prefetch: false } } });
    listener({ detail: { visit: { prefetch: true } } });

    expect(cancel).not.toHaveBeenCalled();
    expect(inertia.cancelAll).not.toHaveBeenCalled();
  });

  it("removes finished polls from foreground cancellation", () => {
    inertia.on.mockReturnValue(vi.fn());
    const cancel = vi.fn();
    const onFinish = vi.fn();
    const options = backgroundVisitOptions("/horizon/jobs/pending", {
      async: true,
      method: "get",
      component: null,
      poll: true,
      onFinish,
    } as VisitOptions & { poll: boolean });

    options.onCancelToken?.({ cancel });
    options.onFinish?.({} as never);
    registerForegroundVisitCancellation();

    const listener = inertia.on.mock.calls[0][1];
    listener({ detail: { visit: { async: false, prefetch: false } } });

    expect(onFinish).toHaveBeenCalledOnce();
    expect(cancel).not.toHaveBeenCalled();
    expect(inertia.cancelAll).not.toHaveBeenCalled();
  });
});
