import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";

import { PageHeader } from "@/components/shell/page-header";
import { TooltipProvider } from "@/components/ui/tooltip";
import { SidebarProvider } from "@/components/ui/sidebar";
import { RefreshRateProvider } from "@/hooks/use-refresh-rate";
import { resetAutoRefreshStatusForTests, trackBackgroundRefresh } from "@/lib/auto-refresh-status";

function renderHeader({
  autoLoad = false,
  defaultIntervalMs = 5000,
  children,
}: { autoLoad?: boolean; defaultIntervalMs?: number; children?: ReactNode } = {}) {
  return render(
    <TooltipProvider>
      <RefreshRateProvider defaultIntervalMs={defaultIntervalMs}>
        <SidebarProvider>
          <PageHeader status="running" autoLoad={autoLoad} onAutoLoadChange={vi.fn()} />
          {children}
        </SidebarProvider>
      </RefreshRateProvider>
    </TooltipProvider>,
  );
}

function selectOption(name: string) {
  const option = screen.getByRole("option", { name });

  fireEvent.pointerDown(option);
  fireEvent.pointerUp(option);
  fireEvent.click(option);
}

function runTrackedRefresh(outcome: "success" | "failure", id = "poll-1") {
  const options = trackBackgroundRefresh({});

  act(() => {
    options.onStart?.({ id } as never);

    if (outcome === "success") {
      options.onSuccess?.({ props: {} } as never);
    } else {
      options.onNetworkError?.(new Error("offline"));
    }

    options.onFinish?.({ id } as never);
  });
}

describe("PageHeader", () => {
  beforeEach(() => {
    window.localStorage.clear();
    resetAutoRefreshStatusForTests();
    Object.defineProperty(window, "matchMedia", {
      configurable: true,
      value: vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }),
    });
  });

  it("rotates the refresh icon until every active poll finishes", () => {
    renderHeader({ autoLoad: true });

    const refresh = screen.getByRole("button", { name: "Auto load new entries" });
    const icon = refresh.querySelector("svg");
    const first = trackBackgroundRefresh({});
    const second = trackBackgroundRefresh({});

    expect(icon).not.toHaveClass("animate-spin");

    act(() => {
      first.onStart?.({ id: "first-poll" } as never);
      second.onStart?.({ id: "second-poll" } as never);
    });

    expect(icon).toHaveClass("animate-spin");
    expect(refresh).toHaveAttribute("aria-busy", "true");
    expect(refresh).toHaveAttribute("data-refresh-status", "refreshing");

    act(() => {
      first.onSuccess?.({ props: {} } as never);
      first.onFinish?.({ id: "first-poll" } as never);
    });

    expect(icon).toHaveClass("animate-spin");

    act(() => {
      second.onSuccess?.({ props: {} } as never);
      second.onFinish?.({ id: "second-poll" } as never);
    });

    expect(icon).not.toHaveClass("animate-spin");
    expect(refresh).toHaveAttribute("aria-busy", "false");
    expect(refresh).toHaveAttribute("data-refresh-status", "idle");
  });

  it("shows a restrained failed state with an accessible name until the next success", async () => {
    renderHeader({ autoLoad: true });

    runTrackedRefresh("failure");

    const refresh = screen.getByRole("button", {
      name: "Auto refresh failed; retrying automatically",
    });
    const icon = refresh.querySelector("svg");

    expect(refresh).toHaveAttribute("data-refresh-status", "failed");
    expect(refresh).toHaveAttribute(
      "aria-description",
      "The last automatic refresh failed. Horizon will keep retrying at the normal interval.",
    );
    expect(icon).toHaveClass("text-destructive");
    expect(icon).not.toHaveClass("animate-spin");

    fireEvent.focus(refresh);
    fireEvent.pointerMove(refresh);

    await waitFor(() => {
      expect(
        screen.getByText(
          "The last automatic refresh failed. Horizon will keep retrying at the normal interval.",
        ),
      ).toBeVisible();
    });

    runTrackedRefresh("success", "poll-2");

    const recovered = screen.getByRole("button", { name: "Auto load new entries" });

    expect(recovered).toHaveAttribute("data-refresh-status", "idle");
    expect(recovered.querySelector("svg")).not.toHaveClass("text-destructive");
    expect(recovered).not.toHaveAttribute("aria-description");
  });

  it("does not indicate background refresh requests while automatic refresh is disabled", () => {
    renderHeader();

    const refresh = screen.getByRole("button", { name: "Auto load new entries" });
    const icon = refresh.querySelector("svg");

    runTrackedRefresh("failure");

    expect(icon).not.toHaveClass("animate-spin");
    expect(icon).not.toHaveClass("text-destructive");
    expect(icon).toHaveStyle({ rotate: "0turn" });
    expect(refresh).toHaveAttribute("aria-busy", "false");
    expect(refresh).toHaveAttribute("data-refresh-status", "idle");
    expect(refresh).toHaveAccessibleName("Auto load new entries");
  });

  it("defaults the refresh-rate selector to the server poll interval", () => {
    renderHeader({ defaultIntervalMs: 5000 });

    expect(screen.getByRole("combobox", { name: "Refresh rate" })).toHaveTextContent("5s");
  });

  it("persists a selected refresh rate for every page to reuse", () => {
    renderHeader({ defaultIntervalMs: 5000 });

    fireEvent.click(screen.getByRole("combobox", { name: "Refresh rate" }));
    selectOption("1s");

    expect(window.localStorage.getItem("horizonRefreshRateMs")).toBe("1000");
  });

  it("lets the refresh rate be turned off", () => {
    renderHeader({ defaultIntervalMs: 5000 });

    fireEvent.click(screen.getByRole("combobox", { name: "Refresh rate" }));
    selectOption("Off");

    expect(window.localStorage.getItem("horizonRefreshRateMs")).toBe("0");
    expect(screen.getByRole("combobox", { name: "Refresh rate" })).toHaveTextContent("Off");
  });
});
