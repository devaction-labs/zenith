import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { useDashboardRefresh, usePageRefresh } from "@/hooks/use-dashboard-refresh";
import { RefreshRateProvider, useRefreshRatePreference } from "@/hooks/use-refresh-rate";
import { getAutoRefreshStatus, resetAutoRefreshStatusForTests } from "@/lib/auto-refresh-status";

const { reload, poll, destroy } = vi.hoisted(() => ({
  reload: vi.fn(),
  poll: vi.fn(),
  destroy: vi.fn(),
}));

vi.mock("@inertiajs/react", () => ({
  router: { reload, poll },
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

function RefreshRateSelectorHarness() {
  const preference = useRefreshRatePreference();
  useDashboardRefresh(5000, true);

  return (
    <button type="button" onClick={() => preference?.setRefreshRateMs(1000)}>
      Use 1s
    </button>
  );
}

describe("useDashboardRefresh", () => {
  beforeEach(() => {
    reload.mockReset();
    poll.mockReset();
    destroy.mockReset();
    poll.mockReturnValue({ start: vi.fn(), stop: vi.fn(), destroy });
    resetAutoRefreshStatusForTests();
  });

  it("polls dashboard props through Inertia at the configured interval", () => {
    render(<RefreshHarness interval={5000} />);

    expect(poll).toHaveBeenCalledWith(5000, expect.any(Function), { mode: "cancel" });
    expect(reload).not.toHaveBeenCalled();
    expect(poll.mock.calls[0][1]()).toMatchObject({
      only: ["summary", "workload", "supervisors", "horizon", "navigationCounts"],
      preserveUrl: true,
      showProgress: false,
    });
    expect(poll.mock.calls[0][1]().onStart).toEqual(expect.any(Function));
    expect(poll.mock.calls[0][1]().onSuccess).toEqual(expect.any(Function));
    expect(poll.mock.calls[0][1]().onNetworkError).toEqual(expect.any(Function));
    expect(poll.mock.calls[0][1]().onHttpException).toEqual(expect.any(Function));
    expect(poll.mock.calls[0][1]().onFinish).toEqual(expect.any(Function));
  });

  it("does not restart or reload an already active poll on a page rerender", () => {
    const { rerender } = render(<RefreshHarness interval={5000} enabled />);

    expect(poll).toHaveBeenCalledOnce();
    expect(reload).not.toHaveBeenCalled();

    rerender(<RefreshHarness interval={5000} enabled />);

    expect(poll).toHaveBeenCalledOnce();
    expect(reload).not.toHaveBeenCalled();
  });

  it("refreshes an explicit detail-page prop", () => {
    render(<RefreshHarness interval={5000} props={["batch"]} />);

    expect(poll.mock.calls[0][1]()).toMatchObject({
      only: ["batch", "horizon", "navigationCounts"],
      preserveUrl: true,
      showProgress: false,
    });
  });

  it("can refresh an independent prop without duplicating shared shell props", () => {
    render(<PageRefreshHarness includeSharedProps={false} />);

    expect(poll.mock.calls[0][1]()).toMatchObject({
      only: ["summary"],
      preserveUrl: true,
      showProgress: false,
    });
  });

  it("does not auto-start when automatic refresh is disabled", () => {
    render(<RefreshHarness interval={5000} enabled={false} />);

    expect(poll).not.toHaveBeenCalled();
  });

  it("stops an active poll when automatic refresh is disabled", () => {
    const { rerender } = render(<RefreshHarness interval={5000} enabled />);

    expect(poll).toHaveBeenCalledOnce();
    expect(destroy).not.toHaveBeenCalled();

    rerender(<RefreshHarness interval={5000} enabled={false} />);

    expect(destroy).toHaveBeenCalledOnce();
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

    const createOptions = poll.mock.calls[0][1] as () => {
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

  it("switches the live poll interval as soon as the shared refresh-rate preference changes", () => {
    render(
      <RefreshRateProvider defaultIntervalMs={5000}>
        <RefreshRateSelectorHarness />
      </RefreshRateProvider>,
    );

    expect(poll).toHaveBeenLastCalledWith(5000, expect.any(Function), { mode: "cancel" });

    act(() => {
      screen.getByRole("button", { name: "Use 1s" }).click();
    });

    expect(destroy).toHaveBeenCalledOnce();
    expect(poll).toHaveBeenLastCalledWith(1000, expect.any(Function), { mode: "cancel" });
  });
});
