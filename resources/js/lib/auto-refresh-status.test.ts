import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import {
  getAutoRefreshStatus,
  resetAutoRefreshStatusForTests,
  trackBackgroundRefresh,
  useAutoRefreshStatus,
} from "@/lib/auto-refresh-status";

describe("auto-refresh status", () => {
  beforeEach(() => {
    resetAutoRefreshStatusForTests();
  });

  it("starts idle", () => {
    expect(getAutoRefreshStatus()).toBe("idle");
  });

  it("marks refreshing while a tracked request is in flight", () => {
    const options = trackBackgroundRefresh({});

    act(() => {
      options.onStart?.({ id: "poll-1" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("refreshing");

    act(() => {
      options.onSuccess?.({ props: {} } as never);
      options.onFinish?.({ id: "poll-1" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("idle");
  });

  it("records a restrained failed state after a network error and keeps retrying possible", () => {
    const first = trackBackgroundRefresh({});
    const second = trackBackgroundRefresh({});
    const onNetworkError = vi.fn(() => false);

    act(() => {
      first.onStart?.({ id: "poll-1" } as never);
      expect(first.onNetworkError?.(new Error("offline"))).toBe(false);
      first.onFinish?.({ id: "poll-1" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("failed");

    const tracked = trackBackgroundRefresh({ onNetworkError });

    act(() => {
      tracked.onStart?.({ id: "poll-2" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("refreshing");

    act(() => {
      expect(tracked.onNetworkError?.(new Error("still offline"))).toBe(false);
      tracked.onFinish?.({ id: "poll-2" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("failed");
    expect(onNetworkError).toHaveBeenCalledOnce();

    act(() => {
      second.onStart?.({ id: "poll-3" } as never);
      second.onSuccess?.({ props: {} } as never);
      second.onFinish?.({ id: "poll-3" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("idle");
  });

  it("marks failure for HTTP exceptions without requiring a toast", () => {
    const options = trackBackgroundRefresh({});

    act(() => {
      options.onStart?.({ id: "poll-1" } as never);
      options.onHttpException?.({ status: 503, data: null, headers: {} } as never);
      options.onFinish?.({ id: "poll-1" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("failed");
  });

  it("does not treat cancellations as failures", () => {
    const options = trackBackgroundRefresh({});

    act(() => {
      options.onStart?.({ id: "poll-1" } as never);
      options.onCancel?.();
    });

    expect(getAutoRefreshStatus()).toBe("idle");
  });

  it("clears a previous failure after the next successful refresh", () => {
    const failed = trackBackgroundRefresh({});
    const recovered = trackBackgroundRefresh({});

    act(() => {
      failed.onStart?.({ id: "poll-1" } as never);
      failed.onNetworkError?.(new Error("offline"));
      failed.onFinish?.({ id: "poll-1" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("failed");

    act(() => {
      recovered.onStart?.({ id: "poll-2" } as never);
      recovered.onSuccess?.({ props: {} } as never);
      recovered.onFinish?.({ id: "poll-2" } as never);
    });

    expect(getAutoRefreshStatus()).toBe("idle");
  });

  it("exposes status through a React subscription", () => {
    const { result } = renderHook(() => useAutoRefreshStatus());
    const options = trackBackgroundRefresh({});

    expect(result.current).toBe("idle");

    act(() => {
      options.onStart?.({ id: "poll-1" } as never);
    });

    expect(result.current).toBe("refreshing");

    act(() => {
      options.onNetworkError?.(new Error("offline"));
      options.onFinish?.({ id: "poll-1" } as never);
    });

    expect(result.current).toBe("failed");
  });

  it("preserves and composes existing request callbacks", () => {
    const callbacks = {
      onCancelToken: vi.fn(),
      onStart: vi.fn(),
      onSuccess: vi.fn(),
      onError: vi.fn(),
      onNetworkError: vi.fn(() => false),
      onHttpException: vi.fn(() => false),
      onCancel: vi.fn(),
      onFinish: vi.fn(),
    };
    const options = trackBackgroundRefresh({
      only: ["summary"],
      preserveUrl: true,
      showProgress: false,
      ...callbacks,
    });
    const token = { cancel: vi.fn() };
    const visit = { id: "poll-1" };
    const page = { props: {} };
    const errors = { summary: "unavailable" };
    const response = { status: 500, data: null, headers: {} };
    const error = new Error("offline");

    expect(options).toMatchObject({
      only: ["summary"],
      preserveUrl: true,
      showProgress: false,
    });

    options.onCancelToken?.(token as never);
    options.onStart?.(visit as never);
    expect(getAutoRefreshStatus()).toBe("refreshing");

    expect(options.onNetworkError?.(error)).toBe(false);
    options.onFinish?.(visit as never);

    expect(callbacks.onCancelToken).toHaveBeenCalledWith(token);
    expect(callbacks.onStart).toHaveBeenCalledWith(visit);
    expect(callbacks.onNetworkError).toHaveBeenCalledWith(error);
    expect(callbacks.onFinish).toHaveBeenCalledWith(visit);
    expect(getAutoRefreshStatus()).toBe("failed");

    const recovered = trackBackgroundRefresh({ ...callbacks });
    recovered.onStart?.(visit as never);
    recovered.onSuccess?.(page as never);
    recovered.onError?.(errors as never);
    recovered.onHttpException?.(response as never);
    recovered.onCancel?.();
    recovered.onFinish?.(visit as never);

    expect(callbacks.onSuccess).toHaveBeenCalledWith(page);
    expect(callbacks.onError).toHaveBeenCalledWith(errors);
    expect(callbacks.onHttpException).toHaveBeenCalledWith(response);
    expect(callbacks.onCancel).toHaveBeenCalledOnce();
  });
});
