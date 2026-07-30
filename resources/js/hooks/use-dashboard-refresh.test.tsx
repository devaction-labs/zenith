import { act, render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { useDashboardRefresh, usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { getAutoRefreshStatus, resetAutoRefreshStatusForTests } from "@/lib/auto-refresh-status";

const { reload, start, stop, usePoll } = vi.hoisted(() => ({
  reload: vi.fn(),
  start: vi.fn(),
  stop: vi.fn(),
  usePoll: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: { reload },
  usePoll,
}));

function RefreshHarness({
  interval,
  enabled = true,
  props,
}: {
  interval: number;
  enabled?: boolean;
  props?: string[];
}) {
  useDashboardRefresh(interval, enabled, props);

  return null;
}

function PageRefreshHarness({ includeSharedProps }: { includeSharedProps: boolean }) {
  usePageRefresh(5000, ["summary"], true, includeSharedProps);

  return null;
}

describe("useDashboardRefresh", () => {
  beforeEach(() => {
    reload.mockReset();
    start.mockReset();
    stop.mockReset();
    usePoll.mockReset();
    usePoll.mockReturnValue({ start, stop });
    resetAutoRefreshStatusForTests();
  });

  it("polls dashboard props through Inertia at the configured interval", () => {
    render(<RefreshHarness interval={5000} />);

    expect(usePoll).toHaveBeenCalledWith(5000, expect.any(Function), {
      autoStart: false,
      mode: "cancel",
    });
    expect(start).toHaveBeenCalledOnce();
    expect(usePoll.mock.calls[0][1]()).toMatchObject({
      only: ["summary", "workload", "supervisors", "horizon", "navigationCounts"],
      preserveUrl: true,
      showProgress: false,
    });
    expect(usePoll.mock.calls[0][1]().onStart).toEqual(expect.any(Function));
    expect(usePoll.mock.calls[0][1]().onSuccess).toEqual(expect.any(Function));
    expect(usePoll.mock.calls[0][1]().onNetworkError).toEqual(expect.any(Function));
    expect(usePoll.mock.calls[0][1]().onHttpException).toEqual(expect.any(Function));
    expect(usePoll.mock.calls[0][1]().onFinish).toEqual(expect.any(Function));
  });

  it("does not restart or reload an already active poll on a page rerender", () => {
    const { rerender } = render(<RefreshHarness interval={5000} enabled />);

    expect(start).toHaveBeenCalledOnce();
    expect(reload).not.toHaveBeenCalled();

    rerender(<RefreshHarness interval={5000} enabled />);

    expect(start).toHaveBeenCalledOnce();
    expect(reload).not.toHaveBeenCalled();
  });

  it("refreshes an explicit detail-page prop", () => {
    render(<RefreshHarness interval={5000} props={["batch"]} />);

    expect(usePoll.mock.calls[0][1]()).toMatchObject({
      only: ["batch", "horizon", "navigationCounts"],
      preserveUrl: true,
      showProgress: false,
    });
  });

  it("can refresh an independent prop without duplicating shared shell props", () => {
    render(<PageRefreshHarness includeSharedProps={false} />);

    expect(usePoll.mock.calls[0][1]()).toMatchObject({
      only: ["summary"],
      preserveUrl: true,
      showProgress: false,
    });
  });

  it("does not auto-start when automatic refresh is disabled", () => {
    render(<RefreshHarness interval={5000} enabled={false} />);

    expect(usePoll).toHaveBeenCalledWith(5000, expect.any(Function), {
      autoStart: false,
      mode: "cancel",
    });
    expect(start).not.toHaveBeenCalled();
    expect(stop).toHaveBeenCalledOnce();
  });

  it("stops an active poll when automatic refresh is disabled", () => {
    const { rerender } = render(<RefreshHarness interval={5000} enabled />);

    expect(start).toHaveBeenCalledOnce();
    stop.mockClear();

    rerender(<RefreshHarness interval={5000} enabled={false} />);

    expect(start).toHaveBeenCalledOnce();
    expect(stop).toHaveBeenCalled();
  });

  it("refreshes immediately when automatic refresh is enabled", () => {
    const { rerender } = render(<RefreshHarness interval={5000} enabled={false} />);

    expect(reload).not.toHaveBeenCalled();

    rerender(<RefreshHarness interval={5000} enabled />);

    expect(reload).toHaveBeenCalledOnce();
    expect(reload.mock.calls[0][0]).toMatchObject({
      only: ["summary", "workload", "supervisors", "horizon", "navigationCounts"],
      preserveUrl: true,
      showProgress: false,
    });
    expect(reload.mock.calls[0][0].onNetworkError).toEqual(expect.any(Function));
  });

  it("surfaces poll failures and clears them after the next successful refresh", () => {
    render(<RefreshHarness interval={5000} />);

    const createOptions = usePoll.mock.calls[0][1] as () => {
      onStart: (visit: { id: string }) => void;
      onSuccess: (page: { props: Record<string, unknown> }) => void;
      onNetworkError: (error: Error) => void;
      onFinish: (visit: { id: string }) => void;
    };
    const failedOptions = createOptions();

    act(() => {
      failedOptions.onStart({ id: "poll-1" });
      failedOptions.onNetworkError(new Error("offline"));
      failedOptions.onFinish({ id: "poll-1" });
    });

    expect(getAutoRefreshStatus()).toBe("failed");

    const recoveredOptions = createOptions();

    act(() => {
      recoveredOptions.onStart({ id: "poll-2" });
      recoveredOptions.onSuccess({ props: {} });
      recoveredOptions.onFinish({ id: "poll-2" });
    });

    expect(getAutoRefreshStatus()).toBe("idle");
  });
});
