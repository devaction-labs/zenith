import { act, renderHook } from "@testing-library/react";
import { mergeDataIntoQueryString } from "@inertiajs/core";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAutoLoad } from "@/hooks/use-auto-load";
import { resetAutoRefreshStatusForTests } from "@/lib/auto-refresh-status";

const inertia = vi.hoisted(() => ({
  reload: vi.fn(),
  start: vi.fn(),
  stop: vi.fn(),
  usePoll: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: { reload: inertia.reload },
  usePoll: inertia.usePoll,
}));

const trackBackgroundKeys = [
  "onCancelToken",
  "onStart",
  "onSuccess",
  "onError",
  "onNetworkError",
  "onHttpException",
  "onCancel",
  "onFinish",
] as const;

describe("useAutoLoad", () => {
  beforeEach(() => {
    resetAutoRefreshStatusForTests();
    inertia.reload.mockReset();
    inertia.start.mockReset();
    inertia.stop.mockReset();
    inertia.usePoll.mockReset();
    inertia.usePoll.mockReturnValue({ start: inertia.start, stop: inertia.stop });
    window.history.replaceState({}, "", "/horizon/failed?starting_at=49&tag=tenant%3A42");
  });

  it("polls a fresh partial reload without carrying the scroll cursor", () => {
    renderHook(() =>
      useAutoLoad({ enabled: true, prop: "jobs", interval: 1_000, loadedItemCount: 10 }),
    );

    expect(inertia.usePoll).toHaveBeenCalledWith(1_000, expect.any(Function), {
      autoStart: false,
      mode: "cancel",
    });
    const options = inertia.usePoll.mock.calls[0][1]();
    const [url] = mergeDataIntoQueryString("get", window.location.href, options.data);

    expect(url).toBe("http://localhost:3000/horizon/failed?tag=tenant%3A42");
    expect(options).toEqual(
      expect.objectContaining({
        data: { starting_at: undefined },
        only: ["jobs", "horizon", "navigationCounts"],
        preserveUrl: true,
        reset: ["jobs"],
        showProgress: false,
      }),
    );
    for (const key of trackBackgroundKeys) {
      expect(options[key]).toEqual(expect.any(Function));
    }
    expect(inertia.start).toHaveBeenCalledOnce();
  });

  it("polls only freshness props while automatic insertion is disabled", () => {
    renderHook(() =>
      useAutoLoad({
        enabled: false,
        prop: "jobs",
        additionalProps: ["summary"],
        listRevision: "rev-1",
        interval: 1_000,
        loadedItemCount: 10,
      }),
    );

    expect(inertia.start).toHaveBeenCalledOnce();
    expect(inertia.usePoll.mock.calls[0][1]()).toEqual(
      expect.objectContaining({
        data: { starting_at: undefined },
        only: ["listRevision", "summary"],
        preserveUrl: true,
        showProgress: false,
      }),
    );
    expect(inertia.usePoll.mock.calls[0][1]().reset).toBeUndefined();
  });

  it("refreshes the active list immediately when automatic insertion is enabled", () => {
    const { rerender } = renderHook(
      ({ enabled }) =>
        useAutoLoad({
          enabled,
          prop: "jobs",
          additionalProps: ["summary"],
          interval: 1_000,
          loadedItemCount: 10,
        }),
      { initialProps: { enabled: false } },
    );

    expect(inertia.reload).not.toHaveBeenCalled();

    rerender({ enabled: true });

    expect(inertia.reload).toHaveBeenCalledOnce();
    expect(inertia.reload).toHaveBeenCalledWith(
      expect.objectContaining({
        data: { starting_at: undefined },
        only: ["jobs", "summary", "horizon", "navigationCounts"],
        preserveUrl: true,
        reset: ["jobs"],
        showProgress: false,
      }),
    );
  });

  it("holds new entries until requested when automatic insertion is disabled", () => {
    const { result, rerender } = renderHook(
      ({ listRevision }) =>
        useAutoLoad({
          enabled: false,
          prop: "jobs",
          interval: 1_000,
          listRevision,
          loadedItemCount: 10,
        }),
      { initialProps: { listRevision: "rev-1" } },
    );

    expect(result.current.hasNewEntries).toBe(false);

    rerender({ listRevision: "rev-2" });

    expect(result.current.hasNewEntries).toBe(true);

    act(() => {
      result.current.loadNewEntries();
    });

    expect(inertia.reload).toHaveBeenCalledOnce();
    expect(inertia.reload).toHaveBeenCalledWith(
      expect.objectContaining({
        only: ["jobs", "listRevision"],
        reset: ["jobs"],
      }),
    );

    const options = inertia.reload.mock.calls[0][0];
    act(() => {
      options.onSuccess?.({ props: { listRevision: "rev-2" } });
    });

    expect(result.current.hasNewEntries).toBe(false);
  });

  it("prefaces infinite-scroll history with prepend once more than one page is loaded", () => {
    renderHook(() =>
      useAutoLoad({
        enabled: true,
        prop: "jobs",
        interval: 1_000,
        loadedItemCount: 75,
      }),
    );

    expect(inertia.usePoll.mock.calls[0][1]()).toEqual(
      expect.objectContaining({
        only: ["jobs", "horizon", "navigationCounts"],
        headers: { "X-Inertia-Infinite-Scroll-Merge-Intent": "prepend" },
      }),
    );
    expect(inertia.usePoll.mock.calls[0][1]().reset).toBeUndefined();
  });

  it("clears the new-entries flag when the list scope changes", () => {
    const { result, rerender } = renderHook(
      ({ listRevision, scope }) =>
        useAutoLoad({
          enabled: false,
          prop: "jobs",
          interval: 1_000,
          listRevision,
          scope,
          loadedItemCount: 10,
        }),
      { initialProps: { listRevision: "rev-1", scope: "all" } },
    );

    rerender({ listRevision: "rev-2", scope: "all" });
    expect(result.current.hasNewEntries).toBe(true);

    rerender({ listRevision: "rev-3", scope: "tenant:42" });
    expect(result.current.hasNewEntries).toBe(false);
  });

  it("stops native polling when polling is disabled or the hook unmounts", () => {
    const { unmount } = renderHook(() =>
      useAutoLoad({
        enabled: true,
        prop: "jobs",
        interval: 1_000,
        polling: false,
        loadedItemCount: 10,
      }),
    );

    expect(inertia.start).not.toHaveBeenCalled();
    expect(inertia.stop).toHaveBeenCalledOnce();

    unmount();

    expect(inertia.stop).toHaveBeenCalledTimes(2);
  });

  it("removes a custom infinite-scroll cursor before replacing batches", () => {
    window.history.replaceState({}, "", "/horizon/batches?before_id=batch-50&query=import");
    renderHook(() =>
      useAutoLoad({
        enabled: true,
        prop: "batches",
        interval: 1_000,
        cursor: "before_id",
        loadedItemCount: 10,
      }),
    );

    const options = inertia.usePoll.mock.calls[0][1]();
    const [url] = mergeDataIntoQueryString("get", window.location.href, options.data);

    expect(url).toBe("http://localhost:3000/horizon/batches?query=import");
    expect(options).toEqual(
      expect.objectContaining({
        data: { before_id: undefined },
        only: ["batches", "horizon", "navigationCounts"],
        reset: ["batches"],
      }),
    );
  });
});
