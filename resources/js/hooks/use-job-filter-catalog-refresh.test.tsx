import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { useJobFilterCatalogRefresh } from "@/hooks/use-job-filter-catalog-refresh";

const reload = vi.hoisted(() => vi.fn());

vi.mock("@inertiajs/react", () => ({
  router: { reload },
}));

describe("useJobFilterCatalogRefresh", () => {
  beforeEach(() => {
    reload.mockReset();
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-07-28T10:00:00Z"));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("refreshes only the filter catalog and coalesces nearby intent", () => {
    const { result } = renderHook(() => useJobFilterCatalogRefresh(5_000, undefined));

    act(() => {
      result.current();
      result.current();
    });

    expect(reload).toHaveBeenCalledOnce();
    const options = reload.mock.calls[0][0];

    expect(options).toMatchObject({
      only: ["filterCatalog"],
      preserveUrl: true,
      showProgress: false,
    });
    expect(options.onCancelToken).toEqual(expect.any(Function));
    expect(options.onSuccess).toEqual(expect.any(Function));
    expect(options.onFinish).toEqual(expect.any(Function));

    act(() => {
      options.onSuccess();
    });
    act(() => {
      options.onFinish();
    });
    act(() => {
      result.current();
    });

    expect(reload).toHaveBeenCalledOnce();

    act(() => {
      vi.advanceTimersByTime(5_000);
    });
    act(() => {
      result.current();
    });

    expect(reload).toHaveBeenCalledTimes(2);
  });

  it("treats an existing catalog as fresh for the configured interval", () => {
    const { result } = renderHook(() =>
      useJobFilterCatalogRefresh(5_000, {
        available: true,
        jobs: [],
        queues: [],
        connections: [],
        message: null,
      }),
    );

    act(() => {
      result.current();
    });
    expect(reload).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(5_000);
    });
    act(() => {
      result.current();
    });

    expect(reload).toHaveBeenCalledOnce();
  });

  it("retries after an unsuccessful request finishes", () => {
    const { result } = renderHook(() => useJobFilterCatalogRefresh(5_000, undefined));

    act(() => {
      result.current();
    });
    const options = reload.mock.calls[0][0];
    act(() => {
      options.onFinish();
    });
    act(() => {
      result.current();
    });

    expect(reload).toHaveBeenCalledTimes(2);
  });

  it("cancels an in-flight catalog request when the page unmounts", () => {
    const cancel = vi.fn();
    const { result, unmount } = renderHook(() => useJobFilterCatalogRefresh(5_000, undefined));

    act(() => {
      result.current();
    });
    const options = reload.mock.calls[0][0];
    act(() => {
      options.onCancelToken({ cancel });
    });
    unmount();

    expect(cancel).toHaveBeenCalledOnce();
  });
});
