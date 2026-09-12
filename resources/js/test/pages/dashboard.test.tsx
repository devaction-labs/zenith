import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vite-plus/test";

import Dashboard from "@/pages/dashboard";
import { TooltipProvider } from "@/components/ui/tooltip";
import type { DashboardPageProps, DashboardSummary } from "@/types/dashboard";

vi.mock("@inertiajs/react", () => ({
  Head: () => null,
  Link: ({ children, href }: { children: ReactNode; href: string }) => (
    <a href={href}>{children}</a>
  ),
  router: { reload: vi.fn() },
  usePoll: vi.fn(() => ({ start: vi.fn(), stop: vi.fn() })),
}));

const summary: DashboardSummary = {
  available: true,
  status: "running",
  failedJobs: 1,
  completedJobs: 11,
  pendingJobs: 0,
  pendingReserved: 0,
  pendingReadyNow: 0,
  pendingDelayed: 0,
  failedJobsPastHour: 1,
  failedJobsPastDay: 1,
  recentlyFailedJobs: 1,
  recentlyFailedPeriodMinutes: 60,
  jobsPerMinute: 0.18,
  recentJobs: 11,
  recentJobsPeriodMinutes: 60,
  processedSinceSnapshot: 11,
  silencedJobs: 0,
  completedRetentionMinutes: 60,
  batchesAvailable: true,
  activeBatches: 0,
  batchPreviews: [],
  processes: 1,
  waits: { "redis:default": 1 },
  maxWaitQueue: "redis:default",
  maxWaitSeconds: 1,
  queueWithMaxRuntime: "default",
  queueWithMaxThroughput: "default",
  message: null,
};

const props: DashboardPageProps = {
  horizon: { baseUrl: "/horizon", pollInterval: 0, status: "running" },
  summary,
  workload: {
    available: true,
    items: [
      {
        name: "default",
        connection: "redis",
        length: 2,
        wait: 1,
        processes: 1,
        processesShared: false,
        throughput: null,
        paused: false,
        pausedUntil: null,
        splitQueues: null,
      },
    ],
    message: null,
  },
  liveThroughput: {
    available: false,
    series: [],
    message: "Enable the telemetry recorder (zenith.telemetry.enabled) to see live throughput.",
  },
  liveMetricsGroupBy: "state",
  liveMetricsWindow: "1h",
};

describe("Dashboard page", () => {
  it("renders the complete server response without a client-side cache or deferred fallback", () => {
    render(
      <TooltipProvider>
        <Dashboard {...props} />
      </TooltipProvider>,
    );

    expect(screen.getByText("Pending Jobs")).toBeVisible();
    expect(screen.getAllByText("default").length).toBeGreaterThan(0);
    expect(screen.queryByText("Instances")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Loading workload")).not.toBeInTheDocument();
  });

  it("shows a fallback alert when the telemetry recorder is disabled", () => {
    render(
      <TooltipProvider>
        <Dashboard {...props} />
      </TooltipProvider>,
    );

    expect(screen.getByText("Live metrics unavailable")).toBeVisible();
    expect(
      screen.getByText(
        "Enable the telemetry recorder (zenith.telemetry.enabled) to see live throughput.",
      ),
    ).toBeVisible();
  });

  it("renders the live throughput chart once the recorder is enabled", () => {
    render(
      <TooltipProvider>
        <Dashboard
          {...props}
          liveThroughput={{
            available: true,
            series: [
              {
                label: "Processed",
                points: [{ timestamp: 1_784_387_100, count: 4 }],
              },
            ],
            message: null,
          }}
        />
      </TooltipProvider>,
    );

    expect(screen.queryByText("Live metrics unavailable")).not.toBeInTheDocument();
    expect(screen.getByRole("img", { name: "Live throughput chart" })).toBeVisible();
  });
});
